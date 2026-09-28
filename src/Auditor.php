<?php

declare(strict_types=1);

namespace Drupal\neo_migrate;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Serialization\Yaml;
use Drupal\neo_migrate\Source\ExoAlchemistSource;
use Drupal\neo_migrate\Source\ParagraphsSource;

/**
 * Finds everything legacy on a site and says how each piece will be handled.
 *
 * Read-only. The report is the first thing a person reviews: it is where the
 * size of the job, the per-site decisions and the surprises (dangling icons,
 * orphan paragraph bundles, config that uninstalling would delete) show up
 * before any work is done. Paragraphs and exo_alchemist sites are both read;
 * each section is empty when its system is not installed.
 */
final class Auditor {

  /**
   * Directories never searched when scanning site code.
   */
  private const SKIP_DIRS = ['node_modules', 'vendor', 'dist', 'assets', '.git', 'bower_components'];

  /**
   * File extensions searched when scanning site code.
   */
  private const CODE_EXTENSIONS = ['twig', 'php', 'module', 'theme', 'inc', 'install', 'js', 'scss', 'yml'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ModuleExtensionList $moduleList,
    private readonly ThemeExtensionList $themeList,
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ConfigManagerInterface $configManager,
    private readonly Connection $database,
    private readonly LegacyCatalog $catalog,
    private readonly ParagraphsSource $paragraphs,
    private readonly ExoAlchemistSource $exo,
    private readonly Inventory $inventory,
    private readonly string $appRoot,
  ) {}

  /**
   * Runs every check.
   */
  public function run(): array {
    $inventory = $this->inventory->build();
    $selectors = $this->iconSelectors();
    $report = [
      'generated' => date('c'),
      'site' => $this->site(),
      'extensions' => $this->extensions(),
      'hosts' => $inventory['hosts'],
      'paragraphs' => $this->paragraphTypes($inventory),
      'components' => $this->componentTypes($inventory),
      'icons' => $this->icons($selectors, $inventory),
      'exo_icons' => $this->exoIcons($inventory),
      'favicon' => $this->favicon(),
      'toolbar' => $this->toolbar(),
      'exo_toolbar' => $this->exoToolbar(),
      'site_settings' => $this->siteSettings(),
      'exo_site_settings' => $this->exoSiteSettings(),
      'metatags' => $this->metatags(),
      'theme' => $this->theme(),
      'code' => $this->code(),
      'removal' => $this->removal(),
    ];
    $report['summary'] = $this->summary($report, $inventory);
    return $report;
  }

  /**
   * Core version and the theme chains in use.
   */
  private function site(): array {
    $system = $this->configFactory->get('system.theme');
    $chain = function (?string $theme): array {
      if (!$theme || !$this->themeHandler->themeExists($theme)) {
        return [];
      }
      // base_themes lists the oldest ancestor first; the chain reads upwards.
      return [$theme, ...array_reverse(array_keys($this->themeHandler->getTheme($theme)->base_themes ?? []))];
    };
    return [
      'drupal' => \Drupal::VERSION,
      'php' => PHP_VERSION,
      'profile' => $this->configFactory->get('core.extension')->get('profile'),
      'default_theme' => $chain($system->get('default')),
      'admin_theme' => $chain($system->get('admin')),
    ];
  }

  /**
   * Legacy modules and themes found, plus anything left unclassified.
   */
  private function extensions(): array {
    $found = [];
    foreach ($this->catalog->get('modules') as $name => $entry) {
      $status = $this->moduleHandler->moduleExists($name) ? 'enabled' : ($this->moduleList->exists($name) ? 'present' : NULL);
      if ($status) {
        $found[] = ['type' => 'module', 'name' => $name, 'status' => $status] + $entry;
      }
    }
    $installedThemes = $this->themeHandler->listInfo();
    foreach ($this->catalog->get('themes') as $name => $entry) {
      $status = isset($installedThemes[$name]) ? 'enabled' : ($this->themeList->exists($name) ? 'present' : NULL);
      if ($status) {
        $found[] = ['type' => 'theme', 'name' => $name, 'status' => $status] + $entry;
      }
    }
    foreach ($installedThemes as $name => $theme) {
      if ($this->catalog->theme($name)) {
        continue;
      }
      $bases = array_keys($theme->base_themes ?? []);
      $legacyBase = array_values(array_filter($bases, fn ($base) => (bool) $this->catalog->theme($base)));
      if ($legacyBase) {
        $found[] = [
          'type' => 'theme',
          'name' => $name,
          'status' => 'enabled',
          'replacement' => 'front (neo_front)',
          'handling' => 'judgment',
          'phase' => 3,
          'note' => sprintf('Sub-theme of %s; rebuilt as the neo front theme.', implode(', ', $legacyBase)),
        ];
      }
    }

    $unclassified = [];
    $keep = array_flip($this->catalog->get('keep'));
    foreach ($this->moduleHandler->getModuleList() as $name => $extension) {
      $path = $extension->getPath();
      if (str_starts_with($path, 'core/') || str_starts_with($name, 'neo') || isset($keep[$name]) || $this->catalog->module($name)) {
        continue;
      }
      $unclassified[] = ['name' => $name, 'path' => $path];
    }
    return ['legacy' => $found, 'unclassified' => $unclassified];
  }

