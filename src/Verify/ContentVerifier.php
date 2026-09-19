<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Verify;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\neo_migrate\Content\ContentMapping;
use Drupal\neo_migrate\Content\MarkupRewriter;
use Drupal\neo_migrate\Content\TreeConverter;
use Drupal\neo_migrate\Content\ValueTransformer;
use Drupal\neo_migrate\FieldNormalizer;
use Drupal\neo_migrate\Source\SourceAdapterInterface;
use Drupal\path_alias\AliasManagerInterface;

/**
 * Checks converted hosts against their legacy trees and the inventory.
 *
 * Read-only. Each host is checked three ways:
 * - Against the inventory: the entity still exists with the same label,
 *   status, URL, changed time and other field values — the conversion must
 *   not have touched them. A host edited since the inventory was taken
 *   reports these as warnings rather than errors.
 * - Against its legacy tree: one component per legacy item, in the same
 *   order and with the same status, and every prop the mapping fills holding
 *   what the legacy field held, read back through the component the way it
 *   renders — text by its visible words, images by file and alt text, links
 *   by title and target, webforms by id (which must exist).
 * - Against the component: a content prop the mapping leaves unset shows the
 *   component's example, which is reported unless a value provider fills it.
 *
 * The checks are written from the mapping, not with the converter, so a
 * converter bug shows up here instead of being repeated.
 */
final class ContentVerifier {

  /**
   * Prop refs that carry content: unset, they show the component's example.
   */
  private const CONTENT_REFS = [
    'markup', 'string', 'heading', 'link', 'url', 'image', 'media', 'file',
    'video', 'remote_video', 'array', 'icon', 'telephone', 'email', 'uri',
    'integer', 'number',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SourceAdapterInterface $source,
    private readonly TreeConverter $converter,
    private readonly FieldNormalizer $normalizer,
    private readonly AliasManagerInterface $aliasManager,
    private readonly KeyValueFactoryInterface $keyValue,
    private readonly RendererInterface $renderer,
    private readonly ?PluginManagerInterface $valueManager = NULL,
  ) {}

  /**
   * Checks every host the mapping converts.
   *
   * @param \Drupal\neo_migrate\Content\ContentMapping $mapping
   *   The mapping file the hosts were converted with.
   * @param array $inventory
   *   The inventory taken before conversion (neo-migrate:inventory).
   * @param list<string>|null $ids
   *   Only these host ids.
   *
   * @return list<array{entity_type: string, id: string, label: string, expected: int, found: int, findings: list<array{level: string, message: string}>}>
   */
  public function verify(ContentMapping $mapping, array $inventory, ?array $ids = NULL): array {
    $recorded = [];
    foreach ($inventory['entities'] ?? [] as $entry) {
      $recorded[$entry['entity_type'] . ':' . $entry['id']] = $entry;
    }
    $results = [];
    foreach ($mapping->hosts() as $host) {
      $storage = $this->entityTypeManager->getStorage($host['entity_type']);
      $definition = $storage->getEntityType();
      $query = $storage->getQuery()->accessCheck(FALSE);
      $bundles = [];
      foreach ($this->source->hosts() as $known) {
        if ($known['entity_type'] === $host['entity_type'] && $known['field'] === $host['field']) {
          $bundles = $known['bundles'];
        }
      }
      if ($bundles && $definition->hasKey('bundle')) {
        $query->condition($definition->getKey('bundle'), $bundles, 'IN');
      }
      $keys = array_map(static fn ($id) => $host['entity_type'] . ':' . $id, $query->execute());
      // Hosts the inventory holds but the site no longer does are checked too.
      foreach ($recorded as $key => $entry) {
        if ($entry['entity_type'] === $host['entity_type'] && isset($entry['trees'][$host['field']])) {
          $keys[] = $key;
        }
      }
      $keys = array_unique($keys);
      natsort($keys);
      foreach ($keys as $key) {
        [, $id] = explode(':', $key, 2);
        if ($ids !== NULL && !in_array($id, $ids, TRUE)) {
          continue;
        }
        $entity = $storage->load($id);
        $results[] = $this->verifyHost($entity instanceof ContentEntityInterface ? $entity : NULL, $recorded[$key] ?? NULL, $host, $mapping, $host['entity_type'], (string) $id);
      }
    }
    return $results;
  }

