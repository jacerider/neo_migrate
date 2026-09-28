<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Source;

/**
 * A tree fingerprint that ignores revision ids.
 *
 * Saving a host as a new revision can save new revisions of the items it
 * holds (entity_reference_revisions and inline blocks both do), so revision
 * ids change without any content changing and would make every re-run a
 * rewrite.
 */
trait RevisionFreeFingerprintTrait {

  /**
   * {@inheritdoc}
   */
  public function fingerprint(array $tree): string {
    $strip = static function (array $value) use (&$strip): array {
      unset($value['revision'], $value['target_revision_id']);
      foreach ($value as $key => $child) {
        if (is_array($child)) {
          $value[$key] = $strip($child);
        }
      }
      return $value;
    };
    return sha1(json_encode($strip($tree)));
  }

}
