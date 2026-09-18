<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Drush\Commands;

use Drupal\neo_migrate\Importer\ToolbarImporter;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Commands that create config: run once locally, then export.
 *
 * What they produce travels to every environment through config import, so
 * they never run on a hosted environment.
 */
final class NeoMigrateConfigCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'neo_migrate.toolbar_importer')]
    private readonly ToolbarImporter $toolbarImporter,
  ) {
    parent::__construct();
  }

  /**
   * Rebuilds escort's items as neo_toolbar items. Creates config.
   */
  #[CLI\Command(name: 'neo-migrate:toolbar', aliases: ['nmt'])]
  #[CLI\Option(name: 'toolbar', description: 'The neo_toolbar to add items to.')]
  #[CLI\Option(name: 'theme', description: 'Show the toolbar only on this theme (the admin theme, while the public site is still legacy); "any" shows it everywhere again.')]
  #[CLI\Option(name: 'dry-run', description: 'Report what would happen without saving.')]
  #[CLI\Usage(name: 'drush neo-migrate:toolbar --dry-run', description: 'Preview the mapping.')]
  #[CLI\Usage(name: 'drush neo-migrate:toolbar --theme=back', description: 'Import, and show the toolbar only in the back theme.')]
  public function toolbar(array $options = ['toolbar' => 'default', 'theme' => NULL, 'dry-run' => FALSE]): void {
    $report = $this->toolbarImporter->import($options['toolbar'], (bool) $options['dry-run']);
    if ($options['theme']) {
      $theme = $options['theme'] === 'any' ? NULL : (string) $options['theme'];
      $this->toolbarImporter->limitToTheme($options['toolbar'], $theme, (bool) $options['dry-run']);
      $this->io()->text($theme ? "Toolbar shown only on the $theme theme." : 'Toolbar shown on every theme.');
    }
    $this->io()->table(
      ['Escort item', 'Plugin', 'Action', 'neo_toolbar item', 'Note'],
      array_map(static fn ($row) => [$row['escort'], $row['plugin'], $row['action'], $row['item'] ?? '', $row['note']], $report),
    );
    $roles = $this->toolbarImporter->grantAccess((bool) $options['dry-run']);
    $this->io()->text('Roles given "access neo_toolbar" because they had "access escort": ' . (implode(', ', $roles) ?: 'none'));
    $problems = array_filter($report, static fn ($row) => in_array($row['action'], ['missing', 'unmapped'], TRUE) || $row['note'] !== '' && $row['action'] !== 'covered');
    if ($options['dry-run']) {
      $this->io()->note('Dry run: nothing saved.');
    }
    elseif ($problems) {
      $this->io()->warning(count($problems) . ' item(s) need a look; see the notes.');
    }
    else {
      $this->io()->success('Toolbar items saved. Export config to keep them.');
    }
  }

}