  /**
   * Checks one host.
   */
  private function verifyHost(?ContentEntityInterface $entity, ?array $recorded, array $host, ContentMapping $mapping, string $entityTypeId, string $id): array {
    $result = [
      'entity_type' => $entityTypeId,
      'id' => $id,
      'label' => $entity ? (string) $entity->label() : (string) ($recorded['label'] ?? ''),
      'expected' => 0,
      'found' => 0,
      'findings' => [],
    ];
    $error = static function (string $message) use (&$result): void {
      $result['findings'][] = ['level' => 'error', 'message' => $message];
    };
    $warning = static function (string $message) use (&$result): void {
      $result['findings'][] = ['level' => 'warning', 'message' => $message];
    };
    if (!$entity) {
      $error('In the inventory, but no longer exists.');
      return $result;
    }

    $legacy = $this->source->tree($entity, $host['field']);
    $this->checkIdentity($entity, $recorded, $legacy, $host['field'], $error, $warning);

    // The conversion record: written by neo-migrate:content on each save.
    $record = $this->keyValue->get('neo_migrate.content')->get($entityTypeId . ':' . $id);
    $list = $entity->get($host['target']);
    if (!$record) {
      $error('Never converted: run neo-migrate:content.');
    }
    else {
      if (($record['source'] ?? NULL) !== $this->source->fingerprint($legacy)) {
        $error('The legacy tree changed after it was converted: run neo-migrate:content again.');
      }
      if (($record['target'] ?? NULL) !== sha1(json_encode($list->getValue()))) {
        $warning(sprintf('%s was edited after the conversion (the checks below compare it with the legacy tree).', $host['target']));
      }
    }

    // What the tree should hold: the prepended components, then one per
    // mapped legacy item, in order.
    $expected = [];
    foreach ($mapping->prepend($entityTypeId, $id) as $entry) {
      $uuid = TreeConverter::derivedUuid(sprintf('%s:%s:%s', $entityTypeId, $id, $entry['component']));
      $expected[$uuid] = ['label' => "prepended {$entry['component']}", 'entry' => $entry, 'item' => NULL, 'status' => TRUE];
    }
    foreach ($legacy as $position => $item) {
      if (!empty($item['missing'])) {
        $error(sprintf('Legacy item %d points at a missing revision (%s).', $position, $item['target_revision_id'] ?? '?'));
        continue;
      }
      $label = sprintf('%s %d', $item['bundle'], $item['id']);
      $entry = $mapping->item($item['bundle']);
      if ($entry === NULL) {
        $error("$label: no mapping for \"{$item['bundle']}\".");
        continue;
      }
      if (!empty($entry['skip'])) {
        continue;
      }
      if (!empty($item['behavior'])) {
        $warning("$label: its behavior settings are not carried over (" . implode(', ', array_keys($item['behavior'])) . ').');
      }
      $expected[$item['uuid']] = ['label' => "$label → {$entry['component']}", 'entry' => $entry, 'item' => $item, 'status' => (bool) $item['status']];
    }
    $result['expected'] = count($expected);

    /** @var \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem|null $tree */
    $tree = $list->isEmpty() ? NULL : $list->first();
    if (!$tree) {
      if ($expected) {
        $error(sprintf('%s is empty; %d components expected.', $host['target'], count($expected)));
      }
      return $result;
    }
    if ($tree->hasDraft()) {
      $warning('Has an unpublished Alchemist draft.');
    }
    // The field stores its tree and props as JSON.
    $raw = array_map(static fn ($value) => is_string($value) ? (json_decode($value, TRUE) ?? []) : $value, $tree->getValue());
    $components = [];
    foreach ($raw['tree'] ?? [] as $children) {
      foreach ($children as $child) {
        $components[$child['uuid']] = $child['component'];
      }
    }
    $placed = $tree->getPlacedUuids();
    $result['found'] = count($placed);

    foreach (array_diff(array_keys($expected), $placed) as $uuid) {
      $error("{$expected[$uuid]['label']}: missing from the tree.");
    }
    foreach (array_diff($placed, array_keys($expected)) as $uuid) {
      $warning(sprintf('%s %s: not from the legacy tree.', $components[$uuid] ?? 'component', $uuid));
    }
    $common = array_values(array_intersect($placed, array_keys($expected)));
    if ($common !== array_values(array_intersect(array_keys($expected), $placed))) {
      $error('The components are not in the legacy order.');
    }

    $this->renderer->executeInRenderContext(new RenderContext(), function () use ($tree, $raw, $components, $common, $expected, $mapping, $entity, $error, $warning) {
      foreach ($common as $uuid) {
        $want = $expected[$uuid];
        $component = $want['entry']['component'];
        $label = $want['label'];
        if (($components[$uuid] ?? NULL) !== $component) {
          $error(sprintf('%s: is a %s.', $label, $components[$uuid] ?? '?'));
          continue;
        }
        if ((bool) ($raw['props'][$uuid]['status'] ?? TRUE) !== $want['status']) {
          $error(sprintf('%s: is %s; the legacy item was %s.', $label, $want['status'] ? 'hidden' : 'shown', $want['status'] ? 'published' : 'unpublished'));
        }
        $instance = $tree->getComponent($uuid);
        if (!$instance) {
          $error("$label: does not load.");
          continue;
        }
        $values = $instance->getPropValues();
        $specs = ($want['entry']['props'] ?? []) + $mapping->bundleProps($entity->bundle());
        if ($want['item'] !== NULL) {
          foreach ($want['entry']['props'] ?? [] as $name => $spec) {
            foreach ($this->checkProp($name, $spec, $want['item'], $values[$name] ?? NULL, $mapping) as $problem) {
              $error("$label: $problem");
            }
          }
          $filters = $instance->getFilters();
          foreach ($want['entry']['filters'] ?? [] as $title => $spec) {
            foreach ($this->checkFilter((string) $title, $spec, $component, $want['item'], $filters) as $problem) {
              $error("$label: $problem");
            }
          }
          foreach ($this->uncovered($want['entry'], $want['item']) as $field) {
            $error("$label: $field holds a value no prop takes.");
          }
        }
        foreach ($this->examples($component, $specs + ($raw['props'][$uuid]['props'] ?? []), $values) as $name) {
          $error("$label: prop \"$name\" shows the component's example; nothing was written to it.");
        }
      }
    });
    return $result;
  }

