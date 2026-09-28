<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Importer;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\neo_migrate\IconNameResolver;
use Drupal\neo_migrate\LegacyCatalog;

/**
 * Rebuilds a site's legacy toolbar items (escort or exo_toolbar) as
 * neo_toolbar items.
 *
 * The legacy items are read from active config while their module is
 * installed, and from the sync directory once it is not, so the import can run
 * either side of the uninstall. Items neo_toolbar's own install already
 * provides — the user menu, local tasks, local actions, the home link — are
 * reported as covered rather than duplicated. Created items are named
 * `escort_<id>` or `exo_<id>`, so a second run updates them instead of adding
 * more. The links neo_toolbar puts on the rail by default (Content, User
 * Accounts) are removed: the legacy toolbar decides which links the rail
 * carries.
 */
final class ToolbarImporter {

  /**
   * Each legacy toolbar: its config prefix, access permission, catalog plugin
   * map and the plugins whose job a stock neo_toolbar item already does.
   */
  private const SOURCES = [
    'escort' => [
      'prefix' => 'escort.escort.',
      'permission' => 'access escort',
      'plugins' => 'escort_plugins',
      'covered' => ['admin_escape', 'branding', 'user', 'current_user', 'local_tasks', 'local_actions'],
    ],
    'exo' => [
      'prefix' => 'exo_toolbar.exo_toolbar_item.',
      'permission' => 'access exo toolbar',
      'plugins' => 'exo_toolbar_plugins',
      'covered' => ['admin_escape', 'image', 'user', 'local_tasks', 'local_actions'],
    ],
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StorageInterface $syncStorage,
    private readonly IconNameResolver $icons,
    private readonly LegacyCatalog $catalog,
    private readonly ModuleExtensionList $moduleList,
  ) {}

  /**
   * Imports every escort item into a toolbar.
   *
   * @return list<array{escort: string, plugin: string, action: string, item: ?string, note: string}>
   *   One row per escort item.
   */
  public function import(string $toolbar = 'default', bool $dryRun = FALSE): array {
    if (!$this->entityTypeManager->hasDefinition('neo_toolbar_item')) {
      throw new \RuntimeException('neo_toolbar is not installed.');
    }
    $storage = $this->entityTypeManager->getStorage('neo_toolbar_item');
    /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface[] $existing */
    $existing = $storage->loadByProperties(['toolbar' => $toolbar]);
    [$source, $legacyItems] = $this->legacyItems();
    $plugins = $source ? $this->catalog->get(self::SOURCES[$source]['plugins']) : [];
    $report = [];

    foreach ($legacyItems as $escort) {
      $id = (string) $escort['id'];
      $plugin = (string) $escort['plugin'];
      $settings = $escort['settings'] ?? [];
      $row = ['legacy' => $id, 'plugin' => $plugin, 'action' => '', 'item' => NULL, 'note' => ''];

      if (in_array($plugin, self::SOURCES[$source]['covered'], TRUE)) {
        $target = $plugins[$plugin] ?? $plugin;
        $match = array_filter($existing, static fn ($item) => $item->get('plugin') === $target);
        $row['action'] = $match ? 'covered' : 'missing';
        $row['item'] = $match ? (string) array_key_first($match) : NULL;
        $row['note'] = $match ? "neo_toolbar's own \"$target\" item" : "no \"$target\" item in the toolbar; add one by hand";
        $report[] = $row;
        continue;
      }

      $values = match ($plugin) {
        'link' => $this->link($settings, (string) ($settings['url'] ?? '')),
        'node_manage' => $this->link($settings, 'internal:/admin/content?type=' . ($settings['bundle'] ?? '')),
        'node_add' => $this->create($settings),
        // exo_toolbar's dividers head a group; neo's carry no title.
        'divider' => $source === 'exo' ? ['plugin' => 'divider', 'settings' => []] : NULL,
        default => NULL,
      };
      if ($values === NULL) {
        $report[] = ['action' => 'unmapped', 'note' => 'no neo_toolbar equivalent'] + $row;
        continue;
      }

      $itemId = $source . '_' . $id;
      $label = (string) ($settings['text'] ?? '') ?: (string) ($settings['title'] ?? '') ?: (string) ($settings['label'] ?? $id);
      // Escort's right-hand regions and exo_toolbar's top bar sit at the end
      // of neo_toolbar's rail; exo_toolbar's left rail at its start.
      $region = (string) ($escort['region'] ?? '');
      $values['region'] = match ($source) {
        'exo' => $region === 'left' ? 'side_start' : 'side_end',
        default => str_ends_with($region, 'right') ? 'side_end' : 'side_start',
      };
      if ($values['plugin'] === 'create') {
        // Neo puts the create menu under Home, wherever the legacy one sat.
        $values['region'] = 'side_start';
      }
      if ($values['plugin'] === 'divider') {
        $label = 'Divider';
      }
      $values += [
        'id' => $itemId,
        'label' => $label,
        'toolbar' => $toolbar,
        'weight' => (int) ($escort['weight'] ?? 0) + 10,
        'visibility' => [],
      ];
      $collisions = array_filter($existing, static fn ($item, $key) => $key !== $itemId && strcasecmp((string) $item->label(), $label) === 0, ARRAY_FILTER_USE_BOTH);
      $notes = [];
      if ($values['plugin'] === 'divider' && !empty($settings['title'])) {
        $notes[] = sprintf('its title "%s" is not shown (neo dividers carry none)', $settings['title']);
      }
      if (!empty($values['_unresolved_icon'])) {
        $notes[] = 'icon ' . $values['_unresolved_icon'] . ' has no neo_icon match';
      }
      if ($collisions) {
        $notes[] = 'same label as ' . implode(', ', array_keys($collisions));
      }
      unset($values['_unresolved_icon']);

      /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface|null $item */
      $item = $storage->load($itemId);
      $row['action'] = $item ? 'updated' : 'created';
      $row['item'] = $itemId;
      $row['note'] = implode('; ', $notes);
      if (!$dryRun) {
        if ($item) {
          foreach ($values as $key => $value) {
            $item->set($key, $value);
          }
        }
        else {
          $item = $storage->create($values);
        }
        $item->save();
      }
      $report[] = $row;
    }
    $report = array_merge($report, $this->removeDefaultLinks($toolbar, $dryRun));
    $this->order($toolbar, $dryRun);
    return $report;
  }

