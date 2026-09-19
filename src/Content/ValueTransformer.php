<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Content;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Turns one legacy field value into one prop value.
 *
 * A prop's entry in the mapping file names the legacy field (`from`) and how
 * to read it (`transform`), or gives a fixed `value`. The result is the prop's
 * stored value — what goes under `value` in the component's props — or NULL
 * when the legacy field is empty, which leaves the prop unset.
 *
 * Transforms:
 * - `string`: the first value, trimmed.
 * - `markup`: rich text, rewritten by the mapping's markup rules.
 * - `flag`: TRUE when the first value equals `when` (or, without `when`, is
 *   truthy).
 * - `image_media`: an image field's file, as an image media entity. A media
 *   entity already holding the same file with the same alt text is reused,
 *   so a file used twice becomes one library item. With `as: <key>`, every
 *   value of the field, as an array prop's entries `{<key>: <media>}`.
 * - `link`: a link field's first value: uri, title and options.
 * - `wrap`: a plain value as rich text inside one tag: `{transform: wrap,
 *   from: field_title, tag: h2}` gives `<h2>…</h2>` in the mapping's format.
 * - `target`: an entity reference's target id, as a component filter value
 *   (a webform picked per instance, say).
 * - `number`: a number from the first value's `key` (default `value`),
 *   divided by `divide` and rounded: `{transform: number, key: rating,
 *   divide: 20}` turns a 0–100 rating into 0–5 stars.
 * - `heading`: a heading built from several fields, named per part:
 *   `{transform: heading, supertitle: field_a, title: field_b}`.
 * - `each`: nested items (a paragraphs field) as an array prop, one entry
 *   per published item, each built from its own `props:` mapping:
 *   `{from: field_items, transform: each, bundle: item, props: {…}}`.
 *   The nested items obey the same rule as their parent: a filled field no
 *   prop takes is an error unless listed under `ignore:`.
 */
final class ValueTransformer {

  /**
   * The text parts of a heading prop.
   */
  public const HEADING_PARTS = ['supertitle', 'title', 'subtitle'];

