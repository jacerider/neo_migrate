<?php

declare(strict_types=1);

namespace Drupal\neo_migrate;

/**
 * The site's migration directory: where every neo_migrate artifact is kept.
 *
 * It defaults to `<project root>/migration`, beside `web/` and `config/`, so
 * the audit, inventory, URL list, mapping and metrics are committed with the
 * site that produced them.
 */
final class Workspace {

  public function __construct(
    private readonly string $appRoot,
  ) {}

  /**
   * The migration directory, created if missing.
   */
  public function dir(?string $override = NULL): string {
    $dir = rtrim($override ?: dirname($this->appRoot) . '/migration', '/');
    if (!is_dir($dir)) {
      mkdir($dir, 0775, TRUE);
    }
    return $dir;
  }

  /**
   * The absolute path of a file inside the migration directory.
   */
  public function path(string $file, ?string $dir = NULL): string {
    return $this->dir($dir) . '/' . $file;
  }

  /**
   * Writes pretty-printed JSON, returning the path written.
   */
  public function writeJson(string $file, mixed $data, ?string $dir = NULL): string {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    return $this->writeText($file, $json . "\n", $dir);
  }

  /**
   * Reads a JSON file, or NULL when it does not exist.
   */
  public function readJson(string $file, ?string $dir = NULL): ?array {
    $path = $this->path($file, $dir);
    if (!is_file($path)) {
      return NULL;
    }
    return json_decode((string) file_get_contents($path), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Writes a text file, returning the path written.
   */
  public function writeText(string $file, string $text, ?string $dir = NULL): string {
    $path = $this->path($file, $dir);
    file_put_contents($path, $text);
    return $path;
  }

  /**
   * Appends one line to a file, returning the path written.
   */
  public function appendLine(string $file, string $line, ?string $dir = NULL): string {
    $path = $this->path($file, $dir);
    file_put_contents($path, rtrim($line, "\n") . "\n", FILE_APPEND | LOCK_EX);
    return $path;
  }

}
