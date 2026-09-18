<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Drush\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\neo_migrate\Auditor;
use Drupal\neo_migrate\AuditMarkdown;
use Drupal\neo_migrate\Inventory;
use Drupal\neo_migrate\MetricsLog;
use Drupal\neo_migrate\UrlCollector;
use Drupal\neo_migrate\Workspace;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Read-only commands that measure a legacy site before anything changes.
 *
 * Everything they write lands in the site's migration directory
 * (`<project root>/migration` unless `--dir` says otherwise).
 */
final class NeoMigrateCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'neo_migrate.workspace')]
    private readonly Workspace $workspace,
    #[Autowire(service: 'neo_migrate.auditor')]
    private readonly Auditor $auditor,
    #[Autowire(service: 'neo_migrate.audit_markdown')]
    private readonly AuditMarkdown $markdown,
    #[Autowire(service: 'neo_migrate.inventory')]
    private readonly Inventory $inventory,
    #[Autowire(service: 'neo_migrate.url_collector')]
    private readonly UrlCollector $urlCollector,
    #[Autowire(service: 'neo_migrate.metrics')]
    private readonly MetricsLog $metrics,
    #[Autowire(service: 'config.factory')]
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct();
  }

  /**
   * Finds everything legacy on the site and says how each piece is handled.
   */
  #[CLI\Command(name: 'neo-migrate:audit', aliases: ['nma'])]
  #[CLI\Option(name: 'dir', description: 'Migration directory. Defaults to <project root>/migration.')]
  #[CLI\Usage(name: 'drush neo-migrate:audit', description: 'Write migration/audit.json and migration/audit.md.')]
  public function audit(array $options = ['dir' => NULL]): void {
    $report = $this->auditor->run();
    $json = $this->workspace->writeJson('audit.json', $report, $options['dir']);
    $md = $this->workspace->writeText('audit.md', $this->markdown->render($report, $this->siteName()), $options['dir']);
    $summary = $report['summary'];
    $this->io()->definitionList(
      ['Host entities' => $summary['hosts']],
      ['Paragraph types (live / total)' => $summary['paragraph_types_live'] . ' / ' . $summary['paragraph_types']],
      ['Paragraphs (live / in database)' => $summary['paragraphs_live'] . ' / ' . $summary['paragraphs_in_database']],
      ['Config deleted on removal' => $summary['config_deleted_on_removal']],
      ...array_map(static fn ($handling, $count) => ["Findings: $handling" => $count], array_keys($summary['handling']), $summary['handling']),
    );
    $this->io()->note('Warnings about displays or components being "disabled" come from core\'s dependency dry run working on copies; nothing was saved.');
    $this->io()->success("Wrote $json and $md.");
  }

  /**
   * Records every host entity and the tree it holds, for later verification.
   */
  #[CLI\Command(name: 'neo-migrate:inventory', aliases: ['nmi'])]
  #[CLI\Option(name: 'label', description: 'Written as inventory.<label>.json.')]
  #[CLI\Option(name: 'dir', description: 'Migration directory. Defaults to <project root>/migration.')]
  #[CLI\Usage(name: 'drush neo-migrate:inventory', description: 'Write migration/inventory.before.json.')]
  public function inventory(array $options = ['label' => 'before', 'dir' => NULL]): void {
    $inventory = $this->inventory->build();
    $path = $this->workspace->writeJson('inventory.' . $options['label'] . '.json', $inventory, $options['dir']);
    $rows = [];
    foreach ($inventory['counts'] as $bundle => $count) {
      $rows[] = [$bundle, $count['live'], $count['published'], $count['hosts'], implode(', ', $count['host_bundles'])];
    }
    $this->io()->table(['Bundle', 'Live', 'Published', 'Hosts', 'Used on'], $rows);
    $this->io()->success(sprintf('Recorded %d host entities in %s.', count($inventory['entities']), $path));
  }

  /**
   * Lists the public URLs to check before and after the migration.
   */
  #[CLI\Command(name: 'neo-migrate:urls', aliases: ['nmu'])]
  #[CLI\Option(name: 'dir', description: 'Migration directory. Defaults to <project root>/migration.')]
  #[CLI\Usage(name: 'drush neo-migrate:urls', description: 'Write migration/urls.json.')]
  public function urls(array $options = ['dir' => NULL]): void {
    $urls = $this->urlCollector->collect();
    $path = $this->workspace->writeJson('urls.json', $urls, $options['dir']);
    $byKind = array_count_values(array_column($urls, 'kind'));
    ksort($byKind);
    $this->io()->table(['Kind', 'URLs'], array_map(null, array_keys($byKind), $byKind));
    $this->io()->success(sprintf('Wrote %d URLs (%d screenshotted) to %s.', count($urls), count(array_filter($urls, static fn ($u) => $u['check'] === 'visual')), $path));
  }

  /**
   * Logs effort to metrics.jsonl, or summarizes it.
   */
  #[CLI\Command(name: 'neo-migrate:metric', aliases: ['nmm'])]
  #[CLI\Argument(name: 'event', description: 'start, end, note, intervention, session or estimate; omit with --summary.')]
  #[CLI\Option(name: 'phase', description: 'Plan phase, 0–6.')]
  #[CLI\Option(name: 'step', description: 'What was being done, e.g. "audit" or "component:hero_s1".')]
  #[CLI\Option(name: 'actor', description: 'ai or human.')]
  #[CLI\Option(name: 'kind', description: 'build (work on neo_migrate itself) or migrate (work on this site).')]
  #[CLI\Option(name: 'minutes', description: 'Minutes spent.')]
  #[CLI\Option(name: 'type', description: 'For interventions: decision, correction, unblock or review.')]
  #[CLI\Option(name: 'session', description: 'AI session id.')]
  #[CLI\Option(name: 'diff', description: 'Visual difference in percent, when a comparison was run.')]
  #[CLI\Option(name: 'note', description: 'Free text.')]
  #[CLI\Option(name: 'summary', description: 'Print totals instead of logging an event.')]
  #[CLI\Option(name: 'dir', description: 'Migration directory. Defaults to <project root>/migration.')]
  #[CLI\Usage(name: 'drush neo-migrate:metric end --phase=0 --step=audit --actor=ai --kind=build --minutes=40', description: 'Log finished work.')]
  #[CLI\Usage(name: 'drush neo-migrate:metric intervention --phase=3 --type=correction --note="hero overlap"', description: 'Log a person stepping in.')]
  #[CLI\Usage(name: 'drush neo-migrate:metric --summary', description: 'Totals by phase, actor and kind.')]
  public function metric(?string $event = NULL, array $options = [
    'phase' => NULL,
    'step' => NULL,
    'actor' => NULL,
    'kind' => NULL,
    'minutes' => NULL,
    'type' => NULL,
    'session' => NULL,
    'diff' => NULL,
    'note' => NULL,
    'summary' => FALSE,
    'dir' => NULL,
  ]): void {
    if ($options['summary'] || $event === NULL) {
      $summary = $this->metrics->summarize($options['dir']);
      $this->io()->table(['Phase / actor / kind', 'Minutes'], array_map(null, array_keys($summary['minutes']), $summary['minutes']));
      $this->io()->table(['Intervention', 'Count'], array_map(null, array_keys($summary['interventions']), $summary['interventions']));
      $this->io()->text(sprintf('Sessions: %d', $summary['sessions']));
      return;
    }
    $values = [
      'site' => $this->siteName(),
      // Drush hands "--phase=0" over as FALSE.
      'phase' => $options['phase'] === FALSE ? '0' : $options['phase'],
      'step' => $options['step'],
      'actor' => $options['actor'],
      'kind' => $options['kind'],
      'minutes' => $options['minutes'] !== NULL ? (float) $options['minutes'] : NULL,
      'type' => $options['type'],
      'session' => $options['session'],
      'diff' => $options['diff'] !== NULL ? (float) $options['diff'] : NULL,
      'note' => $options['note'],
    ];
    $line = $this->metrics->record($event, $values, $options['dir']);
    $this->io()->success('Logged: ' . json_encode($line, JSON_UNESCAPED_SLASHES));
  }

  /**
   * A stable name for the site: its DDEV project, else the site name.
   */
  private function siteName(): string {
    return getenv('DDEV_SITENAME') ?: (string) $this->configFactory->get('system.site')->get('name');
  }

}
