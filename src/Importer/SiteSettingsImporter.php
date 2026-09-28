<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Importer;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\neo_migrate\IconNameResolver;
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
 *
 * exo_site_settings is already entity-based, with bundles and fields much like
 * neo_site_settings' own: its bundles and fields are mirrored (a field neo
 * lacks is created with the same type and settings; `exo_site_settings.
 * field_map` renames the rest), the icons its link formatters showed move to
 * neo's, and values are copied field by field. The values are content there
 * too, so the import runs on each environment.
 */
final class SiteSettingsImporter {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityDisplayRepositoryInterface $displayRepository,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LegacyCatalog $catalog,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly IconNameResolver $icons,
  ) {}

  /**
   * Whether the site's legacy settings come from exo_site_settings.
   */
  private function fromExo(): bool {
    return !$this->moduleHandler->moduleExists('site_settings') && $this->moduleHandler->moduleExists('exo_site_settings');
  }

  /**
   * The neo_site_settings field an exo_site_settings field becomes.
   */
  private function exoTarget(string $field): string {
    return $this->catalog->get('exo_site_settings')['field_map'][$field] ?? $field;
  }

  /**
   * Mirrors exo_site_settings' bundles and fields in neo_site_settings.
   *
   * @return list<string>
   *   What was created or changed.
   */
  private function ensureExoStructure(bool $dryRun): array {
    $created = [];
    $types = $this->entityTypeManager->getStorage('neo_site_settings_type');
    $storages = $this->entityTypeManager->getStorage('field_storage_config');
    $fields = $this->entityTypeManager->getStorage('field_config');
    foreach ($this->entityTypeManager->getStorage('exo_site_settings_type')->loadMultiple() as $bundle => $exoType) {
      if (!$types->load($bundle)) {
        $created[] = "type $bundle";
        if (!$dryRun) {
          $types->create(['id' => $bundle, 'label' => (string) $exoType->label(), 'aggregate' => TRUE])->save();
        }
      }
      $exoForm = $this->displayRepository->getFormDisplay('exo_site_settings', $bundle);
      $exoView = $this->displayRepository->getViewDisplay('exo_site_settings', $bundle);
      foreach ($fields->loadByProperties(['entity_type' => 'exo_site_settings', 'bundle' => $bundle]) as $exoField) {
        /** @var \Drupal\field\FieldConfigInterface $exoField */
        $name = $this->exoTarget($exoField->getName());
        $exoStorage = $exoField->getFieldStorageDefinition();
        if (!$storages->load("neo_site_settings.$name")) {
          $created[] = "storage $name (" . $exoStorage->getType() . ')';
          if (!$dryRun) {
            $storages->create([
              'field_name' => $name,
              'entity_type' => 'neo_site_settings',
              'type' => $exoStorage->getType(),
              'cardinality' => $exoStorage->getCardinality(),
              'settings' => $exoStorage->getSettings(),
            ])->save();
          }
        }
        if (!$fields->load("neo_site_settings.$bundle.$name")) {
          $created[] = "field $bundle.$name";
          if (!$dryRun) {
            $fields->create([
              'field_name' => $name,
              'entity_type' => 'neo_site_settings',
              'bundle' => $bundle,
              'label' => $exoField->label(),
              'description' => $exoField->getDescription(),
              'required' => $exoField->isRequired(),
              'settings' => $exoField->getSettings(),
            ])->save();
            if ($component = $exoForm->getComponent($exoField->getName())) {
              $this->displayRepository->getFormDisplay('neo_site_settings', $bundle)->setComponent($name, $component)->save();
            }
            if ($component = $exoView->getComponent($exoField->getName())) {
              unset($component['third_party_settings']);
              $this->displayRepository->getViewDisplay('neo_site_settings', $bundle)->setComponent($name, $component)->save();
            }
          }
        }
        // The icon exo's link formatter drew beside the link goes to neo's.
        $icon = $exoView->getComponent($exoField->getName())['settings']['icon'] ?? '';
        if ($icon !== '' && $exoStorage->getType() === 'link') {
          $icon = $this->icons->resolve($icon) ?? $icon;
          $display = $this->displayRepository->getViewDisplay('neo_site_settings', $bundle);
          $component = $display->getComponent($name);
          if ($component && ($component['settings']['icon'] ?? NULL) !== $icon) {
            $created[] = "icon $bundle.$name: $icon";
            if (!$dryRun) {
              $component['settings']['icon'] = $icon;
              $display->setComponent($name, $component)->save();
            }
          }
        }
      }
    }
    return $created;
  }

  /**
   * Copies this environment's exo_site_settings values into neo_site_settings.
   *
   * @return list<array{target: string, value: string, action: string}>
   *   One row per field.
   */
  private function importExo(bool $dryRun): array {
    /** @var \Drupal\neo_site_settings\SiteSettingsStorage $storage */
    $storage = $this->entityTypeManager->getStorage('neo_site_settings');
    $report = [];
    foreach ($this->entityTypeManager->getStorage('exo_site_settings')->loadMultiple() as $exo) {
      /** @var \Drupal\Core\Entity\ContentEntityInterface $exo */
      $bundle = $exo->bundle();
      $neo = $storage->loadOrCreateByType($bundle);
      foreach ($exo->getFieldDefinitions() as $field => $definition) {
        if ($definition->getFieldStorageDefinition()->isBaseField()) {
          continue;
        }
        $target = $this->exoTarget($field);
        $value = $exo->get($field)->getValue();
        $shown = mb_substr(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '', 0, 80);
        if (!$neo->hasField($target)) {
          $report[] = ['target' => "$bundle.$target", 'value' => $shown, 'action' => 'no such field'];
          continue;
        }
        $report[] = ['target' => "$bundle.$target", 'value' => $value ? $shown : '', 'action' => $value ? 'set' : 'empty'];
        $neo->set($target, $value);
      }
      if (!$dryRun) {
        $neo->save();
      }
    }
    return $report;
  }

  /**
   * Creates the bundles and fields the map needs, and sets the legacy icon on
   * each mapped link field's formatter. Creates config.
   *
   * @return list<string>
   *   What was created.
   */
  public function ensureStructure(bool $dryRun = FALSE): array {
    if ($this->fromExo()) {
      return $this->ensureExoStructure($dryRun);
    }
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
    if ($this->fromExo()) {
      return $this->importExo($dryRun);
    }
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
