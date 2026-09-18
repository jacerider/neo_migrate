<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Theme;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Serves one admin the other stack's themes while both are installed.
 *
 * The preview is a cookie, so it follows the admin into every request the
 * page makes — including the iframes Alchemist renders previews in — and
 * never reaches anyone else: the permission is checked on every request, and
 * anonymous visitors (the only ones the page cache serves) cannot hold it.
 */
final class PreviewThemeNegotiator implements ThemeNegotiatorInterface {

  /**
   * The cookie name. Pantheon's CDN strips every cookie from requests except
   * a few prefixes, STYXKEY among them, so any other name never reaches PHP.
   */
  public const COOKIE = 'STYXKEY_neo_migrate_preview';

  public const MODES = ['neo', 'legacy'];

  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly AccountInterface $currentUser,
    private readonly AdminContext $adminContext,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ThemeHandlerInterface $themeHandler,
  ) {}

  /**
   * The preview mode the current request asks for, if any.
   */
  public function mode(): ?string {
    $mode = $this->requestStack->getCurrentRequest()?->cookies->get(self::COOKIE);
    return in_array($mode, self::MODES, TRUE) ? $mode : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    return $this->mode() !== NULL
      && $this->currentUser->hasPermission('preview neo migration')
      && $this->theme($route_match) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match): ?string {
    return $this->theme($route_match);
  }

  /**
   * The installed theme the mode names for this kind of route.
   */
  private function theme(RouteMatchInterface $route_match): ?string {
    $route = $route_match->getRouteObject();
    $key = $route && $this->adminContext->isAdminRoute($route) ? 'admin' : 'front';
    $theme = (string) $this->configFactory->get('neo_migrate.settings')->get('preview.' . $this->mode() . '.' . $key);
    return $theme !== '' && $this->themeHandler->themeExists($theme) ? $theme : NULL;
  }

}
