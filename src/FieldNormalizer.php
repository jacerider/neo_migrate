<?php

declare(strict_types=1);

namespace Drupal\neo_migrate;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\file\FileInterface;

/**
 * Turns a field's values into plain, comparable data.
 *
 * The result is what the inventory records and what verification compares
 * against after conversion: the raw columns, plus what a person needs to
 * recognise the value — the plain text and a hash of formatted text, the file
 * URI behind an image, the label behind a reference.
 */
final class FieldNormalizer {

  /**
   * Field types whose `value` column holds markup.
   */
  private const TEXT_TYPES = ['text', 'text_long', 'text_with_summary'];

  /**
   * Normalizes every item of a field.
   *
   * @return array{type: string, items: list<array>}
   */
  public function normalize(FieldItemListInterface $items): array {
    $definition = $items->getFieldDefinition();
    $type = $definition->getType();
    $normalized = [];
    foreach ($items as $item) {
      $value = $this->scalars($item->getValue());
      if (in_array($type, self::TEXT_TYPES, TRUE) && isset($value['value'])) {
        $value['plain'] = self::plain((string) $value['value']);
        $value['sha1'] = sha1((string) $value['value']);
      }
      $entity = $item->entity ?? NULL;
      if ($entity instanceof FileInterface) {
        $value['uri'] = $entity->getFileUri();
        $value['filename'] = $entity->getFilename();
      }
      elseif ($entity instanceof EntityInterface && isset($value['target_id'])) {
        $value['target_type'] = $entity->getEntityTypeId();
        $value['label'] = (string) $entity->label();
      }
      $normalized[] = $value;
    }
    return ['type' => $type, 'items' => $normalized];
  }

  /**
   * The visible text of a piece of markup, whitespace collapsed.
   */
  public static function plain(string $markup): string {
    $text = strip_tags(str_replace('<', ' <', $markup));
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string) preg_replace('/\s+/u', ' ', $text));
  }

  /**
   * Drops objects from an item's values so the result is JSON-safe.
   */
  private function scalars(array $values): array {
    $clean = [];
    foreach ($values as $key => $value) {
      if (is_array($value)) {
        $clean[$key] = $this->scalars($value);
      }
      elseif ($value === NULL || is_scalar($value)) {
        $clean[$key] = $value;
      }
    }
    return $clean;
  }

}
