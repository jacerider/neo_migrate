<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Serialization\Yaml;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;
use Drupal\neo_migrate\LegacyCatalog;
use Drupal\neo_migrate\Theme\ThemeFamily;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps each stack to its own theme while both are installed.
 *
 * Between content conversion and teardown a node carries both the legacy body
 * (paragraphs, or an exo_alchemist Layout Builder layout) and the component
 * tree. The legacy themes render the body and
 * never the tree; the Neo front theme renders the tree and never the body.
 * Render caching already varies by theme, so each theme caches its own build.
 *
 * Legacy theme-layer modules (the catalog's `theme_layer`) restyle common
 * theme hooks in every theme's registry. The Neo themes' registries are
 * rebuilt without them.
 *
 * The reverse holds too: some Neo modules act on every theme. neo_tooltip turns
 * form descriptions into tooltips, and each icon system attaches its global
 * icon fonts to every page, where the two share class names (IcoMoon's
 * `icon-<package>-<name>`) and restyle each other's icons. Legacy themes are
 * kept free of both, and the Neo themes of exo_icon's fonts. exo_modal and
 * neo_modal both take over core's dialog libraries; each theme gets its own.
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
    #[Autowire(param: 'app.root')]
    private readonly string $appRoot,
    #[Autowire(service: 'current_route_match')]
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Implements hook_entity_view_alter().
   */
  #[Hook('entity_view_alter')]
  public function entityViewAlter(array &$build, EntityInterface $entity, EntityViewDisplayInterface $display): void {
    $settings = $this->configFactory->get('neo_migrate.settings');
    $tree = (string) $settings->get('coexistence.tree_field');
    $legacy = (string) $settings->get('coexistence.legacy_field');
    if ($tree === '' || $legacy === '') {
      return;
    }
    $theme = $this->themeManager->getActiveTheme()->getName();
    // A Layout Builder host (exo_alchemist) renders its layout, not its fields:
    // the tree is not in the build at all. The Neo front theme gets the tree
    // in the layout's place; the legacy theme keeps the layout.
    if (isset($build['_layout_builder']) && $entity instanceof FieldableEntityInterface && $entity->hasField($tree)) {
      if ($theme === $settings->get('preview.neo.front')) {
        $build[$tree] = $entity->get($tree)->view(['type' => 'neo_component_tree', 'label' => 'hidden']);
        $build[$tree]['#weight'] = $build['_layout_builder']['#weight'] ?? 0;
        unset($build['_layout_builder']);
      }
      return;
    }
    if (!isset($build[$tree], $build[$legacy])) {
      return;
    }
    if ($theme === $settings->get('preview.legacy.front')) {
      unset($build[$tree]);
    }
    elseif ($theme === $settings->get('preview.neo.front')) {
      unset($build[$legacy]);
    }
  }

  /**
   * Lets a previewed Layout Builder page run full width, as Alchemist's do.
   *
   * Implements hook_preprocess_region__content(). neo_front boxes the content
   * region in a container unless the page's display renders a component tree;
   * a Layout Builder host's display renders its layout until cutover, even
   * where entityViewAlter() swaps the tree in.
   */
  #[Hook('preprocess_region__content', order: Order::Last)]
  public function preprocessRegionContent(array &$variables): void {
    if ($this->previewsLayoutHost()) {
      $variables['alchemist'] = TRUE;
    }
  }

  /**
   * Keeps a previewed Layout Builder page's title for screen readers only.
   *
   * Implements hook_preprocess_block(), as neo_alchemist does for its own
   * pages: the components carry the visible title.
   */
  #[Hook('preprocess_block', order: Order::Last)]
  public function preprocessBlock(array &$variables): void {
    if (($variables['base_plugin_id'] ?? NULL) === 'page_title_block' && $this->previewsLayoutHost()) {
      $variables['attributes']['class'][] = 'sr-only';
    }
  }

  /**
   * Whether this page shows a Layout Builder host's tree in its layout's place.
   */
  private function previewsLayoutHost(): bool {
    $settings = $this->configFactory->get('neo_migrate.settings');
    $tree = (string) $settings->get('coexistence.tree_field');
    if ($tree === '' || $this->themeManager->getActiveTheme()->getName() !== $settings->get('preview.neo.front')) {
      return FALSE;
    }
    $route = (string) $this->routeMatch->getRouteName();
    if (!preg_match('/^entity\.([a-z_]+)\.canonical$/', $route, $matches)) {
      return FALSE;
    }
    $entity = $this->routeMatch->getParameter($matches[1]);
    if (!$entity instanceof FieldableEntityInterface || !$entity->hasField($tree)) {
      return FALSE;
    }
    $display = \Drupal::service('entity_display.repository')->getViewDisplay($entity->getEntityTypeId(), $entity->bundle());
    return $display instanceof LayoutEntityDisplayInterface && $display->isLayoutBuilderEnabled();
  }

  /**
   * Keeps each theme's elements to its own stack.
   *
   * Implements hook_element_info_alter(). Element info is built per theme, with
   * that theme active. In a legacy theme every input opts out of neo_tooltip
   * (`#tooltip: FALSE`, set by a process callback that runs before
   * neo_tooltip's), so descriptions stay where the legacy theme put them. In a
   * Neo theme the page no longer carries exo_icon's global fonts, and in the
   * Neo front theme it is no longer wrapped in exo's page canvas (#exo-body),
   * which pads itself by a displacement exo keeps in localStorage. The back
   * theme keeps the canvas: exo's admin scripts (the list builder) expect it.
   */
  #[Hook('element_info_alter')]
  public function elementInfoAlter(array &$info): void {
    if (ThemeFamily::isNeo($this->themeManager->getActiveTheme())) {
      if (isset($info['html']['#attached']['library'])) {
        $info['html']['#attached']['library'] = array_values(array_filter(
          $info['html']['#attached']['library'],
          static fn (string $library): bool => !str_starts_with($library, 'exo_icon/icon.'),
        ));
      }
      if (isset($info['page']) && ThemeFamily::isNeoFront($this->themeManager->getActiveTheme())) {
        unset($info['page']['#theme_wrappers']['exo']);
        $info['page']['#pre_render'] = array_values(array_filter(
          $info['page']['#pre_render'] ?? [],
          static fn ($callback): bool => !(is_array($callback) && ($callback[0] ?? NULL) === 'Drupal\exo\ExoPageHandler'),
        ));
      }
      return;
    }
    if (!$this->moduleHandler->moduleExists('neo_tooltip')) {
      return;
    }
    foreach ($info as &$element) {
      if (isset($element['#process']) && !empty($element['#input'])) {
        array_unshift($element['#process'], [self::class, 'withoutTooltip']);
      }
    }
  }

  /**
   * Opts an element out of neo_tooltip.
   */
  public static function withoutTooltip(array $element): array {
    $element['#tooltip'] = FALSE;
    return $element;
  }

  /**
   * Gives each theme its own dialog framework.
   *
   * Implements hook_library_info_alter(), last. exo_modal and neo_modal both
   * replace core/drupal.dialog and core/drupal.dialog.ajax. neo_modal runs
   * later and swaps the scripts but keeps exo_modal's dependencies, so every
   * page loaded both frameworks: in the back theme exo_modal still opened core
   * dialogs, and webform's dialog listeners, handed exo's events through
   * neo_modal's bridge without their arguments, threw. Library definitions are
   * built per theme, with it active: a Neo theme gets neo_modal alone; a
   * legacy theme gets core's definitions as exo_modal altered them, as before
   * neo_modal was installed.
   */
  #[Hook('library_info_alter', order: Order::Last)]
  public function libraryInfoAlter(array &$libraries, string $extension): void {
    if ($extension !== 'core' || !isset($libraries['drupal.dialog'], $libraries['drupal.dialog.ajax'])
      || !$this->moduleHandler->moduleExists('exo_modal') || !$this->moduleHandler->moduleExists('neo_modal')) {
      return;
    }
    if (ThemeFamily::isNeo($this->themeManager->getActiveTheme())) {
      // exo's own scripts on admin pages (exo.js, the list builder) call
      // jQuery.once without declaring it; exo_modal's dialog libraries used to
      // bring it along. Keep it, without exo_modal.
      $libraries['drupal.dialog']['dependencies'] = array_values(array_filter(
        $libraries['drupal.dialog']['dependencies'] ?? [],
        static fn (string $library): bool => !str_starts_with($library, 'exo_modal/'),
      ));
      $libraries['drupal.dialog']['dependencies'][] = 'exo/jquery.once';
      return;
    }
    $this->moduleHandler->loadInclude('exo_modal', 'module');
    if (!function_exists('exo_modal_library_info_alter')) {
      return;
    }
    $core = Yaml::decode((string) file_get_contents($this->appRoot . '/core/core.libraries.yml'));
    $legacy = ['drupal.dialog' => $core['drupal.dialog'], 'drupal.dialog.ajax' => $core['drupal.dialog.ajax']];
    exo_modal_library_info_alter($legacy, 'core');
    $libraries['drupal.dialog'] = $legacy['drupal.dialog'];
    $libraries['drupal.dialog.ajax'] = $legacy['drupal.dialog.ajax'];
  }

  /**
   * Hides a Neo theme's dialog commands from exo_modal.
   *
   * Implements hook_ajax_render_alter(), first. exo_modal rewrites every
   * `openDialog` into its own modal commands, in every theme; a Neo theme,
   * which no longer loads exo_modal's scripts, then opens nothing. The
   * commands are renamed before exo_modal sees them and restored after
   * (restoreDialogCommands()). The legacy themes keep exo_modal's dialogs.
   */
  #[Hook('ajax_render_alter', order: Order::First)]
  public function hideDialogCommands(array &$data): void {
    if (!$this->moduleHandler->moduleExists('exo_modal') || !ThemeFamily::isNeo($this->themeManager->getActiveTheme())) {
      return;
    }
    foreach ($data as &$command) {
      if (in_array($command['command'] ?? NULL, ['openDialog', 'webformCloseDialog'], TRUE)) {
        $command['command'] = 'neo_migrate:' . $command['command'];
      }
    }
  }

  /**
   * Restores the dialog commands hideDialogCommands() renamed.
   *
   * Implements hook_ajax_render_alter(), last.
   */
  #[Hook('ajax_render_alter', order: Order::Last)]
  public function restoreDialogCommands(array &$data): void {
    foreach ($data as &$command) {
      if (str_starts_with((string) ($command['command'] ?? ''), 'neo_migrate:')) {
        $command['command'] = substr($command['command'], strlen('neo_migrate:'));
      }
    }
  }

  /**
   * Keeps neo_icon's global icon fonts off legacy pages.
   *
   * Implements hook_page_attachments_alter(). Legacy pages render exo_icon's
   * (or micon's) icons, whose classes the Neo fonts would restyle; any Neo icon
   * on a legacy page (an editor's local tasks) uses the same names, which the
   * legacy fonts still draw.
   */
  #[Hook('page_attachments_alter')]
  public function pageAttachmentsAlter(array &$attachments): void {
    if (empty($attachments['#attached']['library']) || ThemeFamily::isNeo($this->themeManager->getActiveTheme())) {
      return;
    }
    $attachments['#attached']['library'] = array_values(array_filter(
      $attachments['#attached']['library'],
      static fn (string $library): bool => !str_starts_with($library, 'neo_icon/library.'),
    ));
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
