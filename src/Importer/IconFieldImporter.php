<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Importer;

use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Gives a micon icon field a neo_icon twin and copies its values across.
 *
 * A field's type cannot change through config import, and micon's
 * `string_micon` type disappears when micon is uninstalled, so each such field
 * on a host entity gets a new `neo_icon` field beside it. The stored names —
 * `assured-heating` — carry over unchanged: the imported icon libraries are
 * unique and resolve them as they are.
 *
 * Until the cutover the legacy field stays on the edit form and in the legacy
 * displays; the twin is added to the form as hidden, and its values are
 * copied again at the cutover so edits made in the meantime are not lost.
 */
final class IconFieldImporter {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityDisplayRepositoryInterface $displayRepository,
  ) {}

  /**
   * Creates the twin field on every bundle the legacy field is on. Config.
   *
   * @return list<array{bundle: string, action: string}>
   *   One row per bundle.
   */
  public function ensureField(string $entityType, string $legacyField, string $field, bool $dryRun = FALSE): array {
    /** @var \Drupal\field\FieldStorageConfigInterface|null $legacyStorage */
    $legacyStorage = $this->entityTypeManager->getStorage('field_storage_config')->load("$entityType.$legacyField");
    if (!$legacyStorage || $legacyStorage->getType() !== 'string_micon') {
      throw new \RuntimeException("$entityType.$legacyField is not a micon icon field.");
    }
    $storages = $this->entityTypeManager->getStorage('field_storage_config');
    $fields = $this->entityTypeManager->getStorage('field_config');
    if (!$dryRun && !$storages->load("$entityType.$field")) {
      $storages->create([
        'field_name' => $field,
        'entity_type' => $entityType,
        'type' => 'neo_icon',
        'cardinality' => $legacyStorage->getCardinality(),
      ])->save();
    }
    $libraries = array_keys($this->entityTypeManager->getStorage('micon')->loadMultiple());

    $report = [];
    foreach ($legacyStorage->getBundles() as $bundle) {
      /** @var \Drupal\field\FieldConfigInterface $legacy */
      $legacy = $fields->load("$entityType.$bundle.$legacyField");
      $exists = (bool) $fields->load("$entityType.$bundle.$field");
      $report[] = ['bundle' => $bundle, 'action' => $exists ? 'exists' : 'created'];
      if ($dryRun || $exists) {
        continue;
      }
      $fields->create([
        'field_name' => $field,
        'entity_type' => $entityType,
        'bundle' => $bundle,
        'label' => $legacy->getLabel(),
        'description' => $legacy->getDescription(),
        'required' => $legacy->isRequired(),
      ])->save();
      $form = $this->displayRepository->getFormDisplay($entityType, $bundle);
      $weight = $form->getComponent($legacyField)['weight'] ?? 0;
      // Hidden until the cutover: editors keep using the legacy field, whose
      // values are copied across again then.
      $form->setComponent($field, [
        'type' => 'neo_icon',
        'weight' => $weight,
        'settings' => ['include' => array_combine($libraries, $libraries), 'exclude' => [], 'icons' => []],
      ])->removeComponent($field)->save();
    }
    return $report;
  }

  /**
   * Copies every legacy value into the twin field. Content.
   *
   * @return array{entities: int, copied: int, changed: int}
   *   Entities holding the field, values copied, entities whose twin changed.
   */
  public function copyValues(string $entityType, string $legacyField, string $field, bool $dryRun = FALSE): array {
    $storage = $this->entityTypeManager->getStorage($entityType);
    $ids = $storage->getQuery()->accessCheck(FALSE)->exists($legacyField)->execute();
    $result = ['entities' => 0, 'copied' => 0, 'changed' => 0];
    foreach (array_chunk($ids, 50) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $entity) {
        /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
        if (!$entity->hasField($field)) {
          continue;
        }
        $result['entities']++;
        $values = array_map(static fn ($item) => ['value' => $item['value']], $entity->get($legacyField)->getValue());
        $result['copied'] += count($values);
        if ($entity->get($field)->getValue() === $values) {
          continue;
        }
        $result['changed']++;
        if (!$dryRun) {
          $entity->set($field, $values);
          // A copy, not an edit: keep the changed time, write no revision, and
          // stop Pathauto regenerating the alias (PathautoState::SKIP).
          if ($entity instanceof \Drupal\Core\Entity\SynchronizableInterface) {
            $entity->setSyncing(TRUE);
          }
          if ($entity->hasField('path') && !$entity->get('path')->isEmpty()) {
            $entity->get('path')->first()->set('pathauto', 0);
          }
          $entity->save();
        }
      }
    }
    return $result;
  }

}