  /**
   * The host itself against the inventory: nothing but the tree may change.
   */
  private function checkIdentity(ContentEntityInterface $entity, ?array $recorded, array $legacy, string $treeField, callable $error, callable $warning): void {
    if ($recorded === NULL) {
      $warning('Not in the inventory (created after it was taken).');
      return;
    }
    $changed = $entity instanceof EntityChangedInterface ? $entity->getChangedTime() : NULL;
    $edited = $changed !== NULL && (int) $changed !== (int) $recorded['changed'];
    // An editor's own change since the inventory is not the conversion's.
    $report = $edited ? $warning : $error;
    if ($edited) {
      $warning(sprintf('Edited since the inventory (changed %s → %s).', date('Y-m-d H:i', (int) $recorded['changed']), date('Y-m-d H:i', (int) $changed)));
    }
    if ((string) $entity->label() !== (string) $recorded['label']) {
      $report(sprintf('Label is "%s"; the inventory has "%s".', $entity->label(), $recorded['label']));
    }
    $published = $entity instanceof EntityPublishedInterface ? $entity->isPublished() : TRUE;
    if ($published !== (bool) $recorded['status']) {
      $report(sprintf('Is %s; the inventory has it %s.', $published ? 'published' : 'unpublished', $recorded['status'] ? 'published' : 'unpublished'));
    }
    if ($recorded['path'] !== NULL) {
      $alias = $this->aliasManager->getAliasByPath($recorded['path'], $entity->language()->getId());
      if ($alias !== $recorded['alias']) {
        $report(sprintf('URL is %s; the inventory has %s.', $alias, $recorded['alias']));
      }
    }
    foreach ($recorded['fields'] as $name => $value) {
      if (!$entity->hasField($name)) {
        $report("Field $name is gone.");
        continue;
      }
      if (self::roundTrip($this->normalizer->normalize($entity->get($name))) != $value && !$this->sameOnceSaved($entity, $name, $value)) {
        $report("Field $name changed since the inventory.");
      }
    }
    if (isset($recorded['trees'][$treeField]) && $this->source->fingerprint(self::roundTrip($legacy)) !== $this->source->fingerprint($recorded['trees'][$treeField])) {
      $warning('The legacy tree changed since the inventory.');
    }
  }

  /**
   * Whether a recorded field value is the current one once saved.
   *
   * Saving drops the items a field considers empty — metatag's `[]`, say —
   * so a host saved by the conversion can lose an item that held nothing.
   * The recorded items are loaded into a copy of the field, emptied the way
   * a save empties them, and compared again.
   */
  private function sameOnceSaved(ContentEntityInterface $entity, string $name, array $recorded): bool {
    $extras = array_flip(['plain', 'sha1', 'uri', 'filename', 'target_type', 'label']);
    $list = clone $entity->get($name);
    $list->setValue(array_map(static fn ($item) => array_diff_key($item, $extras), $recorded['items'] ?? []), FALSE);
    $list->filterEmptyItems();
    return self::roundTrip($this->normalizer->normalize($list)) == self::roundTrip($this->normalizer->normalize($entity->get($name)));
  }

