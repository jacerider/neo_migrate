<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Theme\ThemeManagerInterface;

/**
 * Lets each stack render only its own body field while both are installed.
 *
 * Between content conversion and teardown a node carries both the legacy body
 * (paragraphs) and the component tree. The legacy themes render the body and
 * never the tree; the Neo front theme renders the tree and never the body.
 * Render caching already varies by theme, so each theme caches its own build.
 */
final class CoexistenceHooks {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ThemeManagerInterface $themeManager,
  ) {}

  /**
   * Implements hook_entity_view_alter().
   */
  #[Hook('entity_view_alter')]
  public function entityViewAlter(array &$build, EntityInterface $entity, EntityViewDisplayInterface $display): void {
    $settings = $this->configFactory->get('neo_migrate.settings');
    $tree = (string) $settings->get('coexistence.tree_field');
    $legacy = (string) $settings->get('coexistence.legacy_field');
    if ($tree === '' || $legacy === '' || !isset($build[$tree], $build[$legacy])) {
      return;
    }
    $theme = $this->themeManager->getActiveTheme()->getName();
    if ($theme === $settings->get('preview.legacy.front')) {
      unset($build[$tree]);
    }
    elseif ($theme === $settings->get('preview.neo.front')) {
      unset($build[$legacy]);
    }
  }

}
