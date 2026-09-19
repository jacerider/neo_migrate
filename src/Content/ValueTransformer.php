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
 *   so a file used twice becomes one library item.
 */
final class ValueTransformer {

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
      'flag' => ['value' => isset($spec['when']) ? (string) ($first['value'] ?? '') === (string) $spec['when'] : !empty($first['value'])],
      'image_media' => $this->imageMedia($first, $mapping),
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
