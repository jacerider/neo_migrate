<?php

declare(strict_types=1);

namespace Drupal\neo_migrate;

/**
 * Renders an audit report as Markdown for a person to review.
 */
final class AuditMarkdown {

  /**
   * Renders the report.
   */
  public function render(array $report, string $site): string {
    $summary = $report['summary'];
    $out = [];
    $out[] = "# Legacy audit: $site";
    $out[] = '';
    $out[] = sprintf('Generated %s on Drupal %s. Default theme chain: %s. Admin theme: %s.',
      $report['generated'],
      $report['site']['drupal'],
      implode(' → ', $report['site']['default_theme']) ?: '-',
      implode(' → ', $report['site']['admin_theme']) ?: '-',
    );
    $out[] = '';
    $out[] = '## Summary';
    $out[] = '';
    $out[] = $this->table(['Measure', 'Value'], [
      ['Host entities holding a tree', $summary['hosts']],
      ['Paragraph types (live / total)', $summary['paragraph_types_live'] . ' / ' . $summary['paragraph_types']],
      ['Paragraphs live on current revisions', $summary['paragraphs_live']],
      ['Paragraphs in the database', $summary['paragraphs_in_database']],
      ['Default theme templates', $summary['theme_templates']],
      ['Config deleted when the legacy stack is uninstalled', $summary['config_deleted_on_removal']],
      ...array_map(static fn ($handling, $count) => ["Findings: $handling", $count], array_keys($summary['handling']), $summary['handling']),
    ]);
    $out[] = '';
    $out[] = 'Handling: **mechanical** — a neo_migrate command converts it. **judgment** — decided per site. **remove** — uninstalled at teardown. **skip** — unused, not carried over. **unclassified** — not in the legacy catalog; a person decides.';
    $out[] = '';
    $out[] = '## Findings';
    $out[] = '';
    $findings = $summary['findings'];
    usort($findings, static fn ($a, $b) => [$a['handling'], $a['area'], $a['item']] <=> [$b['handling'], $b['area'], $b['item']]);
    $out[] = $this->table(['Handling', 'Area', 'Item', 'Becomes', 'Note'], array_map(
      static fn ($f) => [$f['handling'], $f['area'], $f['item'], $f['replacement'] ?? '', $f['note'] ?? ''],
      $findings,
    ));

    if (!empty($report['paragraphs'])) {
      $paragraphs = $report['paragraphs'];
      $out[] = '';
      $out[] = '## Paragraph types';
      $out[] = '';
      $rows = [];
      foreach ($paragraphs['types'] as $id => $type) {
        $fields = array_map(static function ($name, $field) {
          $label = "$name (" . $field['type'] . ')';
          return isset($field['targets']) ? $label . ' → ' . implode(', ', $field['targets']) : $label;
        }, array_keys($type['fields']), $type['fields']);
        $rows[] = [$id, $type['live'], $type['in_database'], implode(', ', $type['host_bundles']), implode('<br>', $fields)];
      }
      $out[] = $this->table(['Type', 'Live', 'In DB', 'Used on', 'Fields'], $rows);
      $out[] = '';
      $out[] = '### Where trees are stored';
      $out[] = '';
      $out[] = $this->table(['Host', 'Field', 'Widget', 'Allowed types'], array_map(
        static fn ($h) => [$h['entity_type'] . '.' . $h['bundle'], $h['field'], $h['widget'] ?? '', implode(', ', $h['allowed'])],
        $paragraphs['hosts'],
      ));
      if ($paragraphs['orphans']) {
        $out[] = '';
        $out[] = 'Orphan bundles (rows with no paragraph type): ' . implode(', ', array_map(static fn ($b, $c) => "$b ($c)", array_keys($paragraphs['orphans']), $paragraphs['orphans'])) . '.';
      }
    }

    if (!empty($report['icons'])) {
      $icons = $report['icons'];
      $out[] = '';
      $out[] = '## Icons';
      $out[] = '';
      $out[] = $this->table(['Package', 'Prefix', 'Type', 'Icons', 'Enabled'], array_map(
        static fn ($id, $p) => [$id, $p['prefix'], $p['type'], $p['icons'], $p['status'] ? 'yes' : 'no'],
        array_keys($icons['packages']), $icons['packages'],
      ));
      $out[] = '';
      $rows = [];
      foreach ($icons['fields'] as $field) {
        $values = isset($field['values']) ? count($field['values']) . ' distinct, ' . array_sum($field['values']) . ' stored' : '';
        $rows[] = [$field['field'], $field['type'], implode(', ', $field['bundles']), $values, implode(', ', $field['dangling'] ?? [])];
      }
      $out[] = $this->table(['Field', 'Type', 'Bundles', 'Values', 'Not in any package'], $rows);
      $menu = $icons['menu_links'];
      $out[] = '';
      $out[] = sprintf('Menu links with icon attributes: %d (%s). Not in any package: %s.',
        $menu['links'],
        implode(', ', array_map(static fn ($i, $c) => "$i ×$c", array_keys($menu['icons']), $menu['icons'])) ?: 'none',
        implode(', ', $menu['dangling']) ?: 'none',
      );
      if ($icons['config']) {
        $out[] = '';
        $out[] = $this->table(['Config', 'Key', 'Icon'], array_map(
          static fn ($c) => [$c['config'], $c['key'], $c['value'] . ($c['known'] ? '' : ' (unknown)')],
          $icons['config'],
        ));
      }
    }

    if (!empty($report['toolbar'])) {
      $out[] = '';
      $out[] = '## Toolbar (escort → neo_toolbar)';
      $out[] = '';
      $out[] = $this->table(['Item', 'Plugin', 'Region', 'Becomes'], array_map(
        static fn ($i) => [$i['id'], $i['plugin'], $i['region'], $i['neo_toolbar'] ?? '?'],
        $report['toolbar']['items'],
      ));
    }

    if (!empty($report['favicon'])) {
      $out[] = '';
      $out[] = '## Favicon';
      $out[] = '';
      $out[] = 'Packages: ' . implode(', ', array_map(static fn ($id, $p) => $id . ($p['status'] ? '' : ' (disabled)'), array_keys($report['favicon']['packages']), $report['favicon']['packages'])) . '.';
    }

    if (!empty($report['site_settings'])) {
      $out[] = '';
      $out[] = '## Site settings';
      $out[] = '';
      $out[] = 'Filled keys: ' . implode(', ', $report['site_settings']['filled']) . '.';
    }

    if (!empty($report['metatags'])) {
      $out[] = '';
      $out[] = '## Metatags using legacy tokens';
      $out[] = '';
      $out[] = $this->table(['Config', 'Tag', 'Value'], array_map(
        static fn ($m) => [$m['config'], $m['tag'], '`' . $m['value'] . '`'],
        $report['metatags'],
      ));
    }

    if (!empty($report['theme'])) {
      $theme = $report['theme'];
      $out[] = '';
      $out[] = '## Default theme: ' . $theme['name'];
      $out[] = '';
      $out[] = '- Path: `' . $theme['path'] . '`';
      $out[] = '- Templates (' . count($theme['templates']) . '): ' . implode(', ', array_map(static fn ($t) => "`$t`", $theme['templates']));
      $out[] = '- Preprocess plugins: ' . (implode(', ', array_map(static fn ($t) => "`$t`", $theme['preprocess'])) ?: 'none');
      $out[] = '- Libraries: ' . (implode(', ', $theme['libraries']) ?: 'none');
      $out[] = '- Blocks placed: ' . count($theme['blocks']) . '; blocks in other themes: ' . (implode(', ', array_map(static fn ($t, $c) => "$t ($c)", array_keys($theme['blocks_in_other_themes']), $theme['blocks_in_other_themes'])) ?: 'none');
    }

    if (!empty($report['code']['matches'])) {
      $out[] = '';
      $out[] = '## Site code coupling';
      $out[] = '';
      $rows = [];
      foreach ($report['code']['matches'] as $key => $match) {
        $top = array_slice($match['files'], 0, 5, TRUE);
        $rows[] = [$key, $match['total'], implode('<br>', array_map(static fn ($f, $c) => "`$f` ×$c", array_keys($top), $top))];
      }
      $out[] = $this->table(['Pattern', 'Mentions', 'Top files'], $rows);
    }

    $removal = $report['removal'];
    $out[] = '';
    $out[] = '## What uninstalling the legacy stack would remove';
    $out[] = '';
    $out[] = sprintf('A dry run of core\'s dependency calculation for %d modules and %d themes: **%d** config items deleted, **%d** changed.',
      count($removal['modules']), count($removal['themes']), count($removal['delete']), count($removal['update']));
    $out[] = '';
    $out[] = '<details><summary>Deleted</summary>' . "\n\n" . implode("\n", array_map(static fn ($n) => "- `$n`", $removal['delete'])) . "\n\n</details>";
    $out[] = '';
    $out[] = '<details><summary>Changed</summary>' . "\n\n" . implode("\n", array_map(static fn ($n) => "- `$n`", $removal['update'])) . "\n\n</details>";

    if ($report['extensions']['unclassified']) {
      $out[] = '';
      $out[] = '## Unclassified modules';
      $out[] = '';
      $out[] = 'Enabled, not core, not Neo and not in the legacy catalog. Each needs a keep-or-remove decision.';
      $out[] = '';
      $out[] = implode(', ', array_map(static fn ($m) => '`' . $m['name'] . '`', $report['extensions']['unclassified']));
    }
    return implode("\n", $out) . "\n";
  }

  /**
   * A Markdown table.
   */
  private function table(array $header, array $rows): string {
    $escape = static fn ($cell) => str_replace(["|", "\n"], ['\|', ' '], (string) $cell);
    $lines = ['| ' . implode(' | ', array_map($escape, $header)) . ' |', '|' . str_repeat(' --- |', count($header))];
    foreach ($rows as $row) {
      $lines[] = '| ' . implode(' | ', array_map($escape, $row)) . ' |';
    }
    return implode("\n", $lines);
  }

}
