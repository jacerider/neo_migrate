<?php

declare(strict_types=1);

namespace Drupal\neo_migrate;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\path_alias\AliasManagerInterface;

/**
 * The public URLs whose rendering must survive the migration.
 *
 * Each entry says how it is checked: `visual` pages are screenshotted and
 * compared section by section; `status` URLs (redirects, taxonomy pages) only
 * need to answer with the same status code and target.
 */
final class UrlCollector {

  /**
   * A path that should not exist, to compare the 404 page.
   */
  public const MISSING_PATH = '/neo-migrate-missing-page';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AliasManagerInterface $aliasManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Collects the URLs.
   *
   * @return list<array{path: string, kind: string, check: string}>
   */
  public function collect(): array {
    $urls = [];
    $front = (string) $this->configFactory->get('system.site')->get('page.front');
    $urls['/'] = ['path' => '/', 'kind' => 'front', 'check' => 'visual', 'internal' => $front];
    foreach ($this->nodes($front) as $url) {
      $urls[$url['path']] ??= $url;
    }
    foreach ([...$this->webforms(), ...$this->views()] as $url) {
      $urls[$url['path']] ??= $url;
    }
    $urls['/user/login'] ??= ['path' => '/user/login', 'kind' => 'special', 'check' => 'visual'];
    $urls[self::MISSING_PATH] = ['path' => self::MISSING_PATH, 'kind' => '404', 'check' => 'visual'];
    foreach ([...$this->terms(), ...$this->redirects()] as $url) {
      $urls[$url['path']] ??= $url;
    }
    return array_values($urls);
  }

  /**
   * Published nodes, by alias; the front page node is covered by `/`.
   */
  private function nodes(string $front): array {
    if (!$this->entityTypeManager->hasDefinition('node')) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('status', 1)->sort('nid')->execute();
    $urls = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $internal = '/node/' . $node->id();
      if ($internal === $front) {
        continue;
      }
      $urls[] = [
        'path' => $this->aliasManager->getAliasByPath($internal),
        'kind' => 'node',
        'check' => 'visual',
        'id' => (int) $node->id(),
        'bundle' => $node->bundle(),
        'label' => (string) $node->label(),
      ];
    }
    return $urls;
  }

  /**
   * Webforms that are open and have a page of their own.
   */
  private function webforms(): array {
    if (!$this->moduleHandler->moduleExists('webform')) {
      return [];
    }
    $urls = [];
    foreach ($this->entityTypeManager->getStorage('webform')->loadMultiple() as $webform) {
      /** @var \Drupal\webform\WebformInterface $webform */
      if (!$webform->isOpen() || !$webform->getSetting('page')) {
        continue;
      }
      $path = $webform->getSetting('page_submit_path') ?: '/form/' . str_replace('_', '-', $webform->id());
      $urls[] = ['path' => $path, 'kind' => 'webform', 'check' => 'visual', 'id' => $webform->id(), 'label' => (string) $webform->label()];
    }
    return $urls;
  }

  /**
   * Enabled Views page displays that take no arguments and are not admin.
   */
  private function views(): array {
    if (!$this->moduleHandler->moduleExists('views')) {
      return [];
    }
    $urls = [];
    foreach ($this->entityTypeManager->getStorage('view')->loadMultiple() as $view) {
      if (!$view->status()) {
        continue;
      }
      foreach ($view->get('display') as $displayId => $display) {
        $path = $display['display_options']['path'] ?? NULL;
        $enabled = $display['display_options']['enabled'] ?? TRUE;
        if ($display['display_plugin'] !== 'page' || !$path || !$enabled || str_contains($path, '%') || str_starts_with($path, 'admin')) {
          continue;
        }
        $urls[] = ['path' => '/' . $path, 'kind' => 'view', 'check' => 'visual', 'id' => $view->id() . ':' . $displayId, 'label' => (string) $view->label()];
      }
    }
    return $urls;
  }

  /**
   * Published taxonomy term pages; checked by status only.
   */
  private function terms(): array {
    if (!$this->entityTypeManager->hasDefinition('taxonomy_term')) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('status', 1)->sort('tid')->execute();
    $urls = [];
    foreach ($ids as $id) {
      $urls[] = ['path' => $this->aliasManager->getAliasByPath('/taxonomy/term/' . $id), 'kind' => 'term', 'check' => 'status', 'id' => (int) $id];
    }
    return $urls;
  }

  /**
   * Redirect sources, with the status and target they must keep.
   */
  private function redirects(): array {
    if (!$this->moduleHandler->moduleExists('redirect')) {
      return [];
    }
    $urls = [];
    foreach ($this->entityTypeManager->getStorage('redirect')->loadMultiple() as $redirect) {
      /** @var \Drupal\redirect\Entity\Redirect $redirect */
      $source = $redirect->getSourceUrl();
      try {
        $target = $redirect->getRedirectUrl()->toString();
      }
      catch (\Throwable) {
        $target = NULL;
      }
      $urls[] = ['path' => $source, 'kind' => 'redirect', 'check' => 'status', 'status' => (int) $redirect->getStatusCode(), 'to' => $target];
    }
    return $urls;
  }

}