  /**
   * Removes the links neo_toolbar's install put on the rail.
   *
   * Only items neo_toolbar ships (its install config) and only link items on
   * the rail itself: the user menu's own links, the home item and anything a
   * site added stay.
   *
   * @return list<array>
   *   One report row per removed item.
   */
  private function removeDefaultLinks(string $toolbar, bool $dryRun): array {
    $storage = $this->entityTypeManager->getStorage('neo_toolbar_item');
    $defaults = new FileStorage($this->moduleList->getPath('neo_toolbar') . '/config/install');
    $report = [];
    foreach ($defaults->listAll('neo_toolbar.neo_toolbar_item.') as $name) {
      $default = $defaults->read($name) ?: [];
      if (($default['plugin'] ?? '') !== 'link' || !in_array($default['region'] ?? '', ['side_start', 'side_end'], TRUE)) {
        continue;
      }
      $item = $storage->load($default['id'] ?? '');
      if (!$item || $item->get('toolbar') !== $toolbar) {
        continue;
      }
      $report[] = ['legacy' => '', 'plugin' => 'link', 'action' => 'removed', 'item' => $item->id(), 'note' => sprintf('neo_toolbar\'s default "%s" link; the legacy toolbar had none', $item->label())];
      if (!$dryRun) {
        $item->delete();
      }
    }
    return $report;
  }

  /**
   * Orders the rail the way Neo sites have it.
   *
   * The start of the rail: Home (the favicon item), then the create menu, then
   * the rest in their current order — neo_toolbar's own items, then the
   * imported ones in escort's order. The end of the rail: everything else, then
   * the user menu, always last. Escort put its create link wherever a site
   * chose; Neo puts it under Home.
   */
  private function order(string $toolbar, bool $dryRun): void {
    $storage = $this->entityTypeManager->getStorage('neo_toolbar_item');
    $items = $storage->loadByProperties(['toolbar' => $toolbar]);
    uasort($items, static fn ($a, $b) => [(int) $a->get('weight'), $a->id()] <=> [(int) $b->get('weight'), $b->id()]);
    $rank = [
      'side_start' => ['favicon' => 0, 'create' => 1],
      'side_end' => ['user' => 1],
    ];
    foreach ($rank as $region => $ranks) {
      $inRegion = array_values(array_filter($items, static fn ($item) => $item->get('region') === $region));
      $default = $region === 'side_start' ? 2 : 0;
      // A stable sort: items of the same rank keep their current order.
      $keyed = [];
      foreach ($inRegion as $position => $item) {
        $keyed[] = [$ranks[$item->get('plugin')] ?? $default, $position, $item];
      }
      sort($keyed);
      foreach ($keyed as $weight => [, , $item]) {
        if ((int) $item->get('weight') !== $weight) {
          $item->set('weight', $weight);
          if (!$dryRun) {
            $item->save();
          }
        }
      }
    }
  }

