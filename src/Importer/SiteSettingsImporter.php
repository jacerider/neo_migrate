<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Importer;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\neo_migrate\LegacyCatalog;

/**
 * Moves the legacy site_settings values into neo_site_settings.
 *
 * Two halves, run in different places. The structure — bundles such as
 * "hours" that neo_site_settings does not ship — is config: created once
 * locally and exported. The values are content: the legacy values live in
 * config that is excluded from sync, so each environment holds its own, and
 * the import runs on each environment reading that environment's values.
 * What goes where is the `site_settings` map in neo_migrate.legacy.yml.
 */
final class SiteSettingsImporter {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityDisplayRepositoryInterface $displayRepository,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LegacyCatalog $catalog,
  ) {}

  /**
   * Creates the bundles and fields the map needs, and sets the legacy icon on
   * each mapped link field's formatter. Creates config.
   *
   * @return list<string>
   *   What was created.
   */
  public function ensureStructure(bool $dryRun = FALSE): array {
    $created = [];
    $types = $this->entityTypeManager->getStorage('neo_site_settings_type');
    $storages = $this->entityTypeManager->getStorage('field_storage_config');
    $fields = $this->entityTypeManager->getStorage('field_config');
    $weight = 10;
    foreach ($this->catalog->get('site_settings')['bundles'] ?? [] as $bundle => $definition) {
      if (!$types->load($bundle)) {
        $created[] = "type $bundle";
        if (!$dryRun) {
          $types->create(['id' => $bundle, 'label' => $definition['label'], 'weight' => $weight++, 'aggregate' => TRUE, 'icon' => $definition['icon'] ?? ''])->save();
        }
      }
      $fieldWeight = 0;
      foreach ($definition['fields'] as $name => $label) {
        if (!$storages->load("neo_site_settings.$name")) {
          $created[] = "storage $name";
          if (!$dryRun) {
            $storages->create(['field_name' => $name, 'entity_type' => 'neo_site_settings', 'type' => 'string', 'cardinality' => 1])->save();
          }
        }
        if (!$fields->load("neo_site_settings.$bundle.$name")) {
          $created[] = "field $bundle.$name";
          if (!$dryRun) {
            $fields->create(['field_name' => $name, 'entity_type' => 'neo_site_settings', 'bundle' => $bundle, 'label' => $label])->save();
            $this->displayRepository->getFormDisplay('neo_site_settings', $bundle)
              ->setComponent($name, ['type' => 'string_textfield', 'weight' => $fieldWeight])
              ->save();
            $this->displayRepository->getViewDisplay('neo_site_settings', $bundle)
              ->setComponent($name, ['type' => 'string', 'label' => 'inline', 'weight' => $fieldWeight])
              ->save();
          }
        }
        $fieldWeight++;
      }
    }
    foreach ($this->catalog->get('site_settings')['link_icons'] ?? [] as $target => $icon) {
      [$bundle, $name] = explode('.', $target, 2);
      if (!$types->load($bundle)) {
        continue;
      }
      $display = $this->displayRepository->getViewDisplay('neo_site_settings', $bundle);
      $component = $display->getComponent($name);
      if (!$component || ($component['settings']['icon'] ?? NULL) === $icon) {
        continue;
      }
      $created[] = "icon $target: $icon";
      if (!$dryRun) {
        $component['settings']['icon'] = $icon;
        $display->setComponent($name, $component)->save();
      }
    }
    return $created;
  }

  /**
   * Writes this environment's legacy values into neo_site_settings.
   *
   * @return list<array{target: string, value: string, action: string}>
   *   One row per mapped field.
   */
  public function import(bool $dryRun = FALSE): array {
    $definition = $this->catalog->get('site_settings');
    $legacy = $this->configFactory->get($definition['source'] ?? 'site_settings.site')->getRawData();
    if (!$legacy) {
      throw new \RuntimeException('No legacy site settings found in ' . ($definition['source'] ?? 'site_settings.site') . '.');
    }
    /** @var \Drupal\neo_site_settings\SiteSettingsStorage $storage */
    $storage = $this->entityTypeManager->getStorage('neo_site_settings');
    $entities = [];
    $report = [];
    $values = $definition['map'] ?? [];
    $values['general.field_name'] = NULL;
    foreach ($values as $target => $source) {
      [$bundle, $field] = explode('.', $target, 2);
      $value = $source === NULL
        ? (string) $this->configFactory->get('system.site')->get('name')
        : trim(implode("\n", array_filter(array_map(static fn ($key) => trim((string) ($legacy[$key] ?? '')), (array) $source))));
      $entities[$bundle] ??= $storage->loadOrCreateByType($bundle);
      $entity = $entities[$bundle];
      if (!$entity->hasField($field)) {
        $report[] = ['target' => $target, 'value' => $value, 'action' => 'no such field'];
        continue;
      }
      $type = $entity->getFieldDefinition($field)->getType();
      $report[] = ['target' => $target, 'value' => $value, 'action' => $value === '' ? 'empty' : 'set'];
      if ($value === '') {
        $entity->set($field, NULL);
      }
      elseif ($type === 'link') {
        $entity->set($field, ['uri' => $value, 'title' => '']);
      }
      else {
        $entity->set($field, $value);
      }
    }
    if (!$dryRun) {
      foreach ($entities as $entity) {
        $entity->save();
      }
    }
    return $report;
  }

}
