<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Theme;

use Drupal\Core\Theme\ActiveTheme;

/**
 * Tells a Neo theme from a legacy one.
 *
 * A Neo theme is neo_base, neo_front or neo_back, or any theme built on one of
 * them (the site's front and back); every other theme is legacy.
 */
final class ThemeFamily {

  /**
   * The Neo base themes.
   */
  private const NEO_BASES = ['neo_base', 'neo_front', 'neo_back'];

  /**
   * Whether a theme is a Neo theme.
   */
  public static function isNeo(ActiveTheme $theme): bool {
    return (bool) array_intersect([$theme->getName(), ...array_keys($theme->getBaseThemeExtensions())], self::NEO_BASES);
  }

}
