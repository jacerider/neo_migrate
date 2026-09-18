<?php

declare(strict_types=1);

namespace Drupal\neo_migrate;

use Drupal\Core\Serialization\Yaml;

/**
 * What neo_migrate knows about each legacy extension (neo_migrate.legacy.yml).
 */
final class LegacyCatalog {

  /**
   * The decoded catalog.
   */
  private ?array $data = NULL;

  /**
   * One top-level section of the catalog.
   */
  public function get(string $section): array {
    if ($this->data === NULL) {
      $this->data = Yaml::decode((string) file_get_contents(dirname(__DIR__) . '/neo_migrate.legacy.yml')) ?: [];
    }
    return $this->data[$section] ?? [];
  }

  /**
   * The catalog entry for a module, or NULL when it is not a legacy module.
   */
  public function module(string $name): ?array {
    return $this->get('modules')[$name] ?? NULL;
  }

  /**
   * The catalog entry for a theme, or NULL when it is not a legacy theme.
   */
  public function theme(string $name): ?array {
    return $this->get('themes')[$name] ?? NULL;
  }

}
