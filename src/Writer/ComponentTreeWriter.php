<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Writer;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Entity\SynchronizableInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\neo_migrate\Content\MarkupRewriter;
use Drupal\neo_migrate\Content\ValueTransformer;

/**
 * Writes converted component instances into a host's tree field.
 *
 * Writing props from code has a trap: a value in the wrong shape is stored
 * without complaint, and the component then renders its examples instead.
 * So every value is read back through the component before anything is
 * saved, and a value that does not come back as written stops the save.
 *
 * Each save records a fingerprint of what was written. A later run that
 * finds the field changed since then — an editor has been at it — reports a
 * conflict instead of overwriting, unless told to overwrite.
 */
final class ComponentTreeWriter {

  public function __construct(
    private readonly KeyValueFactoryInterface $keyValue,
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * Converts one host.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $host
   *   The host entity; its current revision is converted.
   * @param string $field
   *   The tree field to write.
   * @param list<array> $instances
   *   From TreeConverter::convert().
   * @param array{source_hash: string, mapping_hash: string, dry_run: bool, overwrite: bool} $options
   *   The source tree's and mapping file's fingerprints, and the run's flags.
   *
   * @return array{action: string, problems: list<string>}
   *   The action is written, unchanged, would write, conflict or failed.
   */
  public function write(ContentEntityInterface $host, string $field, array $instances, array $options): array {
    if (!$host->hasField($field)) {
      return ['action' => 'failed', 'problems' => ["{$host->bundle()} has no field $field."]];
    }
    $key = $host->getEntityTypeId() . ':' . $host->id();
    $store = $this->keyValue->get('neo_migrate.content');
    $record = $store->get($key);
    $current = $this->fingerprint($host->get($field)->getValue());
    if (!$host->get($field)->isEmpty() && ($record['target'] ?? NULL) !== $current && !$options['overwrite']) {
      return ['action' => 'conflict', 'problems' => ["$field was changed after the last conversion (or not by it); --overwrite replaces it."]];
    }

    $list = $host->get($field);
    $list->setValue(NULL);
    $problems = [];
    // Nothing to write leaves the field empty rather than holding an empty tree.
    if ($instances) {
      /** @var \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item */
      $item = $list->appendItem();
      foreach ($instances as $instance) {
        $item->addComponent($instance['uuid'], $instance['component'], [
          'status' => $instance['status'] ? 1 : 0,
          'props' => $instance['props'],
        ]);
      }
      $problems = $this->readBack($item, $instances);
    }
    // Only the tree field is validated: a legacy field may already hold
    // something its settings no longer allow, which is not this run's to fix.
    // neo_alchemist's tree constraint renders prop values, which needs a
    // render context outside a request.
    $violations = $this->renderer->executeInRenderContext(new RenderContext(), static fn () => $list->validate());
    foreach ($violations as $violation) {
      $problems[] = sprintf('%s: %s', $violation->getPropertyPath(), strip_tags((string) $violation->getMessage()));
    }
    if ($problems) {
      return ['action' => 'failed', 'problems' => $problems];
    }

    $written = $this->fingerprint($list->getValue());
    if ($written === $current && ($record['source'] ?? NULL) === $options['source_hash']) {
      return ['action' => 'unchanged', 'problems' => []];
    }
    if ($options['dry_run']) {
      return ['action' => 'would write', 'problems' => []];
    }

    // A conversion, not an edit: a new revision that says what made it, the
    // changed time kept, and Pathauto told to leave the alias alone.
    $host->setNewRevision(TRUE);
    if ($host instanceof RevisionLogInterface) {
      $host->setRevisionLogMessage('Converted from paragraphs by neo_migrate.');
      $host->setRevisionCreationTime(\Drupal::time()->getRequestTime());
    }
    if ($host instanceof SynchronizableInterface) {
      $host->setSyncing(TRUE);
    }
    if ($host->hasField('path') && !$host->get('path')->isEmpty()) {
      $host->get('path')->first()->set('pathauto', 0);
    }
    $this->renderer->executeInRenderContext(new RenderContext(), static fn () => $host->save());
    $store->set($key, [
      'source' => $options['source_hash'],
      'target' => $written,
      'mapping' => $options['mapping_hash'],
      'revision' => $host->getRevisionId(),
      'time' => \Drupal::time()->getRequestTime(),
    ]);
    return ['action' => 'written', 'problems' => []];
  }

  /**
   * Checks that each written prop comes back through the component as written.
   *
   * @return list<string>
   *   One line per prop that did not.
   */
  private function readBack($item, array $instances): array {
    $problems = [];
    $this->renderer->executeInRenderContext(new RenderContext(), function () use ($item, $instances, &$problems) {
      foreach ($instances as $instance) {
        $label = sprintf('%s %d → %s', $instance['source']['bundle'], $instance['source']['id'], $instance['component']);
        $component = $item->getComponent($instance['uuid']);
        if (!$component) {
          $problems[] = "$label: the component did not load.";
          continue;
        }
        $values = $component->getPropValues();
        foreach ($instance['props'] as $name => $prop) {
          $got = $values[$name] ?? NULL;
          $expected = $prop['value'];
          $hidden = !empty($prop['options'][$name]['empty']);
          $ok = $hidden ? in_array($got, [NULL, '', []], TRUE) : match ($prop['ref']) {
            'markup' => MarkupRewriter::text((string) $got) === MarkupRewriter::text((string) $expected['value']),
            'string' => (string) $got === (string) ($expected['value'] ?? ''),
            'boolean' => (bool) $got === (bool) ($expected['value'] ?? FALSE),
            'integer', 'number' => (string) $got === (string) ($expected['value'] ?? ''),
            'image', 'media', 'file', 'video', 'remote_video' => is_array($got) && (string) ($got['target_id'] ?? $got['entity_id'] ?? '') === (string) ($expected['target_id'] ?? ''),
            'link', 'url' => is_array($got) && (string) ($got['title'] ?? '') === (string) ($expected['title'] ?? '') && !empty($got['uri']),
            'heading' => is_array($got) && array_filter(ValueTransformer::HEADING_PARTS, static fn ($part) => trim((string) ($got[$part] ?? '')) !== (string) ($expected[$part]['value'] ?? '')) === [],
            default => $got !== NULL && $got !== '' && $got !== [],
          };
          if (!$ok) {
            $problems[] = sprintf('%s: prop "%s" did not read back as written (got %s).', $label, $name, substr(is_scalar($got) || $got instanceof \Stringable ? (string) $got : json_encode($got), 0, 80));
          }
        }
      }
    });
    return $problems;
  }

  /**
   * A fingerprint of a tree field's value.
   */
  private function fingerprint(array $value): string {
    return sha1(json_encode($value));
  }

}
