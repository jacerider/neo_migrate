<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Source;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\neo_migrate\FieldNormalizer;

/**
 * Reads paragraphs held in entity_reference_revisions fields.
 *
 * A tree is read from the revision each host item points at, so it is exactly
 * what the host's current revision renders — including paragraphs that are
 * unpublished, which keep their status for the converter to carry over.
 */
final class ParagraphsSource implements SourceAdapterInterface {

  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly FieldNormalizer $normalizer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'paragraphs';
  }

  /**
   * {@inheritdoc}
   */
  public function applies(): bool {
    return $this->moduleHandler->moduleExists('paragraphs');
  }

  /**
   * {@inheritdoc}
   */
  public function hosts(): array {
    if (!$this->applies()) {
      return [];
    }
    $hosts = [];
    foreach ($this->paragraphFields() as $entityTypeId => $fields) {
      if ($entityTypeId === 'paragraph') {
        continue;
      }
      foreach ($fields as $fieldName => $bundles) {
        $hosts[] = ['entity_type' => $entityTypeId, 'field' => $fieldName, 'bundles' => $bundles];
      }
    }
    return $hosts;
  }

  /**
   * Every entity_reference_revisions field that targets paragraphs.
   *
   * @return array<string, array<string, list<string>>>
   *   Bundles, keyed by entity type and field name.
   */
  public function paragraphFields(): array {
    $found = [];
    foreach ($this->entityFieldManager->getFieldMapByFieldType('entity_reference_revisions') as $entityTypeId => $fields) {
      $storage = $this->entityFieldManager->getFieldStorageDefinitions($entityTypeId);
      foreach ($fields as $fieldName => $info) {
        if (($storage[$fieldName] ?? NULL)?->getSetting('target_type') !== 'paragraph') {
          continue;
        }
        $bundles = array_values($info['bundles']);
        sort($bundles);
        $found[$entityTypeId][$fieldName] = $bundles;
      }
    }
    ksort($found);
    return $found;
  }

  /**
   * {@inheritdoc}
   */
  public function tree(ContentEntityInterface $host, string $field): array {
    $tree = [];
    foreach ($host->get($field) as $item) {
      $paragraph = $item->entity;
      $tree[] = $paragraph instanceof ContentEntityInterface
        ? $this->item($paragraph)
        : ['missing' => TRUE, 'target_id' => $item->target_id, 'target_revision_id' => $item->target_revision_id];
    }
    return $tree;
  }

  /**
   * One paragraph and everything nested beneath it.
   */
  private function item(ContentEntityInterface $paragraph): array {
    $fields = [];
    foreach ($paragraph->getFieldDefinitions() as $name => $definition) {
      if ($definition->getFieldStorageDefinition()->isBaseField()) {
        continue;
      }
      $items = $paragraph->get($name);
      if ($definition->getType() === 'entity_reference_revisions' && $definition->getSetting('target_type') === 'paragraph') {
        $fields[$name] = ['type' => 'entity_reference_revisions', 'children' => $this->tree($paragraph, $name)];
        continue;
      }
      $fields[$name] = $this->normalizer->normalize($items);
    }
    ksort($fields);
    return [
      'bundle' => $paragraph->bundle(),
      'id' => (int) $paragraph->id(),
      'revision' => (int) $paragraph->getRevisionId(),
      'uuid' => $paragraph->uuid(),
      'status' => $paragraph instanceof EntityPublishedInterface ? $paragraph->isPublished() : TRUE,
      'behavior' => method_exists($paragraph, 'getAllBehaviorSettings') ? $paragraph->getAllBehaviorSettings() : [],
      'fields' => $fields,
    ];
  }

}
