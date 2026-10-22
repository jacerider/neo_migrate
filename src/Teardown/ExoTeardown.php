<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Teardown;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\DeletedFieldsRepositoryInterface;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;

/**
 * Clears an exo_alchemist site's legacy content so its modules can go.
 *
 * Runs on every environment, after the content is converted and verified and
 * before any legacy module is uninstalled. Uninstalling without it fails or
 * does damage:
 * - A host display still carries exo_alchemist's and Layout Builder's
 *   third-party settings, so uninstalling exo_alchemist deletes the display
 *   (the page renders nothing until the config import recreates it).
 * - exo_alchemist's field types are in use while its blocks and their fields
 *   exist, and Drupal refuses to uninstall it.
 * - The Layout Builder overrides field, once Layout Builder is off, cannot be
 *   purged: core's LayoutSectionItemList asks its deleted field for the
 *   entity and throws ("Field layout_builder__layout is unknown").
 */
final class ExoTeardown {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly DeletedFieldsRepositoryInterface $deletedFields,
    private readonly Connection $database,
  ) {}

  /**
   * Clears the legacy content.
   *
   * @return list<array{0: string, 1: string}>
   *   One row per step: what, and what happened.
   */
  public function run(bool $dryRun = FALSE): array {
    $rows = [];
    $rows = array_merge($rows, $this->displays($dryRun));
    // exo_alchemist names its block types exo_<hash>; other blocks stay.
    $rows[] = ['exo blocks', $this->deleteEntities('block_content', $dryRun, 'type', 'exo_')];
    $rows[] = ['exo block types', $this->deleteEntities('block_content_type', $dryRun, 'id', 'exo_')];
    $rows[] = ['exo site settings', $this->deleteEntities('exo_site_settings', $dryRun)];
    $rows[] = ['deleted fields purged', $dryRun ? 'skipped (dry run)' : $this->purge()];
    return $rows;
  }

  /**
   * Turns Layout Builder off on the tree hosts' displays.
   *
   * Drops exo_alchemist's and Layout Builder's third-party settings too, so
   * uninstalling them leaves the display (and its tree field) in place.
   *
   * @return list<array{0: string, 1: string}>
   *   One row per display changed.
   */
  private function displays(bool $dryRun): array {
    $field = (string) $this->configFactory->get('neo_migrate.settings')->get('coexistence.tree_field');
    $rows = [];
    foreach ($this->entityTypeManager->getStorage('entity_view_display')->loadMultiple() as $display) {
      $legacy = array_intersect($display->getThirdPartyProviders(), ['layout_builder', 'exo_alchemist']);
      if (!$legacy || !$display->getComponent($field)) {
        continue;
      }
      if (!$dryRun) {
        if ($display instanceof LayoutEntityDisplayInterface && $display->isLayoutBuilderEnabled()) {
          $display->disableLayoutBuilder();
        }
        foreach ($legacy as $provider) {
          foreach (array_keys($display->getThirdPartySettings($provider)) as $key) {
            $display->unsetThirdPartySetting($provider, $key);
          }
        }
        $display->save();
      }
      $rows[] = [$display->id(), 'Layout Builder off, ' . implode(' and ', $legacy) . ' settings removed'];
    }
    return $rows ?: [['host displays', 'none to change']];
  }

  /**
   * Deletes the legacy stack's entities of a type, if the type exists.
   *
   * With $key, only those whose $key starts with $prefix.
   */
  private function deleteEntities(string $entityTypeId, bool $dryRun, ?string $key = NULL, string $prefix = ''): string {
    if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
      return 'none (no such entity type)';
    }
    $storage = $this->entityTypeManager->getStorage($entityTypeId);
    $query = $storage->getQuery()->accessCheck(FALSE);
    if ($key) {
      $query->condition($key, $prefix, 'STARTS_WITH');
    }
    $ids = $query->execute();
    if (!$dryRun) {
      foreach (array_chunk($ids, 50) as $chunk) {
        $storage->delete($storage->loadMultiple($chunk));
      }
    }
    return sprintf('%d %s', count($ids), $dryRun ? 'to delete' : 'deleted');
  }

  /**
   * Purges deleted fields, working around core's Layout Builder purge bug.
   *
   * The overrides field's deleted rows are removed directly; nothing reacts
   * to them, and the purge then finds none and drops the field.
   */
  private function purge(): string {
    foreach ($this->deletedFields->getFieldStorageDefinitions() as $definition) {
      if ($definition->getType() !== 'layout_section') {
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($definition->getTargetEntityTypeId());
      if (!$storage instanceof SqlEntityStorageInterface) {
        continue;
      }
      $mapping = $storage->getTableMapping();
      $tables = [
        $mapping->getDedicatedDataTableName($definition, TRUE),
        $mapping->getDedicatedRevisionTableName($definition, TRUE),
      ];
      foreach ($tables as $table) {
        if ($this->database->schema()->tableExists($table)) {
          $this->database->delete($table)->execute();
        }
      }
    }
    $passes = 0;
    while ($this->deletedFields->getFieldDefinitions() && $passes < 100) {
      field_purge_batch(1000);
      $passes++;
    }
    $left = count($this->deletedFields->getFieldDefinitions());
    return $left ? "$left still pending after $passes passes" : "done in $passes passes";
  }

}
