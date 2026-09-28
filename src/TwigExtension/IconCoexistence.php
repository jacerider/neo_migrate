<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\TwigExtension;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\neo_migrate\Theme\ThemeFamily;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * One icon() Twig function for both icon systems while they coexist.
 *
 * exo_icon and neo_icon each register a Twig function named `icon`, and the
 * extension registered last serves every theme: with neo_icon installed, the
 * legacy templates' exo icon ids went to neo_icon and rendered nothing. This
 * extension is registered after both (lowest priority) and sends each call to
 * the system the active theme belongs to: neo_icon for a Neo theme, exo_icon
 * for any other. It steps aside when only one of them is installed.
 */
final class IconCoexistence extends AbstractExtension {

  public function __construct(
    private readonly ThemeManagerInterface $themeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFunctions(): array {
    if (!$this->moduleHandler->moduleExists('exo_icon') || !$this->moduleHandler->moduleExists('neo_icon')) {
      return [];
    }
    return [new TwigFunction('icon', [$this, 'renderIcon'])];
  }

  /**
   * Renders an icon with the icon system of the active theme.
   */
  public function renderIcon(mixed ...$arguments): mixed {
    if (ThemeFamily::isNeo($this->themeManager->getActiveTheme())) {
      return \Drupal\neo_icon\TwigExtension::renderIcon(...$arguments);
    }
    return \Drupal\exo_icon\TwigExtension\ExoIcon::renderIcon($arguments[0] ?? NULL);
  }

}
