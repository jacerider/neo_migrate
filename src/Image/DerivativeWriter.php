<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Image;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\image\ImageStyleInterface;
use Drupal\neo_image\NeoImageStyle;

/**
 * Writes an image style derivative from its URL, outside a web request.
 *
 * On Pantheon the web request that converts a very large photo to AVIF can die
 * part way (a 502, then "Image generation in progress" while its lock lasts),
 * while the same conversion takes seconds from the command line. The warm step
 * lists the derivatives that still fail; this writes them.
 *
 * A page's share image is worse: neo builds its neo_social derivative while
 * rendering the page's head, so a photo too large for the request takes the
 * page itself down. shareImages() writes those before visitors arrive.
 */
final class DerivativeWriter {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Writes the share image derivative of every page with a component tree.
   *
   * The image is found as neo's [neo:image] token finds it (the
   * neo_token_image alter hook: the tree's first image, else the largest
   * favicon), in the neo_social style neo shares it in.
   *
   * @return array<string, string>
   *   The result per derivative URI: written, present or failed.
   */
  public function shareImages(): array {
    $style = $this->entityTypeManager->getStorage('image_style')->load('neo_social');
    $field = (string) $this->configFactory->get('neo_migrate.settings')->get('coexistence.tree_field');
    if (!$style instanceof ImageStyleInterface || $field === '') {
      return [];
    }
    $results = [];
    foreach ($this->entityFieldManager->getFieldMap() as $entityTypeId => $fields) {
      if (!isset($fields[$field])) {
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($entityTypeId);
      $ids = $storage->getQuery()->accessCheck(FALSE)->exists($field)->execute();
      foreach ($storage->loadMultiple($ids) as $entity) {
        if (!$entity instanceof ContentEntityInterface) {
          continue;
        }
        $uri = NULL;
        $params = [];
        $this->moduleHandler->alter('neo_token_image', $uri, $params, $entity);
        if (!$uri || !file_exists($uri) || !$style->supportsUri($uri)) {
          continue;
        }
        $derivative = $style->buildUri($uri);
        if (!isset($results[$derivative])) {
          $results[$derivative] = file_exists($derivative) ? 'present' : ($style->createDerivative($uri, $derivative) ? 'written' : 'failed');
        }
      }
    }
    return $results;
  }

  /**
   * Writes the derivative a page references.
   *
   * @param string $url
   *   The derivative's URL or path as the page references it, such as
   *   /sites/default/files/styles/neo-s--w-860/public/2024-11/a.jpg.avif.
   *
   * @return array{derivative: ?string, result: string}
   *   The derivative's URI, and one of: written, present, failed,
   *   not a derivative, unknown style, missing source.
   */
  public function write(string $url): array {
    $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
    if (!preg_match('#/styles/([^/]+)/(public|private)/(.+)$#', $path, $matches)) {
      return ['derivative' => NULL, 'result' => 'not a derivative'];
    }
    [, $id, $scheme, $target] = $matches;
    $style = $this->style($id);
    if (!$style) {
      return ['derivative' => NULL, 'result' => 'unknown style'];
    }
    // A style that converts the format names its derivative after the whole
    // source name (photo.jpg.avif).
    $source = "$scheme://$target";
    if (!file_exists($source) && preg_match('#^(.+\.[a-z0-9]+)\.[a-z0-9]+$#i', $target, $base)) {
      $source = "$scheme://{$base[1]}";
    }
    if (!file_exists($source)) {
      return ['derivative' => NULL, 'result' => 'missing source'];
    }
    $derivative = $style->buildUri($source);
    if (file_exists($derivative)) {
      return ['derivative' => $derivative, 'result' => 'present'];
    }
    $written = $style->createDerivative($source, $derivative);
    return ['derivative' => $derivative, 'result' => $written ? 'written' : 'failed'];
  }

  /**
   * The style an id names: a neo_image style's id carries its parameters.
   */
  private function style(string $id): ?ImageStyleInterface {
    if (str_starts_with($id, 'neo-') && class_exists(NeoImageStyle::class)) {
      try {
        $neo = new NeoImageStyle();
        return $neo->setParameters($neo->convertIdToParams($id))->getImageStyle();
      }
      catch (\InvalidArgumentException) {
        return NULL;
      }
    }
    $style = $this->entityTypeManager->getStorage('image_style')->load($id);
    return $style instanceof ImageStyleInterface ? $style : NULL;
  }

}
