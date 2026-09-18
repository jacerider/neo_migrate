<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\neo_migrate\Theme\PreviewThemeNegotiator;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Turns the theme preview on or off for the current browser.
 */
final class PreviewController extends ControllerBase {

  /**
   * Sets or clears the preview cookie, then returns to where the admin was.
   *
   * `?destination=` wins; otherwise the front page. Only local paths are
   * followed, so the route cannot be used as an open redirect.
   */
  public function set(Request $request, string $mode): RedirectResponse {
    $destination = (string) $request->query->get('destination', '');
    $target = str_starts_with($destination, '/') && !str_starts_with($destination, '//')
      ? $request->getBasePath() . $destination
      : Url::fromRoute('<front>')->toString();
    $request->query->remove('destination');
    $response = new RedirectResponse($target);
    if ($mode === 'off') {
      $response->headers->clearCookie(PreviewThemeNegotiator::COOKIE, '/');
      return $response;
    }
    $response->headers->setCookie(Cookie::create(PreviewThemeNegotiator::COOKIE, $mode, 0, '/', NULL, $request->isSecure(), TRUE, FALSE, Cookie::SAMESITE_LAX));
    return $response;
  }

}