  public function __construct(
    private readonly MarkupRewriter $markup,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The prop value for one mapping entry and one legacy item.
   *
   * @param array $spec
   *   The prop's mapping entry.
   * @param array $item
   *   The legacy item, as the source adapter reads it.
   * @param \Drupal\neo_migrate\Content\ContentMapping $mapping
   *   The mapping file.
   */
  public function transform(array $spec, array $item, ContentMapping $mapping): mixed {
    if (array_key_exists('value', $spec)) {
      // A fixed value; a scalar is a single-value field item.
      return is_array($spec['value']) ? $spec['value'] : ['value' => $spec['value']];
    }
    if (($spec['transform'] ?? NULL) === 'heading') {
      return $this->heading($spec, $item);
    }
    $field = $item['fields'][$spec['from'] ?? ''] ?? NULL;
    if ($field === NULL) {
      throw new \RuntimeException(sprintf('%s has no field "%s".', $item['bundle'], $spec['from'] ?? ''));
    }
    if (($spec['transform'] ?? NULL) === 'each') {
      return $this->each($spec, $field['children'] ?? [], $mapping);
    }
    $first = $field['items'][0] ?? NULL;
    return match ($spec['transform'] ?? 'string') {
      'markup' => $this->markup($first, $mapping),
      'string' => $first === NULL || trim((string) ($first['value'] ?? '')) === '' ? NULL : ['value' => trim((string) $first['value'])],
      'flag' => ['value' => isset($spec['when']) ? (string) ($first['value'] ?? '') === (string) $spec['when'] : !empty($first['value'])],
      'image_media' => isset($spec['as'])
        ? (array_values(array_filter(array_map(fn ($value) => ($media = $this->imageMedia($value, $mapping)) ? [$spec['as'] => $media] : NULL, $field['items'] ?? []))) ?: NULL)
        : $this->imageMedia($first, $mapping),
      'wrap' => trim((string) ($first['value'] ?? '')) === '' ? NULL : [
        'value' => sprintf('<%1$s>%2$s</%1$s>', $spec['tag'] ?? 'p', htmlspecialchars(trim((string) $first['value']), ENT_QUOTES)),
        'format' => $mapping->markup()['format'],
      ],
      'target' => empty($first['target_id']) ? NULL : (string) $first['target_id'],
      'number' => !isset($first[$spec['key'] ?? 'value']) || $first[$spec['key'] ?? 'value'] === '' ? NULL : [
        'value' => (int) round((float) $first[$spec['key'] ?? 'value'] / (float) ($spec['divide'] ?? 1)),
      ],
      'link' => empty($first['uri']) ? NULL : [
        'uri' => $first['uri'],
        'title' => (string) ($first['title'] ?? ''),
        'options' => is_array($first['options'] ?? NULL) ? $first['options'] : [],
      ],
      default => throw new \RuntimeException(sprintf('Unknown transform "%s".', $spec['transform'])),
    };
  }

  /**
   * Rich text, rewritten for the neo format.
   */
  private function markup(?array $first, ContentMapping $mapping): ?array {
    $rules = $mapping->markup();
    $html = $this->markup->rewrite((string) ($first['value'] ?? ''), $rules);
    return $html === '' ? NULL : ['value' => $html, 'format' => $rules['format']];
  }

  /**
   * Nested items as the entries of an array prop.
   */
  private function each(array $spec, array $children, ContentMapping $mapping): ?array {
    $entries = [];
    foreach ($children as $child) {
      if (!empty($child['missing']) || empty($child['status'])) {
        continue;
      }
      if (isset($spec['bundle']) && $child['bundle'] !== $spec['bundle']) {
        throw new \RuntimeException(sprintf('Expected nested %s items, found %s %d.', $spec['bundle'], $child['bundle'], $child['id']));
      }
      $used = $spec['ignore'] ?? [];
      $entry = [];
      foreach ($spec['props'] ?? [] as $name => $sub) {
        $used = array_merge($used, self::fields($sub));
        $value = $this->transform($sub, $child, $mapping);
        if ($value !== NULL) {
          $entry[$name] = $value;
        }
      }
      foreach ($child['fields'] as $fieldName => $field) {
        if ((!empty($field['items']) || !empty($field['children'])) && !in_array($fieldName, $used, TRUE)) {
          throw new \RuntimeException(sprintf('Nested %s %d: %s has a value that no prop takes.', $child['bundle'], $child['id'], $fieldName));
        }
      }
      $entries[] = $entry;
    }
    return $entries ?: NULL;
  }

  /**
   * The legacy fields a mapping entry reads.
   *
   * @return list<string>
   */
  public static function fields(array $spec): array {
    if (($spec['transform'] ?? NULL) === 'heading') {
      return array_values(array_filter(array_intersect_key($spec, array_flip(self::HEADING_PARTS))));
    }
    return isset($spec['from']) ? [$spec['from']] : [];
  }

  /**
   * A heading from one field per part; empty when every part is.
   */
  private function heading(array $spec, array $item): ?array {
    $value = [];
    foreach (self::HEADING_PARTS as $part) {
      $field = $spec[$part] ?? NULL;
      if ($field !== NULL && !isset($item['fields'][$field])) {
        throw new \RuntimeException(sprintf('%s has no field "%s".', $item['bundle'], $field));
      }
      $value[$part] = ['value' => $field ? trim((string) ($item['fields'][$field]['items'][0]['value'] ?? '')) : ''];
    }
    return implode('', array_column($value, 'value')) === '' ? NULL : $value;
  }

  /**
   * An image field value as a reference to an image media entity.
   */
  private function imageMedia(?array $first, ContentMapping $mapping): ?array {
    if (empty($first['target_id'])) {
      return NULL;
    }
    ['bundle' => $bundle, 'field' => $field] = $mapping->media('image');
    $storage = $this->entityTypeManager->getStorage('media');
    $alt = (string) ($first['alt'] ?? '');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', $bundle)
      ->condition("$field.target_id", $first['target_id'])
      ->condition("$field.alt", $alt)
      ->sort('mid')
      ->range(0, 1)
      ->execute();
    if ($ids) {
      return ['target_id' => (string) reset($ids)];
    }
    $file = $this->entityTypeManager->getStorage('file')->load($first['target_id']);
    if (!$file) {
      throw new \RuntimeException(sprintf('Image file %s does not exist.', $first['target_id']));
    }
    $media = $storage->create([
      'bundle' => $bundle,
      'name' => $alt !== '' ? $alt : $file->getFilename(),
      'uid' => $file->getOwnerId() ?: 1,
      'status' => TRUE,
      $field => [
        'target_id' => $file->id(),
        'alt' => $alt,
        'title' => (string) ($first['title'] ?? ''),
        'width' => $first['width'] ?? NULL,
        'height' => $first['height'] ?? NULL,
      ],
    ]);
    $media->save();
    return ['target_id' => (string) $media->id()];
  }

}
