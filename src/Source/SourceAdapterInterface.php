<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Source;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Reads a legacy content-building system as ordered trees of items.
 *
 * Paragraphs is the first source. Others — exo_alchemist, Layout Builder —
 * implement the same contract so the inventory, the converter and the
 * verification never need to know which system a site was built with.
 */
interface SourceAdapterInterface {

  /**
   * The adapter's machine name, as used in the mapping file.
   */
  public function id(): string;

  /**
   * Whether the system this adapter reads is installed on the site.
   */
  public function applies(): bool;

  /**
   * The fields that hold trees, and the bundles that carry each.
   *
   * @return list<array{entity_type: string, field: string, bundles: list<string>}>
   */
  public function hosts(): array;

  /**
   * The ordered tree stored in one host field.
   *
   * Each item is `{bundle, id, revision, uuid, status, behavior, fields}`.
   * A field that nests further items carries `children` instead of `items`.
   *
   * @return list<array>
   */
  public function tree(ContentEntityInterface $host, string $field): array;

}
