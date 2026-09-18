<?php

declare(strict_types=1);

namespace Drupal\neo_migrate;

/**
 * The migration's effort log, `metrics.jsonl`, one event per line.
 *
 * It is what answers "is this worth doing across the fleet": hours per phase,
 * how much of it was the AI and how much a person, and every time a person had
 * to step in. Time spent building neo_migrate itself is logged with kind
 * `build`, apart from the per-site work (`migrate`), so the second site shows
 * the true repeatable cost.
 */
final class MetricsLog {

  public const FILE = 'metrics.jsonl';

  /**
   * Event names a log line may carry.
   */
  public const EVENTS = ['start', 'end', 'note', 'intervention', 'session', 'estimate'];

  public function __construct(
    private readonly Workspace $workspace,
  ) {}

  /**
   * Appends one event and returns the line written.
   */
  public function record(string $event, array $values, ?string $dir = NULL): array {
    if (!in_array($event, self::EVENTS, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown event "%s"; use one of: %s.', $event, implode(', ', self::EVENTS)));
    }
    $line = ['ts' => date('c'), 'event' => $event] + array_filter($values, static fn ($value) => $value !== NULL && $value !== '');
    $this->workspace->appendLine(self::FILE, json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $dir);
    return $line;
  }

  /**
   * Totals per phase, actor and kind, plus interventions by type.
   */
  public function summarize(?string $dir = NULL): array {
    $path = $this->workspace->path(self::FILE, $dir);
    $summary = ['minutes' => [], 'interventions' => [], 'sessions' => 0];
    if (!is_file($path)) {
      return $summary;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $raw) {
      $line = json_decode($raw, TRUE);
      if (!is_array($line)) {
        continue;
      }
      if (isset($line['minutes']) && $line['event'] !== 'estimate') {
        $key = implode(' / ', [$line['phase'] ?? '-', $line['actor'] ?? '-', $line['kind'] ?? '-']);
        $summary['minutes'][$key] = ($summary['minutes'][$key] ?? 0) + (float) $line['minutes'];
      }
      if ($line['event'] === 'intervention') {
        $type = $line['type'] ?? 'unspecified';
        $summary['interventions'][$type] = ($summary['interventions'][$type] ?? 0) + 1;
      }
      if ($line['event'] === 'session') {
        $summary['sessions']++;
      }
    }
    ksort($summary['minutes']);
    return $summary;
  }

}
