<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\neo_migrate\LegacyCatalog;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps each stack to its own theme while both are installed.
 *
 * Between content conversion and teardown a node carries both the legacy body
 * (paragraphs) and the component tree. The legacy themes render the body and
 * never the tree; the Neo front theme renders the tree and never the body.
 * Render caching already varies by theme, so each theme caches its own build.
 *
 * Legacy theme-layer modules (the catalog's `theme_layer`) restyle common
 * theme hooks in every theme's registry. The Neo themes' registries are
 * rebuilt without them.
 */
final class CoexistenceHooks {

  /**
   * Each theme hook's template directory before any module altered it.
   *
   * @var array<string, string|null>
   */
  private array $unalteredPaths = [];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ThemeManagerInterface $themeManager,
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly ModuleHandlerInterface $moduleHandler,
    #[Autowire(service: 'extension.list.module')]
    private readonly ModuleExtensionList $moduleList,
    #[Autowire(service: 'neo_migrate.catalog')]
    private readonly LegacyCatalog $catalog,
  ) {}

  /**
   * Implements hook_entity_view_alter().
   */
  #[Hook('entity_view_alter')]
  public function entityViewAlter(array &$build, EntityInterface $entity, EntityViewDisplayInterface $display): void {
    $settings = $this->configFactory->get('neo_migrate.settings');
    $tree = (string) $settings->get('coexistence.tree_field');
    $legacy = (string) $settings->get('coexistence.legacy_field');
    if ($tree === '' || $legacy === '' || !isset($build[$tree], $build[$legacy])) {
      return;
    }
    $theme = $this->themeManager->getActiveTheme()->getName();
    if ($theme === $settings->get('preview.legacy.front')) {
      unset($build[$tree]);
    }
    elseif ($theme === $settings->get('preview.neo.front')) {
      unset($build[$legacy]);
    }
  }

  /**
   * Records each theme hook's template directory before modules alter it.
   *
   * Implements hook_theme_registry_alter(), first.
   */
  #[Hook('theme_registry_alter', order: Order::First)]
  public function recordRegistry(array &$registry): void {
    $this->unalteredPaths = array_map(static fn (array $info): ?string => $info['path'] ?? NULL, $registry);
  }

  /**
   * Takes the legacy theme layer back out of a Neo theme's registry.
   *
   * Implements hook_theme_registry_alter(), last. ux_form, for one, points
   * core's fieldset template at its own for every theme and preprocesses every
   * fieldset and container, so Neo's forms rendered its legacy markup. For the
   * Neo themes, a template a theme-layer module moved into its own directory
   * goes back where the theme had it, and the module's preprocess functions
   * leave every hook it does not provide itself. The legacy themes keep both.
   *
   * Which theme a registry belongs to is read from the registry, not from the
   * active theme: a request can build one theme's registry while another is
   * active, and stripping the legacy theme's registry breaks the public site.
   */
  #[Hook('theme_registry_alter', order: Order::Last)]
  public function isolateRegistry(array &$registry): void {
    if (!$this->isNeoRegistry($registry)) {
      return;
    }
    $modules = array_values(array_filter($this->catalog->get('theme_layer'), fn (string $module): bool => $this->moduleHandler->moduleExists($module)));
    if (!$modules) {
      return;
    }
    $paths = array_map(fn (string $module): string => $this->moduleList->getPath($module) . '/', $modules);
    $owned = static function (?string $path) use ($paths): bool {
      foreach ($paths as $prefix) {
        if ($path !== NULL && str_starts_with($path . '/', $prefix)) {
          return TRUE;
        }
      }
      return FALSE;
    };
    foreach ($registry as $hook => &$info) {
      $unaltered = $this->unalteredPaths[$hook] ?? NULL;
      if ($unaltered !== NULL && ($info['path'] ?? NULL) !== $unaltered && $owned($info['path'] ?? NULL)) {
        $info['path'] = $unaltered;
      }
      if (!empty($info['preprocess functions']) && !$owned($info['path'] ?? NULL)) {
        $info['preprocess functions'] = array_values(array_filter(
          $info['preprocess functions'],
          static function (string $function) use ($modules): bool {
            foreach ($modules as $module) {
              if (str_starts_with($function, $module . '_preprocess_')) {
                return FALSE;
              }
            }
            return TRUE;
          },
        ));
      }
    }
    unset($info);
  }

  /**
   * Whether a registry is a Neo theme's.
   *
   * A registry holds the hooks its theme and base themes provide, each marked
   * with that theme's path, so a Neo theme's registry has entries from the Neo
   * themes (or their base themes, neo_base among them) and a legacy theme's has
   * none.
   */
  private function isNeoRegistry(array $registry): bool {
    $settings = $this->configFactory->get('neo_migrate.settings');
    $paths = [];
    foreach (array_filter([$settings->get('preview.neo.front'), $settings->get('preview.neo.admin')]) as $name) {
      if (!$this->themeHandler->themeExists($name)) {
        continue;
      }
      $theme = $this->themeHandler->getTheme($name);
      $paths[$theme->getPath()] = TRUE;
      foreach (array_keys($theme->base_themes ?? []) as $base) {
        if ($this->themeHandler->themeExists($base)) {
          $paths[$this->themeHandler->getTheme($base)->getPath()] = TRUE;
        }
      }
    }
    foreach ($registry as $info) {
      if (isset($info['theme path'], $paths[$info['theme path']])) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