  /**
   * Paragraph types, their fields, nesting, hosts and use.
   */
  private function paragraphTypes(array $inventory): array {
    if (!$this->paragraphs->applies()) {
      return [];
    }
    $counts = $inventory['counts'];
    $inDatabase = $this->database->select('paragraphs_item', 'p')
      ->fields('p', ['type'])
      ->groupBy('p.type')
      ->orderBy('p.type');
    $inDatabase->addExpression('COUNT(*)', 'total');
    $inDatabase = $inDatabase->execute()->fetchAllKeyed();

    $types = [];
    foreach ($this->entityTypeManager->getStorage('paragraphs_type')->loadMultiple() as $id => $type) {
      $fields = [];
      foreach ($this->entityFieldManager->getFieldDefinitions('paragraph', $id) as $name => $definition) {
        if ($definition->getFieldStorageDefinition()->isBaseField()) {
          continue;
        }
        $field = [
          'type' => $definition->getType(),
          'label' => (string) $definition->getLabel(),
          'cardinality' => $definition->getFieldStorageDefinition()->getCardinality(),
          'required' => $definition->isRequired(),
        ];
        if ($definition->getType() === 'entity_reference_revisions') {
          $field['targets'] = array_keys($definition->getSetting('handler_settings')['target_bundles'] ?? []);
        }
        $fields[$name] = $field;
      }
      ksort($fields);
      $live = $counts[$id]['live'] ?? 0;
      $types[$id] = [
        'label' => (string) $type->label(),
        'fields' => $fields,
        'live' => $live,
        'published' => $counts[$id]['published'] ?? 0,
        'hosts' => $counts[$id]['hosts'] ?? 0,
        'host_bundles' => $counts[$id]['host_bundles'] ?? [],
        'in_database' => (int) ($inDatabase[$id] ?? 0),
        'handling' => $live ? 'judgment' : 'skip',
      ];
    }
    ksort($types);

    $hosts = [];
    foreach ($this->paragraphs->paragraphFields() as $entityTypeId => $fields) {
      foreach ($fields as $fieldName => $bundles) {
        foreach ($bundles as $bundle) {
          $definition = $this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle)[$fieldName] ?? NULL;
          $settings = $definition?->getSetting('handler_settings') ?? [];
          $allowed = array_keys($settings['target_bundles'] ?? []);
          if (!empty($settings['negate'])) {
            $allowed = array_values(array_diff(array_keys($types), $allowed));
          }
          $hosts[] = [
            'entity_type' => $entityTypeId,
            'bundle' => $bundle,
            'field' => $fieldName,
            'nested' => $entityTypeId === 'paragraph',
            'allowed' => $allowed ?: ['*'],
            'widget' => $this->widget($entityTypeId, $bundle, $fieldName),
          ];
        }
      }
    }