  /**
   * One prop against the legacy field(s) its mapping entry reads.
   *
   * @return list<string>
   *   Problems, empty when the prop holds what the legacy item did.
   */
  private function checkProp(string $name, array $spec, array $item, mixed $got, ContentMapping $mapping): array {
    if (array_key_exists('value', $spec)) {
      $want = $spec['value'];
      return is_scalar($want) && self::text($got) !== (string) $want ? [sprintf('prop "%s" is %s, not the fixed %s.', $name, self::show($got), $want)] : [];
    }
    $transform = $spec['transform'] ?? 'string';
    if ($transform === 'heading') {
      $problems = [];
      $empty = TRUE;
      foreach (ValueTransformer::HEADING_PARTS as $part) {
        $want = isset($spec[$part]) ? trim((string) ($item['fields'][$spec[$part]]['items'][0]['value'] ?? '')) : '';
        $empty = $empty && $want === '';
        $read = is_array($got) ? MarkupRewriter::text((string) ($got[$part] ?? '')) : '';
        if ($read !== MarkupRewriter::text($want)) {
          $problems[] = sprintf('prop "%s" %s reads %s; the legacy item has %s.', $name, $part, self::show($read), self::show($want));
        }
      }
      return $empty ? $this->hidden($name, $got) : $problems;
    }
    if ($transform === 'each') {
      return $this->checkEach($name, $spec, $item, $got, $mapping);
    }
    $field = $item['fields'][$spec['from'] ?? ''] ?? NULL;
    if ($field === NULL) {
      return [sprintf('prop "%s": the legacy item has no field %s.', $name, $spec['from'] ?? '?')];
    }
    $first = $field['items'][0] ?? NULL;
    switch ($transform) {
      case 'markup':
      case 'string':
      case 'wrap':
        $want = (string) ($first['value'] ?? '');
        if (trim($want) === '') {
          return $this->hidden($name, $got);
        }
        // A string is compared as it is; rich text by its visible words.
        [$read, $want] = match ($transform) {
          'string' => [trim(self::text($got)), trim($want)],
          'wrap' => [MarkupRewriter::text(self::text($got)), MarkupRewriter::text(htmlspecialchars(trim($want), ENT_QUOTES))],
          default => [MarkupRewriter::text(self::text($got)), MarkupRewriter::text($want)],
        };
        return $read === $want ? [] : [sprintf('prop "%s" reads %s; the legacy item has %s.', $name, self::show($read), self::show($want))];

      case 'flag':
        $want = isset($spec['when']) ? (string) ($first['value'] ?? '') === (string) $spec['when'] : !empty($first['value']);
        return (bool) $got === $want ? [] : [sprintf('prop "%s" is %s; the legacy item says %s.', $name, self::show((bool) $got), self::show($want))];

      case 'number':
        $key = $spec['key'] ?? 'value';
        if (!isset($first[$key]) || $first[$key] === '') {
          return $this->hidden($name, $got);
        }
        $want = round((float) $first[$key] / (float) ($spec['divide'] ?? 1), (int) ($spec['precision'] ?? 0));
        return is_numeric($got) && abs((float) $got - $want) < 1e-9 ? [] : [sprintf('prop "%s" is %s; the legacy item gives %s.', $name, self::show($got), $want)];

      case 'link':
        if (empty($first['uri'])) {
          return $this->hidden($name, $got);
        }
        if (!is_array($got) || empty($got['uri'])) {
          return [sprintf('prop "%s" has no link; the legacy item links to %s.', $name, $first['uri'])];
        }
        $problems = [];
        if (trim((string) ($got['title'] ?? '')) !== trim((string) ($first['title'] ?? ''))) {
          $problems[] = sprintf('prop "%s" link text reads %s; the legacy item has %s.', $name, self::show($got['title'] ?? ''), self::show($first['title'] ?? ''));
        }
        // Both sides as the address the visitor follows.
        try {
          $want = Url::fromUri($first['uri'])->toString();
        }
        catch (\InvalidArgumentException) {
          $want = $first['uri'];
        }
        if ((string) $got['uri'] !== (string) $want) {
          $problems[] = sprintf('prop "%s" links to %s; the legacy item links to %s.', $name, self::show($got['uri']), self::show($want));
        }
        return $problems;

      case 'image_media':
        if (isset($spec['as'])) {
          $want = array_values(array_filter($field['items'] ?? [], static fn ($value) => !empty($value['target_id'])));
          if (!$want) {
            return $this->hidden($name, $got);
          }
          $got = is_array($got) ? array_values($got) : [];
          if (count($got) !== count($want)) {
            return [sprintf('prop "%s" holds %d images; the legacy item has %d.', $name, count($got), count($want))];
          }
          $problems = [];
          foreach ($want as $delta => $value) {
            $problems = array_merge($problems, $this->checkImage("$name $delta", $value, $got[$delta][$spec['as']] ?? NULL, $mapping));
          }
          return $problems;
        }
        return empty($first['target_id']) ? $this->hidden($name, $got) : $this->checkImage($name, $first, $got, $mapping);

      default:
        return [sprintf('prop "%s": verify does not know the transform "%s".', $name, $transform)];
    }
  }

