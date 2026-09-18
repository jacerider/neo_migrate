<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Drush\Commands;

use Drupal\neo_migrate\Importer\IconImporter;
use Drupal\neo_migrate\Importer\ToolbarImporter;
use Drupal\neo_migrate\Importer\TreeFieldInstaller;
use Drupal\neo_migrate\Source\ParagraphsSource;
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
    #[Autowire(service: 'neo_migrate.icon_importer')]
    private readonly IconImporter $iconImporter,
    #[Autowire(service: 'neo_migrate.tree_field_installer')]
    private readonly TreeFieldInstaller $treeFieldInstaller,
    #[Autowire(service: 'neo_migrate.source.paragraphs')]
    private readonly ParagraphsSource $paragraphs,
  ) {
    parent::__construct();
  }

  /**
   * Adds the component tree field beside the legacy body. Creates config.
   *
   * Without options it covers every host the paragraphs source finds.
   */
  #[CLI\Command(name: 'neo-migrate:tree-field', aliases: ['nmtf'])]
  #[CLI\Option(name: 'field', description: 'Machine name of the component tree field.')]
  #[CLI\Option(name: 'dry-run', description: 'Report what would happen without saving.')]
  #[CLI\Usage(name: 'drush neo-migrate:tree-field', description: 'Add field_full wherever paragraphs are hosted.')]
  public function treeField(array $options = ['field' => 'field_full', 'dry-run' => FALSE]): void {
    $hosts = $this->paragraphs->hosts();
    if (!$hosts) {
      throw new \RuntimeException('The paragraphs source finds no host fields.');
    }
    foreach ($hosts as $host) {
      $report = $this->treeFieldInstaller->install($host['entity_type'], $host['bundles'], $options['field'], $host['field'], (bool) $options['dry-run']);
      $this->io()->title($host['entity_type'] . ': ' . $options['field'] . ' beside ' . $host['field']);
      $this->io()->table(['Bundle', 'Field', 'Rendered in'], array_map(static fn ($row) => [$row['bundle'], $row['action'], implode(', ', $row['displays'])], $report));
    }
    $this->io()->success($options['dry-run'] ? 'Dry run: nothing saved.' : 'Saved. The legacy theme keeps rendering the old body; export config to keep this.');
  }

  /**
   * Imports micon's packages as unique neo_icon libraries. Creates config.
   */
  #[CLI\Command(name: 'neo-migrate:icons', aliases: ['nmic'])]
  #[CLI\Option(name: 'global', description: 'Load the libraries on every page. Leave off while the legacy theme still draws micon icons.')]
  #[CLI\Option(name: 'dry-run', description: 'Report what would happen without saving.')]
  #[CLI\Usage(name: 'drush neo-migrate:icons', description: 'Import every micon package.')]
  public function icons(array $options = ['global' => FALSE, 'dry-run' => FALSE]): void {
    $report = $this->iconImporter->import((bool) $options['global'], (bool) $options['dry-run']);
    $this->io()->table(
      ['micon package', 'neo_icon library', 'Action', 'micon icons', 'Note'],
      array_map(static fn ($row) => [$row['package'], $row['library'], $row['action'], $row['icons'], $row['note']], $report),
    );
    if ($options['dry-run']) {
      $this->io()->note('Dry run: nothing saved.');
    }
    else {
      $this->io()->success('Libraries saved. Export config to keep them (their zips go to config/files).');
    }
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
