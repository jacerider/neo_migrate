<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Image;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\image\ImageStyleInterface;
use Drupal\neo_image\NeoImageStyle;

/**
 * Writes an image style derivative from its URL, outside a web request.
 *
 * On Pantheon the web request that converts a very large photo to AVIF can die
 * part way (a 502, then "Image generation in progress" while its lock lasts),
 * while the same conversion takes seconds from the command line. The warm step
 * lists the derivatives that still fail; this writes them.
 */
final class DerivativeWriter {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

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
