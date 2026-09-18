<?php

declare(strict_types=1);

namespace Drupal\neo_migrate;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\neo_migrate\Source\SourceAdapterInterface;
use Drupal\path_alias\AliasManagerInterface;

/**
 * A snapshot of every host entity and the tree it holds.
 *
 * Taken before anything changes, it is the record that verification compares
 * the converted site against: same entities, same URLs, same order of items,
 * same values.
 */
final class Inventory {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SourceAdapterInterface $source,
    private readonly FieldNormalizer $normalizer,
    private readonly AliasManagerInterface $aliasManager,
  ) {}

  /**
   * Builds the inventory.
   *
   * @return array{generated: string, source: string, hosts: list<array>, entities: list<array>, counts: array<string, array>}
   */
  public function build(): array {
    $hosts = $this->source->hosts();
    $entities = [];
    foreach ($hosts as $host) {
      $storage = $this->entityTypeManager->getStorage($host['entity_type']);
      $definition = $storage->getEntityType();
      $query = $storage->getQuery()->accessCheck(FALSE)->sort($definition->getKey('id'));
      if ($definition->hasKey('bundle')) {
        $query->condition($definition->getKey('bundle'), $host['bundles'], 'IN');
      }
      foreach (array_chunk($query->execute(), 50) as $ids) {
        foreach ($storage->loadMultiple($ids) as $entity) {
          if (!$entity instanceof ContentEntityInterface || !$entity->hasField($host['field'])) {
            continue;
          }
          $key = $entity->getEntityTypeId() . ':' . $entity->id();
          $entities[$key] ??= $this->describe($entity, array_column($hosts, 'field'));
          $entities[$key]['trees'][$host['field']] = $this->source->tree($entity, $host['field']);
        }
      }
    }
    ksort($entities, SORT_NATURAL);
    $entities = array_values($entities);
    return [
      'generated' => date('c'),
      'source' => $this->source->id(),
      'hosts' => $hosts,
      'entities' => $entities,
      'counts' => $this->count($entities),
    ];
  }

  /**
   * How often each bundle appears across all trees.
   *
   * `live` counts every item a current revision holds, nested ones included;
   * `published` those whose own status is published; `hosts` the entities
   * holding at least one.
   *
   * @return array<string, array{live: int, published: int, hosts: int, host_bundles: list<string>}>
   */
  public function count(array $entities): array {
    $counts = [];
    $walk = function (array $items, string $hostKey, string $hostBundle) use (&$walk, &$counts): void {
      foreach ($items as $item) {
        if (!empty($item['missing'])) {
          continue;
        }
        $bundle = $item['bundle'];
        $counts[$bundle] ??= ['live' => 0, 'published' => 0, 'hosts' => [], 'host_bundles' => []];
        $counts[$bundle]['live']++;
        $counts[$bundle]['published'] += $item['status'] ? 1 : 0;
        $counts[$bundle]['hosts'][$hostKey] = TRUE;
        $counts[$bundle]['host_bundles'][$hostBundle] = TRUE;
        foreach ($item['fields'] as $field) {
          if (isset($field['children'])) {
            $walk($field['children'], $hostKey, $hostBundle);
          }
        }
      }
    };
    foreach ($entities as $entity) {
      foreach ($entity['trees'] as $tree) {
        $walk($tree, $entity['entity_type'] . ':' . $entity['id'], $entity['bundle']);
      }
    }
    foreach ($counts as &$count) {
      $count['hosts'] = count($count['hosts']);
      $count['host_bundles'] = array_keys($count['host_bundles']);
      sort($count['host_bundles']);
    }
    ksort($counts);
    return $counts;
  }

  /**
   * The host entity itself: identity, URL, and its other field values.
   *
   * @param list<string> $treeFields
   *   Fields that hold trees; recorded under `trees`, not `fields`.
   */
  private function describe(ContentEntityInterface $entity, array $treeFields): array {
    $path = NULL;
    $alias = NULL;
    if ($entity->hasLinkTemplate('canonical')) {
      $path = '/' . $entity->toUrl()->getInternalPath();
      $alias = $this->aliasManager->getAliasByPath($path, $entity->language()->getId());
    }
    $fields = [];
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if ($definition->getFieldStorageDefinition()->isBaseField() || in_array($name, $treeFields, TRUE)) {
        continue;
      }
      $fields[$name] = $this->normalizer->normalize($entity->get($name));
    }
    ksort($fields);
    return [
      'entity_type' => $entity->getEntityTypeId(),
      'id' => (int) $entity->id(),
      'bundle' => $entity->bundle(),
      'label' => (string) $entity->label(),
      'langcode' => $entity->language()->getId(),
      'status' => $entity instanceof EntityPublishedInterface ? $entity->isPublished() : TRUE,
      'changed' => $entity instanceof EntityChangedInterface ? $entity->getChangedTime() : NULL,
      'revision' => $entity->getEntityType()->isRevisionable() ? (int) $entity->getRevisionId() : NULL,
      'path' => $path,
      'alias' => $alias,
      'fields' => $fields,
      'trees' => [],
    ];
  }

}