    return [
      'types' => $types,
      'hosts' => $hosts,
      'orphans' => array_map('intval', array_diff_key($inDatabase, $types)),
      'total_in_database' => array_sum(array_map('intval', $inDatabase)),
      'total_live' => array_sum(array_column($counts, 'live')),
    ];
  }

  /**
   * The form widget a field uses in its default form display.
   */
  private function widget(string $entityTypeId, string $bundle, string $field): ?string {
    $display = $this->configFactory->get("core.entity_form_display.$entityTypeId.$bundle.default")->get("content.$field.type");
    return $display ?: NULL;
  }

  /**
   * exo_alchemist components: their fields, nesting, hosts and use.
   *
   * Counted from the inventory, so `live` is what current revisions render:
   * top-level items and those nested in a sequence, the modifier values they
   * set and the fields editors hid. `in_database` includes blocks only old
   * revisions reference; all of them go before exo_alchemist is uninstalled.
   */
  private function componentTypes(array $inventory): array {
    if (!$this->exo->applies()) {
      return [];
    }
    $counts = $inventory['counts'];
    $query = $this->database->select('block_content_field_data', 'b')->fields('b', ['type'])->groupBy('b.type');
    $query->addExpression('COUNT(*)', 'total');
    $inDatabase = array_map('intval', $query->execute()->fetchAllKeyed());

    $use = [];
    $walk = function (array $items, bool $nested) use (&$walk, &$use): void {
      foreach ($items as $item) {
        if (!empty($item['missing'])) {
          continue;
        }
        $bundle = $item['bundle'];
        $use[$bundle] ??= ['top' => 0, 'nested' => 0, 'modifiers' => [], 'hidden' => [], 'default' => 0];
        $use[$bundle][$nested ? 'nested' : 'top']++;
        $use[$bundle]['default'] += empty($item['placement']['default']) ? 0 : 1;
        $flatten = function (array $values, string $prefix) use (&$flatten, &$use, $bundle): void {
          foreach ($values as $key => $value) {
            if (is_array($value)) {
              $flatten($value, "$prefix$key.");
            }
            elseif ($value !== '' && $value !== NULL) {
              $use[$bundle]['modifiers']["$prefix$key"][(string) $value] = ($use[$bundle]['modifiers']["$prefix$key"][(string) $value] ?? 0) + 1;
            }
          }
        };
        $flatten($item['behavior']['modifiers'] ?? [], '');
        foreach ($item['fields'] as $name => $field) {
          if (!empty($field['hidden'])) {
            $use[$bundle]['hidden'][$name] = ($use[$bundle]['hidden'][$name] ?? 0) + 1;
          }
          if (isset($field['children'])) {
            $walk($field['children'], TRUE);
          }
        }
      }
    };
    foreach ($inventory['entities'] as $entity) {
      foreach ($entity['trees'] as $tree) {
        $walk($tree, FALSE);
      }
    }

    $types = [];
    $orphans = [];
    foreach ($this->exo->definitions() as $bundle => $definition) {
      $id = $definition['id'];
      if ($id === NULL) {
        $orphans[$bundle] = $inDatabase[$bundle] ?? 0;
        continue;
      }
      $live = $counts[$id]['live'] ?? 0;
      $types[$id] = [
        'bundle' => $bundle,
        'label' => $definition['label'],
        'provider' => $definition['provider'],
        'fields' => $definition['fields'],
        'modifier_groups' => $definition['modifiers'],
        'live' => $live,
        'published' => $counts[$id]['published'] ?? 0,
        'top' => $use[$id]['top'] ?? 0,
        'nested' => $use[$id]['nested'] ?? 0,
        'on_default_layout' => $use[$id]['default'] ?? 0,
        'hosts' => $counts[$id]['hosts'] ?? 0,
        'host_bundles' => $counts[$id]['host_bundles'] ?? [],
        'modifiers' => $use[$id]['modifiers'] ?? [],
        'hidden' => $use[$id]['hidden'] ?? [],
        'in_database' => $inDatabase[$bundle] ?? 0,
        'handling' => $live ? 'judgment' : 'skip',
      ];
    }
    ksort($types);

    $hosts = [];
    foreach ($inventory['hosts'] as $host) {
      if (($host['source'] ?? NULL) !== $this->exo->id()) {
        continue;
      }
      foreach ($host['bundles'] as $bundle) {
        $entities = array_filter($inventory['entities'], static fn ($e) => $e['entity_type'] === $host['entity_type'] && $e['bundle'] === $bundle);
        $default = array_filter($entities, static function ($e) use ($host) {
          $tree = $e['trees'][$host['field']] ?? [];
          return $tree && !empty($tree[0]['placement']['default']);
        });
        $hosts[] = [
          'entity_type' => $host['entity_type'],
          'bundle' => $bundle,
          'field' => $host['field'],
          'entities' => count($entities),
          'default_layout' => count($default),
        ];
      }
    }

    return [
      'types' => $types,
      'hosts' => $hosts,
      'orphans' => $orphans,
      'total_in_database' => array_sum(array_intersect_key($inDatabase, $this->exo->definitions())),
      'total_live' => array_sum(array_column($types, 'live')),
    ];
  }

  /**
   * Every icon name the legacy icon system knows.
   *
   * @return array<string, true>
   */
  private function iconSelectors(): array {
    if (!$this->moduleHandler->moduleExists('micon')) {
      return [];
    }
    /** @var \Drupal\micon\MiconIconManager $manager */
    $manager = \Drupal::service('micon.icon.manager');
    return array_fill_keys(array_keys($manager->getFlattenedIcons()), TRUE);
  }

  /**
   * Icon names stored in live content, with how often each appears.
   *
   * Counts every icon-typed value on a host entity or anywhere in its current
   * tree, so an unknown icon can be told apart from one that only lingers in
   * unused rows.
   *
   * @return array<string, int>
   */
  private function liveIcons(array $inventory): array {
    $counts = [];
    $collect = function (array $fields) use (&$counts): void {
      foreach ($fields as $field) {
        if (($field['type'] ?? NULL) === 'string_micon') {
          foreach ($field['items'] as $item) {
            if (!empty($item['value'])) {
              $counts[$item['value']] = ($counts[$item['value']] ?? 0) + 1;
            }
          }
        }
      }
    };
    $walk = function (array $items) use (&$walk, $collect): void {
      foreach ($items as $item) {
        $collect($item['fields'] ?? []);
        foreach ($item['fields'] ?? [] as $field) {
          if (isset($field['children'])) {
            $walk($field['children']);
          }
        }
      }
    };
    foreach ($inventory['entities'] as $entity) {
      $collect($entity['fields']);
      foreach ($entity['trees'] as $tree) {
        $walk($tree);
      }
    }
    arsort($counts);
    return $counts;
  }

  /**
   * Icon packages and every place an icon name is stored.
   */
  private function icons(array $selectors, array $inventory): array {
    if (!$this->moduleHandler->moduleExists('micon')) {
      return [];
    }
    $packages = [];
    foreach ($this->entityTypeManager->getStorage('micon')->loadMultiple() as $id => $package) {
      /** @var \Drupal\micon\Entity\Micon $package */
      $packages[$id] = [
        'label' => (string) $package->label(),
        'status' => $package->status(),
        'type' => $package->type(),
        'prefix' => $package->getPrefix(),
        'icons' => count($package->getIcons()),
      ];
    }

    $dangling = function (array $values) use ($selectors): array {
      return array_values(array_filter(array_keys($values), fn ($value) => $value !== '' && !isset($selectors[$value])));
    };

    $fields = [];
    foreach (['string_micon', 'micon_rating'] as $fieldType) {
      foreach ($this->entityFieldManager->getFieldMapByFieldType($fieldType) as $entityTypeId => $map) {
        $storage = $this->entityTypeManager->getStorage($entityTypeId);
        $definitions = $this->entityFieldManager->getFieldStorageDefinitions($entityTypeId);
        foreach ($map as $fieldName => $info) {
          $entry = ['field' => "$entityTypeId.$fieldName", 'type' => $fieldType, 'bundles' => array_values($info['bundles'])];
          if ($fieldType === 'string_micon' && $storage instanceof SqlEntityStorageInterface && isset($definitions[$fieldName])) {
            $mapping = $storage->getTableMapping();
            $table = $mapping->getDedicatedDataTableName($definitions[$fieldName]);
            $column = $mapping->getFieldColumnName($definitions[$fieldName], 'value');
            $query = $this->database->select($table, 't')->fields('t', [$column])->groupBy("t.$column");
            $query->addExpression('COUNT(*)', 'total');
            $values = $query->execute()->fetchAllKeyed();
            arsort($values);
            $entry['values'] = array_map('intval', $values);
            $entry['dangling'] = $dangling($values);
          }
          $fields[] = $entry;
        }
      }
    }

    $menu = ['links' => 0, 'icons' => [], 'positions' => [], 'dangling' => []];
    if ($this->database->schema()->tableExists('menu_link_content_data')) {
      $rows = $this->database->select('menu_link_content_data', 'm')
        ->fields('m', ['id', 'link__options'])
        ->condition('link__options', '%data-icon%', 'LIKE')
        ->execute();
      foreach ($rows as $row) {
        $options = @unserialize((string) $row->link__options, ['allowed_classes' => FALSE]) ?: [];
        $attributes = $options['attributes'] ?? [];
        $menu['links']++;
        if (!empty($attributes['data-icon'])) {
          $menu['icons'][$attributes['data-icon']] = ($menu['icons'][$attributes['data-icon']] ?? 0) + 1;
        }
        if (!empty($attributes['data-icon-position'])) {
          $menu['positions'][$attributes['data-icon-position']] = ($menu['positions'][$attributes['data-icon-position']] ?? 0) + 1;
        }
      }
      $menu['dangling'] = $dangling($menu['icons']);
    }

    return [
      'packages' => $packages,
      'known_icons' => count($selectors),
      'live' => $this->liveIcons($inventory),
      'fields' => $fields,
      'menu_links' => $menu,
      'config' => $this->iconConfigReferences($selectors),
    ];
  }

  /**
   * Config that stores an icon name, found by value or by a micon setting.
   */
  private function iconConfigReferences(array $selectors): array {
    $found = [];
    $walk = function ($data, string $name, string $path) use (&$walk, &$found, $selectors): void {
      if (is_array($data)) {
        foreach ($data as $key => $value) {
          $walk($value, $name, $path === '' ? (string) $key : "$path.$key");
        }
        return;
      }
      $inMiconSettings = str_contains($path, 'third_party_settings.micon');
      if (is_string($data) && (isset($selectors[$data]) || ($inMiconSettings && $data !== ''))) {
        $found[] = ['config' => $name, 'key' => $path, 'value' => $data, 'known' => isset($selectors[$data])];
      }
    };
    foreach ($this->configFactory->listAll() as $name) {
      if (str_starts_with($name, 'micon.micon.')) {
        continue;
      }
      $walk($this->configFactory->get($name)->getRawData(), $name, '');
    }
    return $found;
  }

  /**
   * exo_icon packages and every place an exo icon id is stored.
   *
   * Ids are `<package>-<name>`. Component icon fields are counted from the
   * inventory (live values only); menu links carry them as data-icon
   * attributes (exo_link_menu), the same as micon did.
   */
  private function exoIcons(array $inventory): array {
    if (!$this->moduleHandler->moduleExists('exo_icon')) {
      return [];
    }
    /** @var \Drupal\exo_icon\ExoIconRepository $repository */
    $repository = \Drupal::service('exo_icon.repository');
    $known = [];
    foreach ($repository->getDefinitions() as $id => $definition) {
      $known[$definition['id'] ?? $id] = TRUE;
    }
    $packages = [];
    foreach ($this->entityTypeManager->getStorage('exo_icon_package')->loadMultiple() as $id => $package) {
      /** @var \Drupal\exo_icon\Entity\ExoIconPackageInterface $package */
      $packages[$id] = [
        'label' => (string) $package->label(),
        'status' => $package->status(),
        'type' => $package->getType(),
        'global' => $package->isGlobal(),
        'icons' => count($package->getDefinitions()),
      ];
    }

    $live = [];
    $collect = function (array $fields) use (&$collect, &$live): void {
      foreach ($fields as $field) {
        if (($field['component_type'] ?? NULL) === 'icon' || ($field['type'] ?? NULL) === 'icon') {
          foreach ($field['items'] ?? [] as $item) {
            if (!empty($item['value'])) {
              $live[$item['value']] = ($live[$item['value']] ?? 0) + 1;
            }
          }
        }
        foreach ($field['children'] ?? [] as $child) {
          $collect($child['fields'] ?? []);
        }
      }
    };
    foreach ($inventory['entities'] as $entity) {
      $collect($entity['fields']);
      foreach ($entity['trees'] as $tree) {
        foreach ($tree as $item) {
          $collect($item['fields'] ?? []);
        }
      }
    }
    arsort($live);

    $menu = [];
    if ($this->database->schema()->tableExists('menu_link_content_data')) {
      $rows = $this->database->select('menu_link_content_data', 'm')
        ->fields('m', ['id', 'menu_name', 'title', 'link__options'])
        ->condition('link__options', '%data-icon%', 'LIKE')
        ->execute();
      foreach ($rows as $row) {
        $attributes = (@unserialize((string) $row->link__options, ['allowed_classes' => FALSE]) ?: [])['attributes'] ?? [];
        $menu[] = ['id' => (int) $row->id, 'menu' => $row->menu_name, 'title' => $row->title, 'icon' => $attributes['data-icon'] ?? NULL, 'position' => $attributes['data-icon-position'] ?? NULL];
      }
    }

    $config = [];
    $walk = function ($data, string $name, string $path) use (&$walk, &$config, $known): void {
      if (is_array($data)) {
        foreach ($data as $key => $value) {
          $walk($value, $name, $path === '' ? (string) $key : "$path.$key");
        }
        return;
      }
      if (is_string($data) && isset($known[$data])) {
        $config[] = ['config' => $name, 'key' => $path, 'value' => $data];
      }
    };
    foreach ($this->configFactory->listAll() as $name) {
      if (!str_starts_with($name, 'exo_icon.') && !str_starts_with($name, 'exo_config_file.')) {
        $walk($this->configFactory->get($name)->getRawData(), $name, '');
      }
    }

    $values = array_merge(array_keys($live), array_filter(array_column($menu, 'icon')));
    return [
      'packages' => $packages,
      'known_icons' => count($known),
      'live' => $live,
      'menu_links' => $menu,
      'config' => $config,
      'dangling' => array_values(array_unique(array_filter($values, static fn ($value) => !isset($known[$value])))),
    ];
  }

  /**
   * exo_toolbar toolbars and their items.
   */
  private function exoToolbar(): array {
    if (!$this->moduleHandler->moduleExists('exo_toolbar')) {
      return [];
    }
    $toolbars = [];
    foreach ($this->entityTypeManager->getStorage('exo_toolbar')->loadMultiple() as $id => $toolbar) {
      $toolbars[$id] = ['label' => (string) $toolbar->label(), 'status' => $toolbar->status()];
    }
    $items = [];
    foreach ($this->entityTypeManager->getStorage('exo_toolbar_item')->loadMultiple() as $id => $item) {
      $settings = $item->get('settings') ?? [];
      $items[] = [
        'id' => $id,
        'toolbar' => $item->get('toolbar'),
        'plugin' => (string) $item->get('plugin'),
        'region' => $item->get('region'),
        'weight' => $item->get('weight'),
        'status' => $item->status(),
        'title' => $settings['title'] ?? NULL,
        'url' => $settings['url'] ?? NULL,
        'icon' => $settings['icon'] ?? NULL,
      ];
    }
    usort($items, static fn ($a, $b) => [$a['toolbar'], $a['region'], $a['weight']] <=> [$b['toolbar'], $b['region'], $b['weight']]);
    return ['toolbars' => $toolbars, 'items' => $items];
  }

  /**
   * exo_site_settings bundles, their fields and which hold a value.
   */
  private function exoSiteSettings(): array {
    if (!$this->moduleHandler->moduleExists('exo_site_settings')) {
      return [];
    }
    $types = [];
    foreach ($this->entityTypeManager->getStorage('exo_site_settings_type')->loadMultiple() as $id => $type) {
      $fields = [];
      foreach ($this->entityFieldManager->getFieldDefinitions('exo_site_settings', $id) as $name => $definition) {
        if (!$definition->getFieldStorageDefinition()->isBaseField()) {
          $fields[$name] = $definition->getType();
        }
      }
      $types[$id] = ['label' => (string) $type->label(), 'fields' => $fields, 'filled' => []];
    }
    foreach ($this->entityTypeManager->getStorage('exo_site_settings')->loadMultiple() as $settings) {
      foreach (array_keys($types[$settings->bundle()]['fields'] ?? []) as $name) {
        if ($settings->hasField($name) && !$settings->get($name)->isEmpty()) {
          $types[$settings->bundle()]['filled'][] = $name;
        }
      }
    }
    return $types;
  }

  /**
   * Favicon packages from real_favicon.
   */
  private function favicon(): array {
    if (!$this->moduleHandler->moduleExists('real_favicon')) {
      return [];
    }
    $packages = [];
    foreach ($this->entityTypeManager->getStorage('real_favicon')->loadMultiple() as $id => $favicon) {
      $packages[$id] = ['label' => (string) $favicon->label(), 'status' => $favicon->status()];
    }
    return [
      'packages' => $packages,
      'settings' => $this->configFactory->get('real_favicon.settings')->getRawData(),
    ];
  }

  /**
   * Escort items and the neo_toolbar item each becomes.
   */
  private function toolbar(): array {
    if (!$this->moduleHandler->moduleExists('escort')) {
      return [];
    }
    $plugins = $this->catalog->get('escort_plugins');
    $items = [];
    foreach ($this->entityTypeManager->getStorage('escort')->loadMultiple() as $id => $item) {
      $settings = $item->get('settings') ?? [];
      unset($settings['id'], $settings['provider']);
      $plugin = (string) $item->get('plugin');
      $items[] = [
        'id' => $id,
        'plugin' => $plugin,
        'region' => $item->get('region'),
        'weight' => $item->get('weight'),
        'status' => $item->status(),
        'settings' => $settings,
        'neo_toolbar' => $plugins[$plugin] ?? NULL,
      ];
    }
    return ['items' => $items, 'config' => $this->configFactory->get('escort.config')->getRawData()];
  }

  /**
   * The keys the legacy site_settings module stores.
   */
  private function siteSettings(): array {
    if (!$this->moduleHandler->moduleExists('site_settings')) {
      return [];
    }
    $data = $this->configFactory->get('site_settings.site')->getRawData();
    unset($data['_core']);
    $filled = array_keys(array_filter($data, static fn ($value) => $value !== '' && $value !== NULL && $value !== []));
    return ['keys' => array_keys($data), 'filled' => $filled];
  }

  /**
   * Metatag defaults that use a legacy token.
   */
  private function metatags(): array {
    $fragments = $this->catalog->get('tokens');
    $found = [];
    foreach ($this->configFactory->listAll('metatag.metatag_defaults.') as $name) {
      foreach ($this->configFactory->get($name)->get('tags') ?? [] as $tag => $value) {
        foreach ($fragments as $fragment) {
          if (is_string($value) && str_contains($value, $fragment)) {
            $found[] = ['config' => $name, 'tag' => $tag, 'value' => $value];
            break;
          }
        }
      }
    }
    return $found;
  }

  /**
   * The default theme's templates, preprocess code, libraries and blocks.
   */
  private function theme(): array {
    $default = (string) $this->configFactory->get('system.theme')->get('default');
    if (!$this->themeHandler->themeExists($default)) {
      return [];
    }
    $path = $this->themeHandler->getTheme($default)->getPath();
    $root = $this->appRoot . '/' . $path;
    $templates = [];
    $preprocess = [];
    foreach ($this->files($root, ['twig', 'php']) as $file) {
      $relative = substr($file, strlen($root) + 1);
      if (str_ends_with($file, '.html.twig')) {
        $templates[] = $relative;
      }
      elseif (str_contains($relative, 'Plugin/Preprocess/')) {
        $preprocess[] = $relative;
      }
    }
    sort($templates);
    sort($preprocess);
    $libraries = [];
    if (is_file("$root/$default.libraries.yml")) {
      $libraries = array_keys(Yaml::decode((string) file_get_contents("$root/$default.libraries.yml")) ?: []);
    }

    $blocks = [];
    $otherThemes = [];
    foreach ($this->entityTypeManager->getStorage('block')->loadMultiple() as $id => $block) {
      /** @var \Drupal\block\BlockInterface $block */
      if ($block->getTheme() === $default) {
        $blocks[] = ['id' => $id, 'plugin' => $block->getPluginId(), 'region' => $block->getRegion(), 'status' => $block->status()];
      }
      else {
        $otherThemes[$block->getTheme()] = ($otherThemes[$block->getTheme()] ?? 0) + 1;
      }
    }
    return [
      'name' => $default,
      'path' => $path,
      'templates' => $templates,
      'paragraph_templates' => array_values(array_filter($templates, static fn ($t) => str_contains(basename($t), 'paragraph'))),
      'preprocess' => $preprocess,
      'libraries' => $libraries,
      'blocks' => $blocks,
      'blocks_in_other_themes' => $otherThemes,
    ];
  }

  /**
   * How often the site's own code mentions the legacy stack.
   *
   * Site code is the default theme plus every enabled module or theme that
   * lives outside core and outside a contrib/community directory.
   */
  private function code(): array {
    $roots = [];
    foreach ([...$this->moduleHandler->getModuleList(), ...$this->themeHandler->listInfo()] as $name => $extension) {
      $path = $extension->getPath();
      if (str_starts_with($path, 'core/') || str_contains($path, '/contrib/') || str_contains($path, '/community/')) {
        continue;
      }
      $roots[$name] = $path;
    }
    $patterns = $this->catalog->get('code_patterns');
    $matches = [];
    foreach ($roots as $path) {
      foreach ($this->files($this->appRoot . '/' . $path, self::CODE_EXTENSIONS) as $file) {
        $content = (string) file_get_contents($file);
        $relative = substr($file, strlen($this->appRoot) + 1);
        foreach ($patterns as $key => $needle) {
          $count = substr_count(strtolower($content), strtolower($needle));
          if ($count) {
            $matches[$key]['total'] = ($matches[$key]['total'] ?? 0) + $count;
            $matches[$key]['files'][$relative] = $count;
          }
        }
      }
    }
    foreach ($matches as &$match) {
      arsort($match['files']);
    }
    ksort($matches);
    return ['roots' => array_values($roots), 'matches' => $matches];
  }

  /**
   * The config that uninstalling the legacy extensions would delete or change.
   *
   * A dry run of core's own dependency calculation, so the teardown's blast
   * radius is known before the first line of the migration is written.
   */
  private function removal(): array {
    $modules = [];
    $themes = [];
    foreach ($this->extensions()['legacy'] as $extension) {
      if ($extension['status'] !== 'enabled') {
        continue;
      }
      if ($extension['type'] === 'module') {
        $modules[] = $extension['name'];
      }
      else {
        $themes[] = $extension['name'];
      }
    }
    $result = ['delete' => [], 'update' => []];
    foreach (['module' => $modules, 'theme' => $themes] as $type => $names) {
      if (!$names) {
        continue;
      }
      $changes = $this->configManager->getConfigEntitiesToChangeOnDependencyRemoval($type, $names);
      foreach (['delete', 'update'] as $operation) {
        foreach ($changes[$operation] as $entity) {
          if ($entity instanceof ConfigEntityInterface) {
            $result[$operation][] = $entity->getConfigDependencyName();
          }
        }
      }
    }
    // Config deleted by one removal is not also "changed" by another.
    $result['update'] = array_diff($result['update'], $result['delete']);
    foreach ($result as &$names) {
      $names = array_values(array_unique($names));
      sort($names);
    }
    return ['modules' => $modules, 'themes' => $themes] + $result;
  }

  /**
   * Headline numbers and every finding with its handling.
   */
  private function summary(array $report, array $inventory): array {
    $findings = [];
    foreach ($report['extensions']['legacy'] as $extension) {
      $findings[] = [
        'area' => $extension['type'],
        'item' => $extension['name'] . ($extension['status'] === 'present' ? ' (present, not enabled)' : ''),
        'handling' => $extension['status'] === 'present' ? 'remove' : $extension['handling'],
        'replacement' => $extension['replacement'] ?? NULL,
        'note' => $extension['note'] ?? NULL,
      ];
    }
    foreach ($report['paragraphs']['types'] ?? [] as $id => $type) {
      $findings[] = [
        'area' => 'paragraph type',
        'item' => $id,
        'handling' => $type['handling'],
        'replacement' => $type['live'] ? 'component' : NULL,
        'note' => sprintf('%d live on %d host(s), %d in database', $type['live'], $type['hosts'], $type['in_database']),
      ];
    }
    foreach ($report['components']['types'] ?? [] as $id => $type) {
      $findings[] = [
        'area' => 'component',
        'item' => $id,
        'handling' => $type['handling'],
        'replacement' => $type['live'] ? 'component' : NULL,
        'note' => sprintf('%d live (%d top-level, %d nested) on %d host(s), %d in database', $type['live'], $type['top'], $type['nested'], $type['hosts'], $type['in_database']),
      ];
    }
    foreach ($report['components']['orphans'] ?? [] as $bundle => $count) {
      $findings[] = ['area' => 'component', 'item' => $bundle, 'handling' => 'remove', 'replacement' => NULL, 'note' => "$count in database with no component definition; delete before uninstalling exo_alchemist"];
    }
    foreach ($report['exo_icons']['dangling'] ?? [] as $value) {
      $findings[] = ['area' => 'icon', 'item' => $value, 'handling' => 'judgment', 'replacement' => NULL, 'note' => 'Stored in live content or a menu link, but in no exo_icon package; needs a fallback'];
    }
    foreach ($report['paragraphs']['orphans'] ?? [] as $bundle => $count) {
      $findings[] = ['area' => 'paragraph type', 'item' => $bundle, 'handling' => 'remove', 'replacement' => NULL, 'note' => "$count in database with no config; delete before uninstalling paragraphs"];
    }
    $danglingIcons = [];
    foreach ($report['icons']['fields'] ?? [] as $field) {
      foreach ($field['dangling'] ?? [] as $value) {
        $danglingIcons[$value] = TRUE;
      }
    }
    foreach ($report['icons']['menu_links']['dangling'] ?? [] as $value) {
      $danglingIcons[$value] = TRUE;
    }
    foreach (array_keys($danglingIcons) as $value) {
      $live = $report['icons']['live'][$value] ?? 0;
      $findings[] = [
        'area' => 'icon',
        'item' => $value,
        'handling' => $live ? 'judgment' : 'skip',
        'replacement' => NULL,
        'note' => $live
          ? "In no icon package, yet used $live time(s) in live content; needs a fallback"
          : 'In no icon package, and not used in live content',
      ];
    }
    foreach ($report['extensions']['unclassified'] as $module) {
      $findings[] = ['area' => 'module', 'item' => $module['name'], 'handling' => 'unclassified', 'replacement' => NULL, 'note' => $module['path']];
    }

    $handling = array_count_values(array_column($findings, 'handling'));
    ksort($handling);
    return [
      'hosts' => count($inventory['entities']),
      'paragraph_types' => count($report['paragraphs']['types'] ?? []),
      'paragraph_types_live' => count(array_filter($report['paragraphs']['types'] ?? [], static fn ($t) => $t['live'] > 0)),
      'paragraphs_live' => $report['paragraphs']['total_live'] ?? 0,
      'paragraphs_in_database' => $report['paragraphs']['total_in_database'] ?? 0,
      'component_types' => count($report['components']['types'] ?? []),
      'component_types_live' => count(array_filter($report['components']['types'] ?? [], static fn ($t) => $t['live'] > 0)),
      'components_live' => $report['components']['total_live'] ?? 0,
      'components_in_database' => $report['components']['total_in_database'] ?? 0,
      'theme_templates' => count($report['theme']['templates'] ?? []),
      'config_deleted_on_removal' => count($report['removal']['delete']),
      'handling' => $handling,
      'findings' => $findings,
    ];
  }

  /**
   * Files beneath a directory with one of the given extensions.
   *
   * @return list<string>
   */
  private function files(string $root, array $extensions): array {
    if (!is_dir($root)) {
      return [];
    }
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveCallbackFilterIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        static fn (\SplFileInfo $file) => !($file->isDir() && in_array($file->getFilename(), self::SKIP_DIRS, TRUE)),
      ),
    );
    $files = [];
    foreach ($iterator as $file) {
      if ($file->isFile() && in_array($file->getExtension(), $extensions, TRUE)) {
        $files[] = $file->getPathname();
      }
    }
    sort($files);
    return $files;
  }

}
