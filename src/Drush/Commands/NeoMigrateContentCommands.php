<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Drush\Commands;

use Drupal\neo_migrate\Importer\IconFieldImporter;
use Drupal\neo_migrate\Importer\SiteSettingsImporter;
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

}
