<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Importer;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;

/**
 * Moves the site's real_favicon package into neo_favicon. Creates config.
 *
 * Both are realfavicongenerator.net packages with the tags that go with
 * them, so nothing is regenerated: the package real_favicon uses for the
 * default theme is decoded from its config, registered as neo_favicon's config
 * file (which unpacks it into public://neo-favicon), and its tags are copied
 * into neo_favicon.settings.
 *
 * Run it at the cutover only. Both modules write their tags under the same
 * `real_favicon` head key, so while both are configured the page carries
 * whichever runs last.
 */
final class FaviconImporter {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * Imports the default theme's package.
   *
   * @return array{package: string, tags: int, files: int}
   *   The real_favicon package used, how many tags, and how many files unpacked.
   */
  public function import(bool $dryRun = FALSE): array {
    $package = $this->package();
    $tags = array_values(array_filter((array) $package->get('tags')));
    $result = ['package' => (string) $package->id(), 'tags' => count($tags), 'files' => 0];
    if ($dryRun) {
      return $result;
    }

    $directory = 'public://neo-file';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $uri = $this->fileSystem->saveData($package->getArchive(), $directory . '/favicon.zip', FileExists::Replace);
    $files = $this->entityTypeManager->getStorage('file');
    $file = current($files->loadByProperties(['uri' => $uri])) ?: $files->create(['uri' => $uri, 'uid' => 1, 'status' => 1]);
    $file->save();

    /** @var \Drupal\neo_config_file\ConfigFileStorageInterface $configFiles */
    $configFiles = $this->entityTypeManager->getStorage('neo_config_file');
    /** @var \Drupal\neo_config_file\Entity\ConfigFile $configFile */
    $configFile = $configFiles->loadByFile($file) ?: $configFiles->createFromFile($file);
    // neo_favicon unpacks a config file saved from its settings form.
    $configFile->set('parent_form_id', 'neo_favicon_settings');
    $configFile->addDependent('config', 'neo_favicon.settings');
    $configFile->save();

    $this->configFactory->getEditable('neo_favicon.settings')
      ->set('file', $configFile->id())
      ->set('tags', implode("\n", $tags))
      ->save();

    $unpacked = $this->fileSystem->scanDirectory('public://neo-favicon', '/.*/');
    $result['files'] = count($unpacked);
    return $result;
  }

  /**
   * The package real_favicon serves on the default theme.
   *
   * @return \Drupal\real_favicon\Entity\RealFavicon
   *   The package.
   */
  private function package() {
    $storage = $this->entityTypeManager->hasDefinition('real_favicon') ? $this->entityTypeManager->getStorage('real_favicon') : NULL;
    if (!$storage) {
      throw new \RuntimeException('real_favicon is not installed.');
    }
    $default = (string) $this->configFactory->get('system.theme')->get('default');
    $id = $this->configFactory->get('real_favicon.settings')->get("themes.$default");
    $package = $id ? $storage->load($id) : NULL;
    $package ??= current(array_filter($storage->loadMultiple(), static fn ($candidate) => $candidate->status())) ?: NULL;
    if (!$package) {
      throw new \RuntimeException('No real_favicon package is in use.');
    }
    return $package;
  }

}
