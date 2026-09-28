<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Source;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Reads a legacy content-building system as ordered trees of items.
 *
 * Paragraphs and exo_alchemist implement it; others (plain Layout Builder)
 * can follow, so the inventory, the converter and the verification never need
 * to know which system a site was built with. SourceChain reads every adapter
 * that applies as one.
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
   * Each item is `{bundle, id, revision, uuid, status, behavior, fields}`,
   * plus `placement` where the source has one (a layout section and region).
   * A field that nests further items carries `children` instead of `items`.
   *
   * @return list<array>
   */
  public function tree(ContentEntityInterface $host, string $field): array;

  /**
   * A fingerprint of a tree that changes only when its content does.
   *
   * Stored with each conversion so a later run, and verification, can tell
   * whether the legacy tree was edited since.
   */
  public function fingerprint(array $tree): string;

}
