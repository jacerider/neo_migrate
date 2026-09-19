<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Content;

use Drupal\Component\Utility\Html;

/**
 * Rewrites legacy rich text for the neo text format.
 *
 * Three rules from the mapping file: a class list matched as a set (in any
 * order) is replaced by its neo classes, listed tags are unwrapped (kept
 * content, dropped element), and listed attributes are removed. Everything
 * else passes through untouched; the text format decides what is shown.
 */
final class MarkupRewriter {

  /**
   * Rewrites one HTML fragment.
   *
   * @param array{classes: array<string, string>, unwrap: list<string>, drop: list<string>} $rules
   *   The mapping file's markup rules.
   */
  public function rewrite(string $html, array $rules): string {
    if (trim($html) === '') {
      return '';
    }
    $classes = [];
    foreach ($rules['classes'] as $legacy => $neo) {
      $classes[$this->classKey((string) $legacy)] = $neo;
    }
    $dom = Html::load($html);
    $xpath = new \DOMXPath($dom);

    foreach (iterator_to_array($xpath->query('//body//*[@class]')) as $element) {
      /** @var \DOMElement $element */
      $key = $this->classKey($element->getAttribute('class'));
      if (isset($classes[$key])) {
        $element->setAttribute('class', $classes[$key]);
      }
    }
    foreach ($rules['drop'] as $attribute) {
      foreach (iterator_to_array($xpath->query('//body//*[@' . $attribute . ']')) as $element) {
        $element->removeAttribute($attribute);
      }
    }
    foreach ($rules['unwrap'] as $tag) {
      // Innermost first, so nested wrappers unwrap cleanly.
      $elements = array_reverse(iterator_to_array($xpath->query('//body//' . $tag)));
      foreach ($elements as $element) {
        while ($element->firstChild) {
          $element->parentNode->insertBefore($element->firstChild, $element);
        }
        $element->parentNode->removeChild($element);
      }
    }
    return trim(Html::serialize($dom));
  }

  /**
   * The visible text of a fragment, whitespace collapsed, for comparisons.
   */
  public static function text(string $html): string {
    $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
  }

  /**
   * A class list as an order-free key.
   */
  private function classKey(string $classes): string {
    $list = preg_split('/\s+/', trim($classes), -1, PREG_SPLIT_NO_EMPTY);
    sort($list);
    return implode(' ', $list);
  }

}
