<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Importer;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Adds the component tree field that converted content is written into.
 *
 * One `neo_component_tree` field per bundle, as on the reference site: custom
 * trees allowed, hidden on the edit form (editors use the Alchemist layout
 * editor) and rendered by the tree formatter in every view display that shows
 * the legacy body field. It also records both field names for the coexistence
 * hook, so the legacy theme keeps rendering the old body until the cutover.
 */
final class TreeFieldInstaller {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityDisplayRepositoryInterface $displayRepository,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Installs the field on each bundle.
   *
   * @param list<string> $bundles
   *   Bundles of the entity type to add the field to.
   *
   * @return list<array{bundle: string, action: string, displays: list<string>}>
   *   One row per bundle.
   */
  public function install(string $entityType, array $bundles, string $field, string $legacyField, bool $dryRun = FALSE): array {
    $storages = $this->entityTypeManager->getStorage('field_storage_config');
    $fields = $this->entityTypeManager->getStorage('field_config');
    if (!$dryRun && !$storages->load("$entityType.$field")) {
      $storages->create([
        'field_name' => $field,
        'entity_type' => $entityType,
        'type' => 'neo_component_tree',
        'cardinality' => 1,
        'translatable' => TRUE,
      ])->save();
    }

    $report = [];
    foreach ($bundles as $bundle) {
      $row = ['bundle' => $bundle, 'action' => $fields->load("$entityType.$bundle.$field") ? 'exists' : 'created', 'displays' => []];
      if (!$dryRun && $row['action'] === 'created') {
        $fields->create([
          'field_name' => $field,
          'entity_type' => $entityType,
          'bundle' => $bundle,
          'label' => 'Full',
          'required' => FALSE,
          'translatable' => FALSE,
          'settings' => ['allow_custom' => TRUE, 'sizes' => [], 'defaults' => []],
        ])->save();
      }

      $form = $this->displayRepository->getFormDisplay($entityType, $bundle);
      if (!$dryRun && $form->getComponent($field)) {
        $form->removeComponent($field)->save();
      }

      foreach ($this->displayRepository->getViewModeOptionsByBundle($entityType, $bundle) as $mode => $label) {
        $display = $this->displayRepository->getViewDisplay($entityType, $bundle, $mode);
        $legacy = $display->getComponent($legacyField);
        if (!$legacy) {
          continue;
        }
        $row['displays'][] = $mode;
        if (!$dryRun) {
          $display->setComponent($field, [
            'type' => 'neo_component_tree',
            'label' => 'hidden',
            'settings' => [],
            'weight' => $legacy['weight'] ?? 0,
            'region' => 'content',
          ])->save();
        }
      }
      $report[] = $row;
    }

    if (!$dryRun) {
      $this->configFactory->getEditable('neo_migrate.settings')
        ->set('coexistence.tree_field', $field)
        ->set('coexistence.legacy_field', $legacyField)
        ->save();
    }
    return $report;
  }

}
