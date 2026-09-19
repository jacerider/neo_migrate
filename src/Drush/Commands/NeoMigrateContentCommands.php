<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Drush\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\neo_migrate\Content\ContentMapping;
use Drupal\neo_migrate\Content\TreeConverter;
use Drupal\neo_migrate\Importer\IconFieldImporter;
use Drupal\neo_migrate\Importer\SiteSettingsImporter;
use Drupal\neo_migrate\Source\ParagraphsSource;
use Drupal\neo_migrate\Workspace;
use Drupal\neo_migrate\Writer\ComponentTreeWriter;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Commands that change content: run on every environment.
 *
 * Their results are not config and do not travel through config import, so
 * each environment — local, the multidev, live — runs them against its own
 * data after its code and config are deployed.
 */
final class NeoMigrateContentCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'neo_migrate.site_settings_importer')]
    private readonly SiteSettingsImporter $siteSettingsImporter,
    #[Autowire(service: 'neo_migrate.icon_field_importer')]
    private readonly IconFieldImporter $iconFieldImporter,
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'neo_migrate.workspace')]
    private readonly Workspace $workspace,
    #[Autowire(service: 'neo_migrate.source.paragraphs')]
    private readonly ParagraphsSource $source,
    #[Autowire(service: 'neo_migrate.tree_converter')]
    private readonly TreeConverter $converter,
    #[Autowire(service: 'neo_migrate.tree_writer')]
    private readonly ComponentTreeWriter $writer,
    #[Autowire(service: 'database')]
    private readonly Connection $database,
  ) {
    parent::__construct();
  }

  /**
   * Copies a micon field's values into its neo_icon twin.
   *
   * Safe to repeat: entities whose twin already matches are left alone, and a
   * copy keeps the entity's changed time.
   */
  #[CLI\Command(name: 'neo-migrate:icon-field-values', aliases: ['nmifv'])]
  #[CLI\Argument(name: 'field', description: 'The micon field, as <entity type>.<field name>.')]
  #[CLI\Option(name: 'to', description: 'Machine name of the neo_icon twin.')]
  #[CLI\Option(name: 'dry-run', description: 'Report what would happen without saving.')]
  #[CLI\Usage(name: 'drush neo-migrate:icon-field-values node.field_icon --to=field_service_icon', description: 'Copy the service icons.')]
  public function iconFieldValues(string $field, array $options = ['to' => NULL, 'dry-run' => FALSE]): void {
    [$entityType, $legacy] = explode('.', $field, 2) + [1 => ''];
    $result = $this->iconFieldImporter->copyValues($entityType, $legacy, $options['to'] ?: $legacy . '_neo', (bool) $options['dry-run']);
    $this->io()->success(sprintf('%d entities, %d values, %d updated%s.', $result['entities'], $result['copied'], $result['changed'], $options['dry-run'] ? ' (dry run)' : ''));
  }

  /**
   * Copies this environment's legacy site settings into neo_site_settings.
   */
  #[CLI\Command(name: 'neo-migrate:site-settings', aliases: ['nmss'])]
  #[CLI\Option(name: 'dry-run', description: 'Report what would happen without saving.')]
  #[CLI\Usage(name: 'drush neo-migrate:site-settings', description: 'Run after neo-migrate:site-settings-types has been deployed.')]
  public function siteSettings(array $options = ['dry-run' => FALSE]): void {
    $report = $this->siteSettingsImporter->import((bool) $options['dry-run']);
    $this->io()->table(['Target', 'Value', 'Action'], array_map(static fn ($row) => [$row['target'], str_replace("\n", ' / ', $row['value']), $row['action']], $report));
    $this->io()->success($options['dry-run'] ? 'Dry run: nothing saved.' : 'Saved.');
  }


  /**
   * Converts each host's legacy tree into its component tree field.
   *
   * Follows migration/neo_migrate.yml. A host whose tree has an item the
   * mapping cannot convert is left alone and reported; so is one whose tree
   * field was edited after its last conversion, unless --overwrite is given.
   * Only the current revision is converted, into a new revision that keeps
   * the changed time and the alias.
   */
  #[CLI\Command(name: 'neo-migrate:content', aliases: ['nmc'])]
  #[CLI\Option(name: 'id', description: 'Only these host entity ids, comma-separated.')]
  #[CLI\Option(name: 'mapping', description: 'The mapping file (default: neo_migrate.yml in the migration directory).')]
  #[CLI\Option(name: 'overwrite', description: 'Replace tree fields changed since their last conversion.')]
  #[CLI\Option(name: 'dry-run', description: 'Convert and check everything, then roll it all back.')]
  #[CLI\Option(name: 'skip-unmapped', description: 'Leave out unmapped items instead of stopping the page. For previewing components while the mapping is incomplete; a later full run replaces the tree.')]
  #[CLI\Usage(name: 'drush neo-migrate:content --id=7,25 --dry-run', description: 'Check two pages without saving.')]
  public function content(array $options = ['id' => NULL, 'mapping' => NULL, 'overwrite' => FALSE, 'dry-run' => FALSE, 'skip-unmapped' => FALSE]): int {
    $mapping = ContentMapping::fromFile($options['mapping'] ?: $this->workspace->path('neo_migrate.yml'));
    if ($mapping->source() !== $this->source->id()) {
      throw new \RuntimeException(sprintf('The mapping reads "%s"; only "%s" is supported.', $mapping->source(), $this->source->id()));
    }
    $ids = $options['id'] ? array_map('trim', explode(',', (string) $options['id'])) : NULL;
    $rows = [];
    $failed = 0;
    foreach ($mapping->hosts() as $host) {
      $bundles = [];
      foreach ($this->source->hosts() as $known) {
        if ($known['entity_type'] === $host['entity_type'] && $known['field'] === $host['field']) {
          $bundles = $known['bundles'];
        }
      }
      $storage = $this->entityTypeManager->getStorage($host['entity_type']);
      $query = $storage->getQuery()->accessCheck(FALSE)->sort($storage->getEntityType()->getKey('id'));
      if ($bundles && ($bundleKey = $storage->getEntityType()->getKey('bundle'))) {
        $query->condition($bundleKey, $bundles, 'IN');
      }
      if ($ids) {
        $query->condition($storage->getEntityType()->getKey('id'), $ids, 'IN');
      }
      foreach ($storage->loadMultiple($query->execute()) as $entity) {
        /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
        // A dry run still creates what the conversion needs (media entities)
        // so the result can be read back, then rolls it all back.
        $transaction = $options['dry-run'] ? $this->database->startTransaction() : NULL;
        try {
          $tree = $this->source->tree($entity, $host['field']);
          $converted = $this->converter->convert($tree, $mapping, $entity, (bool) $options['skip-unmapped']);
          $result = $converted['problems']
            ? ['action' => 'failed', 'problems' => $converted['problems']]
            : $this->writer->write($entity, $host['target'], $converted['instances'], [
              'source_hash' => sha1(json_encode($tree)),
              'mapping_hash' => $mapping->hash(),
              'dry_run' => (bool) $options['dry-run'],
              'overwrite' => (bool) $options['overwrite'],
            ]);
        }
        finally {
          if ($transaction) {
            $transaction->rollBack();
            $this->entityTypeManager->getStorage('media')->resetCache();
          }
        }
        if (in_array($result['action'], ['failed', 'conflict'], TRUE)) {
          $failed++;
        }
        $notes = array_merge($result['problems'], array_map(static fn ($s) => "skipped $s", $converted['skipped']));
        $rows[] = [
          $entity->id(),
          $entity->label(),
          count($converted['instances']),
          $result['action'],
          implode("\n", $notes),
        ];
      }
    }
    $this->io()->table(['ID', 'Label', 'Components', 'Action', 'Notes'], $rows);
    if ($failed) {
      $this->io()->warning(sprintf('%d of %d not converted.', $failed, count($rows)));
      return self::EXIT_FAILURE;
    }
    $this->io()->success(sprintf('%d converted%s.', count($rows), $options['dry-run'] ? ' (dry run: nothing saved)' : ''));
    return self::EXIT_SUCCESS;
  }

}
