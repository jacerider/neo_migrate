<?php

declare(strict_types=1);

namespace Drupal\neo_migrate;

use Drupal\neo_icon\IconRepository;

/**
 * Finds the neo_icon name for a legacy (micon) icon selector.
 *
 * Legacy selectors are `<package>-<name>` (`fa-gear`). Where the site keeps
 * its micon packages as unique neo_icon libraries, the selector itself still
 * resolves; this is for the places that take a plain neo_icon name — toolbar
 * items, admin icons — where Font Awesome 4 names map onto the Font Awesome 5
 * libraries neo_icon ships.
 */
final class IconNameResolver {

  public function __construct(
    private readonly LegacyCatalog $catalog,
    private readonly ?IconRepository $repository = NULL,
  ) {}

  /**
   * The neo_icon name for a legacy selector, or NULL when none matches.
   */
  public function resolve(?string $selector): ?string {
    if ($selector === NULL || $selector === '' || $this->repository === NULL) {
      return NULL;
    }
    $name = (string) preg_replace('/^fa-/', '', $selector);
    $aliases = $this->catalog->get('icon_aliases');
    foreach (array_unique([$name, $aliases[$name] ?? $name, (string) preg_replace('/-o$/', '', $name)]) as $candidate) {
      if ($this->repository->getIconFromLibrary($candidate)) {
        return $candidate;
      }
    }
    return NULL;
  }

}