  /**
   * An array prop built from nested items, entry by entry.
   */
  private function checkEach(string $name, array $spec, array $item, mixed $got, ContentMapping $mapping): array {
    $children = ($spec['from'] ?? NULL) === '@self' ? [$item] : ($item['fields'][$spec['from'] ?? '']['children'] ?? []);
    $problems = [];
    $want = [];
    foreach ($children as $child) {
      if (!empty($child['missing'])) {
        $problems[] = sprintf('prop "%s": a nested item points at a missing revision.', $name);
      }
      elseif (empty($child['status'])) {
        $problems[] = sprintf('prop "%s": nested %s %d is unpublished and was left out.', $name, $child['bundle'], $child['id']);
      }
      else {
        $want[] = $child;
      }
    }
    if (!$want) {
      return array_merge($problems, $this->hidden($name, $got));
    }
    $got = is_array($got) ? array_values($got) : [];
    if (count($got) !== count($want)) {
      return array_merge($problems, [sprintf('prop "%s" holds %d entries; the legacy item has %d.', $name, count($got), count($want))]);
    }
    foreach ($want as $delta => $child) {
      foreach ($spec['props'] ?? [] as $sub => $subSpec) {
        foreach ($this->checkProp("$name $delta $sub", $subSpec, $child, $got[$delta][$sub] ?? NULL, $mapping) as $problem) {
          $problems[] = $problem;
        }
      }
      foreach ($this->uncovered($spec, $child) as $field) {
        $problems[] = sprintf('prop "%s" entry %d: %s holds a value no prop takes.', $name, $delta, $field);
      }
    }
    return $problems;
  }

  /**
   * An image prop: the media behind it holds the legacy file and alt text.
   */
  private function checkImage(string $name, array $legacy, mixed $got, ContentMapping $mapping): array {
    $mid = is_array($got) ? ($got['target_id'] ?? $got['entity_id'] ?? NULL) : NULL;
    if (!$mid) {
      return [sprintf('prop "%s" has no media; the legacy item has %s.', $name, $legacy['filename'] ?? 'an image')];
    }
    ['field' => $sourceField] = $mapping->media('image');
    $media = $this->entityTypeManager->getStorage('media')->load($mid);
    if (!$media || !$media->hasField($sourceField)) {
      return [sprintf('prop "%s" points at media %s, which does not exist.', $name, $mid)];
    }
    $source = $media->get($sourceField)->first();
    $problems = [];
    if ((string) ($source?->target_id) !== (string) $legacy['target_id']) {
      $problems[] = sprintf('prop "%s" shows file %s; the legacy item has file %s.', $name, $source?->target_id ?? 'none', $legacy['target_id']);
    }
    $alt = (string) ($legacy['alt'] ?? '');
    if ((string) ($source?->alt) !== $alt || (string) ($got['alt'] ?? '') !== $alt) {
      $problems[] = sprintf('prop "%s" alt text is %s; the legacy item has %s.', $name, self::show($got['alt'] ?? ''), self::show($alt));
    }
    $file = $source?->entity;
    if ($file && !file_exists($file->getFileUri())) {
      $problems[] = sprintf('prop "%s": %s is missing on disk.', $name, $file->getFileUri());
    }
    return $problems;
  }

