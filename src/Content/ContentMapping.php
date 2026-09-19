<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Content;

use Drupal\Component\Serialization\Yaml;

/**
 * The site's mapping file: how its legacy items become components.
 *
 * `migration/neo_migrate.yml`, written per site and reviewed by a person:
 *
 * @code
 * source: paragraphs
 * hosts:
 *   - { entity_type: node, field: field_body, target: field_full }
 * unmapped: fail
 * prepend:
 *   - { component: title_s1, ids: [7, 8] }
 * bundle_props:                  # fixed props for every component on a bundle
 *   service: { spacing: { value: lg } }
 * markup:
 *   format: neo
 *   classes: { 'button outline': 'btn btn-outline-primary' }
 *   unwrap: [span]
 *   attributes: { drop: [data-list-item-id] }
 * paragraphs:
 *   text:
 *     component: text_s1
 *     props:
 *       content: { from: field_text, transform: markup }
 *     ignore: [field_legacy_only]   # filled fields no prop takes
 * @endcode
 */
final class ContentMapping {

  private function __construct(
    private readonly array $data,
    public readonly string $path,
  ) {}

  /**
   * Reads and checks a mapping file.
   */
  public static function fromFile(string $path): self {
    if (!is_file($path)) {
      throw new \RuntimeException("No mapping file at $path.");
    }
    $data = Yaml::decode((string) file_get_contents($path)) ?? [];
    foreach (['source', 'hosts', 'paragraphs'] as $key) {
      if (empty($data[$key])) {
        throw new \RuntimeException("The mapping file has no \"$key\".");
      }
    }
    foreach ($data['hosts'] as $host) {
      if (empty($host['entity_type']) || empty($host['field']) || empty($host['target'])) {
        throw new \RuntimeException('Each host needs entity_type, field and target.');
      }
    }
    foreach ($data['paragraphs'] as $bundle => $spec) {
      if (empty($spec['component']) && empty($spec['skip'])) {
        throw new \RuntimeException("Paragraph type \"$bundle\" names no component (or skip: true).");
      }
    }
    return new self($data, $path);
  }

  /**
   * The source adapter's id.
   */
  public function source(): string {
    return $this->data['source'];
  }

  /**
   * The host fields to convert, each with the tree field it becomes.
   *
   * @return list<array{entity_type: string, field: string, target: string}>
   */
  public function hosts(): array {
    return $this->data['hosts'];
  }

  /**
   * What to do with an item whose type has no entry: fail or skip.
   */
  public function unmapped(): string {
    return ($this->data['unmapped'] ?? 'fail') === 'skip' ? 'skip' : 'fail';
  }

  /**
   * The entry for one legacy item type, or NULL.
   */
  public function item(string $bundle): ?array {
    return $this->data['paragraphs'][$bundle] ?? NULL;
  }

  /**
   * Components added before the converted tree on particular hosts.
   *
   * For what the legacy theme showed on some pages only, outside the tree —
   * a page title, say — and which in neo belongs to the page's content.
   *
   * @return list<array{component: string, props?: array}>
   */
  public function prepend(string $entityTypeId, int|string $id): array {
    $entries = [];
    foreach ($this->data['prepend'] ?? [] as $entry) {
      $type = $entry['entity_type'] ?? 'node';
      if ($type === $entityTypeId && in_array((string) $id, array_map('strval', $entry['ids'] ?? []), TRUE)) {
        $entries[] = $entry;
      }
    }
    return $entries;
  }

  /**
   * Fixed prop values for every component converted on hosts of a bundle.
   *
   * For how a legacy theme styled one content type differently — wider
   * spacing on service pages, say — which in neo is a prop each component
   * carries. Applied only where the component has the prop and its own
   * mapping does not set it.
   *
   * @return array<string, array>
   */
  public function bundleProps(string $bundle): array {
    return $this->data['bundle_props'][$bundle] ?? [];
  }

  /**
   * Rules applied to every rich text value.
   *
   * @return array{format: string, classes: array<string, string>, unwrap: list<string>, drop: list<string>}
   */
  public function markup(): array {
    $markup = $this->data['markup'] ?? [];
    return [
      'format' => $markup['format'] ?? 'neo',
      'classes' => $markup['classes'] ?? [],
      'unwrap' => $markup['unwrap'] ?? [],
      'drop' => $markup['attributes']['drop'] ?? [],
    ];
  }

  /**
   * The media bundle and source field an image (or other file) becomes.
   *
   * @return array{bundle: string, field: string}
   */
  public function media(string $kind): array {
    $defaults = ['image' => ['bundle' => 'image', 'field' => 'field_media_image']];
    $media = ($this->data['media'][$kind] ?? []) + ($defaults[$kind] ?? []);
    if (empty($media['bundle']) || empty($media['field'])) {
      throw new \RuntimeException("The mapping names no media bundle and field for \"$kind\".");
    }
    return $media;
  }

  /**
   * A fingerprint of the file, stored with each conversion.
   */
  public function hash(): string {
    return sha1(serialize($this->data));
  }

}