  /**
   * Shows a toolbar only on one theme, on all but one, or everywhere again.
   *
   * neo_toolbar's assets are built for Neo themes only; over a legacy front
   * theme it renders unstyled. While the public site is still legacy the
   * toolbar is kept off the legacy front theme (`$except`), so editors have it
   * on the admin and on the Neo front theme in the preview.
   *
   * @param string|null $theme
   *   The theme to limit it to — or, with $except, to keep it off — or NULL to
   *   show it on every theme.
   * @param bool $dryRun
   *   Report only.
   * @param bool $except
   *   Show it on every theme but $theme.
   */
  public function limitToTheme(string $toolbar, ?string $theme, bool $dryRun = FALSE, bool $except = FALSE): void {
    $entity = $this->entityTypeManager->getStorage('neo_toolbar')->load($toolbar);
    if (!$entity) {
      throw new \RuntimeException("No neo_toolbar \"$toolbar\".");
    }
    /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface $entity */
    $visibility = $entity->get('visibility') ?: [];
    unset($visibility['current_theme']);
    if ($theme !== NULL) {
      $visibility['current_theme'] = ['id' => 'current_theme', 'theme' => $theme, 'negate' => $except];
    }
    if (!$dryRun) {
      $entity->set('visibility', $visibility)->save();
    }
  }

  /**
   * Grants the toolbar to every role that could use the legacy toolbar.
   *
   * Roles are read from active config while the legacy toolbar is installed
   * and from the sync directory once it is not — uninstalling it strips its
   * permission from the active roles.
   *
   * @return list<string>
   *   The roles granted `access neo_toolbar`.
   */
  public function grantAccess(bool $dryRun = FALSE): array {
    $granted = [];
    $permissions = array_column(self::SOURCES, 'permission');
    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();
    foreach ($roles as $id => $role) {
      /** @var \Drupal\user\RoleInterface $role */
      $synced = $this->syncStorage->read("user.role.$id")['permissions'] ?? [];
      $had = (bool) array_filter($permissions, static fn ($permission) => $role->hasPermission($permission) || in_array($permission, $synced, TRUE));
      if (!$had || $role->isAdmin()) {
        continue;
      }
      $granted[] = $id;
      if (!$dryRun && !$role->hasPermission('access neo_toolbar')) {
        $role->grantPermission('access neo_toolbar')->save();
      }
    }
    return $granted;
  }

  /**
   * A neo_toolbar link item.
   */
  private function link(array $settings, string $url): array {
    $icon = $this->icons->resolve($settings['icon'] ?? NULL);
    return [
      'plugin' => 'link',
      'settings' => [
        'url' => $url,
        'target' => !empty($settings['target']),
        'icon' => $icon ?? '',
        'badge' => ['type' => ''],
      ],
      '_unresolved_icon' => $icon === NULL && !empty($settings['icon']) ? $settings['icon'] : NULL,
    ];
  }

  /**
   * A neo_toolbar create item for the node types escort's "add" offered.
   *
   * Types that no longer exist are dropped; escort's include/exclude choice is
   * kept as neo_toolbar's own exclude flag.
   */
  private function create(array $settings): array {
    $types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    $bundles = array_values(array_intersect(array_keys($settings['bundles'] ?? []), array_keys($types)));
    $icon = $this->icons->resolve($settings['icon'] ?? NULL);
    return [
      'plugin' => 'create',
      'settings' => [
        'icon' => $icon ?? 'plus-circle',
        'scheme' => '',
        'node_enabled' => TRUE,
        'node_bundles' => array_combine($bundles, $bundles) ?: [],
        'node_exclude' => ($settings['type'] ?? 'include') === 'exclude',
        'taxonomy_enabled' => FALSE,
        'taxonomy_bundles' => [],
        'taxonomy_exclude' => FALSE,
        'media_enabled' => FALSE,
        'media_bundles' => [],
        'media_exclude' => FALSE,
      ],
      '_unresolved_icon' => $icon === NULL && !empty($settings['icon']) ? $settings['icon'] : NULL,
    ];
  }

  /**
   * The legacy toolbar's items and which toolbar they come from.
   *
   * Escort first, then exo_toolbar; each from active config or else the sync
   * directory. exo_toolbar's items of other toolbars than its default are left
   * out.
   *
   * @return array{0: ?string, 1: list<array>}
   */
  private function legacyItems(): array {
    foreach (self::SOURCES as $source => $info) {
      $names = $this->configFactory->listAll($info['prefix']);
      $items = $names
        ? array_map(fn ($name) => $this->configFactory->get($name)->getRawData(), $names)
        : array_map(fn ($name) => $this->syncStorage->read($name), $this->syncStorage->listAll($info['prefix']));
      $items = array_filter($items, static fn ($item) => is_array($item) && ($item['status'] ?? TRUE) && ($item['toolbar'] ?? 'default') === 'default');
      if ($items) {
        usort($items, static fn ($a, $b) => [$a['region'] ?? '', $a['weight'] ?? 0] <=> [$b['region'] ?? '', $b['weight'] ?? 0]);
        return [$source, $items];
      }
    }
    return [NULL, []];
  }

}
