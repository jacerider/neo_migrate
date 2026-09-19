<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Drush\Commands;

use Drupal\neo_migrate\Importer\EntityIconImporter;
use Drupal\neo_migrate\Importer\FaviconImporter;
use Drupal\neo_migrate\Importer\IconFieldImporter;
use Drupal\neo_migrate\Importer\IconImporter;
use Drupal\neo_migrate\Importer\MetatagRewriter;
use Drupal\neo_migrate\Importer\SiteSettingsImporter;
use Drupal\neo_migrate\Importer\ToolbarImporter;
use Drupal\neo_migrate\Importer\TreeFieldInstaller;
use Drupal\neo_migrate\PalletGenerator;
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
    #[Autowire(service: 'neo_migrate.site_settings_importer')]
    private readonly SiteSettingsImporter $siteSettingsImporter,
    #[Autowire(service: 'neo_migrate.icon_field_importer')]
    private readonly IconFieldImporter $iconFieldImporter,
    #[Autowire(service: 'neo_migrate.favicon_importer')]
    private readonly FaviconImporter $faviconImporter,
    #[Autowire(service: 'neo_migrate.metatag_rewriter')]
    private readonly MetatagRewriter $metatagRewriter,
    #[Autowire(service: 'neo_migrate.entity_icon_importer')]
    private readonly EntityIconImporter $entityIconImporter,
  ) {
    parent::__construct();
  }

  /**
   * Sets a neo_color pallet from one brand colour. Creates config.
   *
   * The ramp is generated exactly as the pallet form generates it. Rebuild
   * the assets afterwards (npm run deploy): pallets are compiled into CSS.
   */
  #[CLI\Command(name: 'neo-migrate:pallet', aliases: ['nmp'])]
  #[CLI\Argument(name: 'id', description: 'The pallet, e.g. primary, secondary, accent.')]
  #[CLI\Argument(name: 'hex', description: 'The brand colour, which becomes shade 500.')]
  #[CLI\Option(name: 'dry-run', description: 'Print the ramp without saving.')]
  #[CLI\Usage(name: 'drush neo-migrate:pallet primary "#0917B9"', description: 'Make the primary pallet the legacy brand blue.')]
  public function pallet(string $id, string $hex, array $options = ['dry-run' => FALSE]): void {
    $shades = (new PalletGenerator())->ramp($hex);
    $this->io()->table(['Shade', 'Colour', 'Dark content'], array_map(null, array_keys($shades), array_column($shades, 'color'), array_column($shades, 'dark')));
    if ($options['dry-run']) {
      $this->io()->note('Dry run: nothing saved.');
      return;
    }
    $config = \Drupal::configFactory()->getEditable("neo_color.neo_pallet.$id");
    if ($config->isNew()) {
      throw new \RuntimeException("No neo_color pallet \"$id\".");
    }
    $config->set('shades', array_map(static fn ($shade) => ['color' => $shade['color'], 'dark' => $shade['dark']], $shades))->set('specific', FALSE)->save();
    $this->io()->success("Pallet $id set from $hex. Rebuild assets, then export config.");
  }

  /**
   * Swaps legacy tokens for Neo tokens in the metatag defaults. Creates config.
   *
   * For the cutover: the Neo tokens read the component tree.
   */
  #[CLI\Command(name: 'neo-migrate:metatags', aliases: ['nmmt'])]
  #[CLI\Option(name: 'dry-run', description: 'Report what would happen without saving.')]
  public function metatags(array $options = ['dry-run' => FALSE]): void {
    $report = $this->metatagRewriter->rewrite((bool) $options['dry-run']);
    $this->io()->table(['Config', 'Tag', 'From', 'To'], array_map(static fn ($row) => [str_replace('metatag.metatag_defaults.', '', $row['config']), $row['tag'], $row['from'], $row['to'] === '' ? '(legacy token left)' : $row['to']], $report));
    $this->io()->success($options['dry-run'] ? 'Dry run: nothing saved.' : 'Saved. Export it with the cutover.');
  }

  /**
   * Moves the real_favicon package into neo_favicon. Creates config.
   *
   * For the cutover only: both modules write the same head tags.
   */
  #[CLI\Command(name: 'neo-migrate:favicon', aliases: ['nmfav'])]
  #[CLI\Option(name: 'dry-run', description: 'Report what would happen without saving.')]
  public function favicon(array $options = ['dry-run' => FALSE]): void {
    $result = $this->faviconImporter->import((bool) $options['dry-run']);
    $this->io()->definitionList(['real_favicon package' => $result['package']], ['Tags' => $result['tags']], ['Files unpacked' => $result['files']]);
    $this->io()->success($options['dry-run'] ? 'Dry run: nothing saved.' : 'Saved. Export it with the cutover, alongside uninstalling real_favicon.');
  }

  /**
   * Adds a neo_icon twin beside a micon icon field. Creates config.
   *
   * The values are content: see neo-migrate:icon-field-values.
   */
  #[CLI\Command(name: 'neo-migrate:icon-field', aliases: ['nmif'])]
  #[CLI\Argument(name: 'field', description: 'The micon field, as <entity type>.<field name>.')]
  #[CLI\Option(name: 'to', description: 'Machine name of the new neo_icon field.')]
  #[CLI\Option(name: 'dry-run', description: 'Report what would happen without saving.')]
  #[CLI\Usage(name: 'drush neo-migrate:icon-field node.field_icon --to=field_service_icon', description: 'Twin the service icon.')]
  public function iconField(string $field, array $options = ['to' => NULL, 'dry-run' => FALSE]): void {
    [$entityType, $legacy] = explode('.', $field, 2) + [1 => ''];
    $to = $options['to'] ?: $legacy . '_neo';
    $report = $this->iconFieldImporter->ensureField($entityType, $legacy, $to, (bool) $options['dry-run']);
    $this->io()->table(['Bundle', "$entityType.$to"], array_map(static fn ($row) => [$row['bundle'], $row['action']], $report));
    $this->io()->success($options['dry-run'] ? 'Dry run: nothing saved.' : 'Saved. Export config, then copy the values on each environment.');
  }

  /**
   * Creates the neo_site_settings bundles the legacy values need. Creates config.
   *
   * The values themselves are content: see neo-migrate:site-settings.
   */
  #[CLI\Command(name: 'neo-migrate:site-settings-types', aliases: ['nmsst'])]
  #[CLI\Option(name: 'dry-run', description: 'Report what would happen without saving.')]
  public function siteSettingsTypes(array $options = ['dry-run' => FALSE]): void {
    $created = $this->siteSettingsImporter->ensureStructure((bool) $options['dry-run']);
    $this->io()->listing($created ?: ['nothing to create']);
    $this->io()->success($options['dry-run'] ? 'Dry run: nothing saved.' : 'Saved. Export config to keep it.');
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
   * Moves content type and vocabulary icons into neo_icon. Creates config.
   *
   * Run before the export that uninstalls micon_content_type and
   * micon_vocabulary drops their settings.
   */
  #[CLI\Command(name: 'neo-migrate:entity-icons', aliases: ['nmei'])]
  #[CLI\Option(name: 'dry-run', description: 'Report what would happen without saving.')]
  #[CLI\Usage(name: 'drush neo-migrate:entity-icons', description: 'Copy every bundle icon.')]
  public function entityIcons(array $options = ['dry-run' => FALSE]): void {
    $report = $this->entityIconImporter->import((bool) $options['dry-run']);
    $this->io()->table(['Bundle', 'micon icon', 'neo_icon icon', 'Action'], array_map(static fn ($row) => [$row['entity'], $row['legacy'], $row['icon'], $row['action']], $report));
    $this->io()->success(($options['dry-run'] ? 'Dry run: nothing saved. ' : '') . count($report) . ' bundle icon(s).');
  }

  /**
   * Rebuilds escort's items as neo_toolbar items. Creates config.
   */
  #[CLI\Command(name: 'neo-migrate:toolbar', aliases: ['nmt'])]
  #[CLI\Option(name: 'toolbar', description: 'The neo_toolbar to add items to.')]
  #[CLI\Option(name: 'theme', description: 'Show the toolbar only on this theme; "any" shows it everywhere again.')]
  #[CLI\Option(name: 'except', description: 'Show the toolbar on every theme but this one: the legacy front theme, while the public site is still legacy.')]
  #[CLI\Option(name: 'dry-run', description: 'Report what would happen without saving.')]
  #[CLI\Usage(name: 'drush neo-migrate:toolbar --dry-run', description: 'Preview the mapping.')]
  #[CLI\Usage(name: 'drush neo-migrate:toolbar --except=client', description: 'Import, and keep the toolbar off the legacy client theme.')]
  public function toolbar(array $options = ['toolbar' => 'default', 'theme' => NULL, 'except' => NULL, 'dry-run' => FALSE]): void {
    $report = $this->toolbarImporter->import($options['toolbar'], (bool) $options['dry-run']);
    if ($options['except']) {
      $this->toolbarImporter->limitToTheme($options['toolbar'], (string) $options['except'], (bool) $options['dry-run'], TRUE);
      $this->io()->text("Toolbar shown on every theme but {$options['except']}.");
    }
    elseif ($options['theme']) {
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
    $problems = array_filter($report, static fn ($row) => in_array($row['action'], ['missing', 'unmapped'], TRUE) || $row['note'] !== '' && !in_array($row['action'], ['covered', 'removed'], TRUE));
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
