<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Content;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Turns a legacy tree into component instances, following the mapping file.
 *
 * Nothing is written here. Each instance keeps the legacy item's UUID, so a
 * repeated conversion produces the same tree and a component can always be
 * traced to the item it came from.
 */
final class TreeConverter {

  /**
   * Prop refs whose shapes are filled from the media library.
   */
  public const MEDIA_REFS = ['image', 'media', 'file', 'video', 'remote_video'];

  /**
   * Prop refs by component id and prop name.
   *
   * @var array<string, array<string, string>>
   */
  private array $refs = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ValueTransformer $transformer,
  ) {}

  /**
   * Converts one tree.
   *
   * @return array{instances: list<array>, problems: list<string>, skipped: list<string>}
   *   Instances are `{uuid, component, status, props, source}`, where props
   *   are in the stored wrapper format (`{ref, value}`). A problem means the
   *   tree must not be written; skipped items were left out on purpose.
   */
  public function convert(array $tree, ContentMapping $mapping, ContentEntityInterface $host, bool $skipUnmapped = FALSE): array {
    $result = ['instances' => [], 'problems' => [], 'skipped' => []];
    foreach ($mapping->prepend($host->getEntityTypeId(), $host->id()) as $entry) {
      // Nothing in the legacy tree to take a UUID from: derive a stable one
      // from the host and the component instead.
      $item = [
        'bundle' => 'prepend',
        'id' => 0,
        'uuid' => self::derivedUuid(sprintf('%s:%s:%s', $host->getEntityTypeId(), $host->id(), $entry['component'])),
        'status' => TRUE,
        'fields' => [],
      ];
      try {
        $result['instances'][] = $this->instance($item, $entry, $mapping, $host->bundle());
      }
      catch (\RuntimeException $e) {
        $result['problems'][] = "prepend {$entry['component']}: {$e->getMessage()}";
      }
    }
    foreach ($tree as $position => $item) {
      if (!empty($item['missing'])) {
        $result['problems'][] = sprintf('Item %d points at a missing revision (%s).', $position, $item['target_revision_id'] ?? '?');
        continue;
      }
      $label = sprintf('%s %d', $item['bundle'], $item['id']);
      $entry = $mapping->item($item['bundle']);
      if ($entry === NULL) {
        if ($skipUnmapped || $mapping->unmapped() === 'skip') {
          $result['skipped'][] = "$label (unmapped)";
        }
        else {
          $result['problems'][] = "$label: no mapping for \"{$item['bundle']}\".";
        }
        continue;
      }
      if (!empty($entry['skip'])) {
        $result['skipped'][] = $label;
        continue;
      }
      try {
        $result['instances'][] = $this->instance($item, $entry, $mapping, $host->bundle());
      }
      catch (\RuntimeException $e) {
        $result['problems'][] = "$label: {$e->getMessage()}";
      }
    }
    return $result;
  }

  /**
   * One component instance from one legacy item.
   */
  private function instance(array $item, array $entry, ContentMapping $mapping, string $hostBundle = ''): array {
    $component = $entry['component'];
    $refs = $this->refs($component);
    $props = [];
    $specs = $entry['props'] ?? [];
    foreach ($mapping->bundleProps($hostBundle) as $name => $spec) {
      if (isset($refs[$name]) && !isset($specs[$name])) {
        $specs[$name] = $spec;
      }
    }
    foreach ($specs as $name => $spec) {
      if (!isset($refs[$name])) {
        throw new \RuntimeException("$component has no prop \"$name\".");
      }
      $value = $this->transformer->transform($spec, $item, $mapping);
      if ($value === NULL) {
        // An unset prop renders the component's example, so an empty legacy
        // field becomes a hidden prop: the editor's "Hide".
        $props[$name] = ['ref' => $refs[$name], 'value' => [], 'options' => [$name => ['empty' => TRUE, 'default' => FALSE]]];
        continue;
      }
      $props[$name] = ['ref' => $refs[$name], 'value' => $value];
      // Media props start out showing their default instead of the stored
      // value, and a heading's parts fall back to their examples: a converted
      // value switches both off to be what is seen.
      if (in_array($refs[$name], self::MEDIA_REFS, TRUE)) {
        $props[$name]['options'] = [$name => ['empty' => FALSE, 'default' => FALSE]];
      }
      // The same inside an array, where each entry's props are keyed
      // `<array>~<prop>~<delta>`: nested media otherwise show their default.
      if ($refs[$name] === 'array' && is_array($value)) {
        $props[$name]['options'] = [$name => ['default' => FALSE]];
        foreach (array_values($value) as $delta => $row) {
          foreach (array_keys((array) $row) as $key) {
            $props[$name]['options']["$name~$key~$delta"] = ['default' => FALSE, 'empty' => FALSE];
          }
        }
      }
      if ($refs[$name] === 'heading') {
        $props[$name]['options'] = [$name => ['default' => FALSE, 'empty' => FALSE]];
        foreach (ValueTransformer::HEADING_PARTS as $part) {
          $props[$name]['options']["$name~$part"] = ['default' => FALSE, 'empty' => ($value[$part]['value'] ?? '') === ''];
        }
      }
      // Any other written value is the value, not a fallback to the default.
      $props[$name]['options'] ??= [$name => ['default' => FALSE]];
    }
    // Nothing is dropped silently: a field holding a value must feed a prop
    // or be listed under `ignore`.
    $used = $entry['ignore'] ?? [];
    foreach ($entry['props'] ?? [] as $spec) {
      $used = array_merge($used, ValueTransformer::fields($spec));
    }
    foreach ($item['fields'] as $fieldName => $field) {
      $filled = !empty($field['items']) || !empty($field['children']);
      if ($filled && !in_array($fieldName, $used, TRUE)) {
        throw new \RuntimeException("$fieldName has a value that no prop takes (map it, or list it under ignore).");
      }
    }
    return [
      'uuid' => $item['uuid'],
      'component' => $component,
      'status' => (bool) $item['status'],
      'props' => $props,
      'source' => ['bundle' => $item['bundle'], 'id' => $item['id'], 'spec' => $entry],
    ];
  }

  /**
   * A name-based UUID (version 5 layout), the same for the same name.
   */
  public static function derivedUuid(string $name): string {
    $hash = sha1('neo_migrate:' . $name);
    return sprintf('%s-%s-5%s-%x%s-%s',
      substr($hash, 0, 8),
      substr($hash, 8, 4),
      substr($hash, 13, 3),
      (hexdec(substr($hash, 16, 1)) & 0x3) | 0x8,
      substr($hash, 17, 3),
      substr($hash, 20, 12),
    );
  }

  /**
   * The ref of each of a component's props, from its stored schema.
   *
   * @return array<string, string>
   */
  public function refs(string $component): array {
    if (!isset($this->refs[$component])) {
      $entity = $this->entityTypeManager->getStorage('neo_component')->load($component);
      if (!$entity) {
        throw new \RuntimeException("No neo_component \"$component\".");
      }
      $schema = json_decode((string) $entity->get('schema'), TRUE) ?? [];
      $this->refs[$component] = [];
      foreach ($schema['properties'] ?? [] as $name => $property) {
        $types = (array) ($property['type'] ?? 'string');
        $this->refs[$component][$name] = $property['ref'] ?? reset($types);
      }
    }
    return $this->refs[$component];
  }

}
