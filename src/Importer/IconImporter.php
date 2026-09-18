<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Importer;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;

/**
 * Brings micon's icon packages into neo_icon, keeping every stored name.
 *
 * Each micon package becomes a neo_icon library with the same machine name,
 * marked unique, so its icons are named `<library>-<name>` — exactly the
 * `fa-wrench` / `assured-checkmark` strings micon stored in fields, menus and
 * templates. The package's own IcoMoon zip is reused as it is: written to
 * `public://neo-file/<id>.zip`, registered as a neo_config_file whose parent
 * is the library, and saved, which makes the library unpack itself.
 *
 * Libraries are not global by default: they load only where a neo icon is
 * rendered, so the legacy theme, still drawing micon's own `fa-*` classes,
 * is untouched until the cutover.
 */
final class IconImporter {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * Imports every micon package.
   *
   * @return list<array{package: string, library: string, action: string, icons: int, note: string}>
   *   One row per package.
   */
  public function import(bool $global = FALSE, bool $dryRun = FALSE): array {
    if (!$this->moduleHandler->moduleExists('micon') || !$this->moduleHandler->moduleExists('neo_icon')) {
      throw new \RuntimeException('Both micon and neo_icon must be installed.');
    }
    $libraries = $this->entityTypeManager->getStorage('neo_icon_library');
    $report = [];
    foreach ($this->entityTypeManager->getStorage('micon')->loadMultiple() as $id => $package) {
      /** @var \Drupal\micon\Entity\Micon $package */
      $row = ['package' => (string) $id, 'library' => (string) $id, 'action' => '', 'icons' => count($package->getIcons()), 'note' => ''];
      /** @var \Drupal\neo_icon\Entity\IconLibrary|null $library */
      $library = $libraries->load($id);
      if ($library && $library->getFile() !== $id . '_zip') {
        $report[] = ['action' => 'skipped', 'note' => "a neo_icon library \"$id\" already exists and is not an import"] + $row;
        continue;
      }
      $row['action'] = $library ? 'updated' : 'created';
      if ($dryRun) {
        $report[] = $row;
        continue;
      }

      $library ??= $libraries->create(['id' => $id]);
      $library->set('label', (string) $package->label());
      $library->set('type', $package->type() === 'image' ? 'image' : 'font');
      $library->set('file', $id . '_zip');
      $library->set('global', $global);
      $library->set('unique', TRUE);
      $library->set('status', (bool) $package->status());
      $library->set('weight', 20);
      $library->save();

      $this->attachArchive($library, (string) $package->getArchive());
      $report[] = ['note' => $this->check($library)] + $row;
    }
    return $report;
  }

  /**
   * Writes the package zip and hands it to the library as its config file.
   */
  private function attachArchive($library, string $archive): void {
    $directory = 'public://neo-file';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $uri = $this->fileSystem->saveData($archive, $directory . '/' . $library->id() . '.zip', FileExists::Replace);

    $files = $this->entityTypeManager->getStorage('file');
    $file = current($files->loadByProperties(['uri' => $uri])) ?: $files->create(['uri' => $uri, 'uid' => 1, 'status' => 1]);
    // Saving a file under public://neo-file registers its config file.
    $file->save();

    /** @var \Drupal\neo_config_file\ConfigFileStorageInterface $configFiles */
    $configFiles = $this->entityTypeManager->getStorage('neo_config_file');
    /** @var \Drupal\neo_config_file\Entity\ConfigFile $configFile */
    $configFile = $configFiles->loadByFile($file) ?: $configFiles->createFromFile($file);
    $configFile->setParentEntity($library);
    $configFile->set('parent_field', 'file');
    $configFile->addDependent('config', $library->getConfigDependencyName());
    // The parent library unpacks the package while this saves.
    $configFile->save();
  }

  /**
   * Confirms the library unpacked and knows its icons.
   */
  private function check($library): string {
    $reloaded = $this->entityTypeManager->getStorage('neo_icon_library')->loadUnchanged($library->id());
    if (!file_exists($reloaded->getUri() . '/definitions.json')) {
      return 'the package did not unpack; no definitions.json';
    }
    $count = count($reloaded->getIcons());
    return "$count icons in neo_icon";
  }

}