  /**
   * A component filter filled from a legacy reference (a webform, say).
   */
  private function checkFilter(string $title, array $spec, string $component, array $item, array $filters): array {
    try {
      $uuid = $this->converter->filterUuid($component, $title);
    }
    catch (\RuntimeException $e) {
      return [$e->getMessage()];
    }
    $got = ($filters[$uuid] ?? NULL)?->getValue();
    $first = $item['fields'][$spec['from'] ?? '']['items'][0] ?? NULL;
    if (empty($first['target_id'])) {
      return in_array($got, [NULL, ''], TRUE) ? [] : [sprintf('filter "%s" is %s; the legacy item picks none.', $title, self::show($got))];
    }
    if ((string) $got !== (string) $first['target_id']) {
      return [sprintf('filter "%s" is %s; the legacy item picks %s.', $title, self::show($got), $first['target_id'])];
    }
    $type = $first['target_type'] ?? NULL;
    if ($type === NULL || !$this->entityTypeManager->getStorage($type)->load($first['target_id'])) {
      return [sprintf('filter "%s" picks %s, which does not exist.', $title, $first['target_id'])];
    }
    return [];
  }

  /**
   * A prop whose legacy field is empty must show nothing.
   */
  private function hidden(string $name, mixed $got): array {
    return in_array($got, [NULL, '', []], TRUE) || (is_string($got) && trim($got) === '')
      ? []
      : [sprintf('prop "%s" shows %s, but the legacy field is empty.', $name, self::show($got))];
  }

  /**
   * Filled legacy fields that neither a prop, a filter nor `ignore` takes.
   *
   * @return list<string>
   */
  private function uncovered(array $entry, array $item): array {
    $used = $entry['ignore'] ?? [];
    foreach (array_merge(array_values($entry['props'] ?? []), array_values($entry['filters'] ?? [])) as $spec) {
      $used = array_merge($used, ValueTransformer::fields($spec));
    }
    if (($entry['from'] ?? NULL) === '@self') {
      $used = array_merge($used, ValueTransformer::fields($entry));
    }
    $uncovered = [];
    foreach ($item['fields'] as $name => $field) {
      if ((!empty($field['items']) || !empty($field['children'])) && !in_array($name, $used, TRUE)) {
        $uncovered[] = $name;
      }
    }
    return $uncovered;
  }

  /**
   * Content props left unset that show the component's example.
   *
   * Unset means neither the mapping nor the stored tree gives the prop a
   * value. A prop filled by a value provider (a view, the page title, a site
   * setting) is not unset; nor is a choice (an enum) or a switch.
   *
   * @param string $component
   *   The neo_component id.
   * @param array $set
   *   Props the mapping or the stored tree sets, keyed by name.
   * @param array $values
   *   The component's resolved prop values.
   *
   * @return list<string>
   */
  private function examples(string $component, array $set, array $values): array {
    $entity = $this->entityTypeManager->getStorage('neo_component')->load($component);
    $schema = json_decode((string) $entity?->get('schema'), TRUE) ?? [];
    $settings = $entity?->get('settings')['props'] ?? [];
    $names = [];
    foreach ($schema['properties'] ?? [] as $name => $property) {
      $types = (array) ($property['type'] ?? 'string');
      $ref = $property['ref'] ?? reset($types);
      if (isset($set[$name]) || !in_array($ref, self::CONTENT_REFS, TRUE) || isset($property['enum']) || $this->provided($settings[$name] ?? [])) {
        continue;
      }
      if (!in_array($values[$name] ?? NULL, [NULL, '', []], TRUE)) {
        $names[] = $name;
      }
    }
    return $names;
  }

  /**
   * Whether a value provider fills a prop (the media picker does not count).
   */
  private function provided(array $settings): bool {
    foreach ($settings['plugins'] ?? [] as $plugins) {
      foreach (array_keys((array) $plugins) as $pluginId) {
        $definition = $this->valueManager?->getDefinition($pluginId, FALSE);
        if ($pluginId !== 'media' && ($definition['group'] ?? NULL) === 'providers') {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * A resolved value as text: markup and strings as they are.
   */
  private static function text(mixed $value): string {
    return is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
  }

  /**
   * A value, short, for a message.
   */
  private static function show(mixed $value): string {
    $text = is_bool($value) ? ($value ? 'on' : 'off') : (is_scalar($value) || $value instanceof \Stringable ? '"' . $value . '"' : json_encode($value));
    return mb_strlen((string) $text) > 70 ? mb_substr((string) $text, 0, 67) . '…"' : (string) $text;
  }

  /**
   * A value as it comes back from the inventory's JSON.
   */
  private static function roundTrip(array $value): array {
    return json_decode(json_encode($value), TRUE);
  }

}
