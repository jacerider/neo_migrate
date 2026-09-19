<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Content;

/**
 * Turns one legacy field value into one prop value.
 *
 * A prop's entry in the mapping file names the legacy field (`from`) and how
 * to read it (`transform`), or gives a fixed `value`. The result is the prop's
 * stored value — what goes under `value` in the component's props — or NULL
 * when the legacy field is empty, which leaves the prop unset.
 */
final class ValueTransformer {

  public function __construct(
    private readonly MarkupRewriter $markup,
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
      return $spec['value'];
    }
    $field = $item['fields'][$spec['from'] ?? ''] ?? NULL;
    if ($field === NULL) {
      throw new \RuntimeException(sprintf('%s has no field "%s".', $item['bundle'], $spec['from'] ?? ''));
    }
    $first = $field['items'][0] ?? NULL;
    return match ($spec['transform'] ?? 'string') {
      'markup' => $this->markup($first, $mapping),
      'string' => $first === NULL || trim((string) ($first['value'] ?? '')) === '' ? NULL : ['value' => trim((string) $first['value'])],
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

}
