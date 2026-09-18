<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\neo_migrate\Theme\PreviewThemeNegotiator;

/**
 * Tells a previewing admin they are previewing, and how to stop.
 */
final class PreviewHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly PreviewThemeNegotiator $negotiator,
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Implements hook_page_top().
   */
  #[Hook('page_top')]
  public function pageTop(array &$page_top): void {
    $page_top['neo_migrate_preview'] = [
      '#cache' => ['contexts' => ['cookies:' . PreviewThemeNegotiator::COOKIE, 'user.permissions']],
    ];
    if (!$this->negotiator->applies($this->routeMatch)) {
      return;
    }
    $other = $this->negotiator->mode() === 'neo' ? 'legacy' : 'neo';
    $destination = ['query' => ['destination' => Url::fromRouteMatch($this->routeMatch)->toString()]];
    $page_top['neo_migrate_preview'] += [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['neo-migrate-preview'],
        'style' => 'position:fixed;bottom:12px;left:12px;z-index:10000;padding:6px 12px;border-radius:6px;background:#111;color:#fff;font:13px/1.4 system-ui,sans-serif;box-shadow:0 2px 8px rgba(0,0,0,.3)',
      ],
      'label' => ['#markup' => $this->t('Previewing the @mode themes.', ['@mode' => $this->negotiator->mode()]) . ' '],
      'switch' => [
        '#type' => 'link',
        '#title' => $this->t('Switch to @other', ['@other' => $other]),
        '#url' => Url::fromRoute('neo_migrate.preview', ['mode' => $other], $destination),
        '#attributes' => ['style' => 'color:#9cf'],
      ],
      'sep' => ['#markup' => ' · '],
      'off' => [
        '#type' => 'link',
        '#title' => $this->t('Stop'),
        '#url' => Url::fromRoute('neo_migrate.preview', ['mode' => 'off'], $destination),
        '#attributes' => ['style' => 'color:#9cf'],
      ],
    ];
  }

}
