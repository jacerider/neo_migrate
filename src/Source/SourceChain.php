<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Source;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Every source adapter that applies to the site, read as one.
 *
 * A site built with one system gets that adapter alone, under its own id; a
 * site with several (exo_alchemist pages beside paragraph fields) gets their
 * hosts together, each field read by the adapter that found it.
 */
final class SourceChain implements SourceAdapterInterface {

  use RevisionFreeFingerprintTrait;

  /**
   * @param iterable<\Drupal\neo_migrate\Source\SourceAdapterInterface> $adapters
   *   Every known adapter, in order of precedence.
   */
  public function __construct(
    private readonly iterable $adapters,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return implode('+', array_map(static fn (SourceAdapterInterface $adapter) => $adapter->id(), $this->active())) ?: 'none';
  }

  /**
   * {@inheritdoc}
   */
  public function applies(): bool {
    return (bool) $this->active();
  }

  /**
   * {@inheritdoc}
   */
  public function hosts(): array {
    $hosts = [];
    foreach ($this->active() as $adapter) {
      foreach ($adapter->hosts() as $host) {
        $hosts[] = $host + ['source' => $adapter->id()];
      }
    }
    return $hosts;
  }

  /**
   * {@inheritdoc}
   */
  public function tree(ContentEntityInterface $host, string $field): array {
    foreach ($this->active() as $adapter) {
      foreach ($adapter->hosts() as $known) {
        if ($known['entity_type'] === $host->getEntityTypeId() && $known['field'] === $field) {
          return $adapter->tree($host, $field);
        }
      }
    }
    return [];
  }

  /**
   * The adapters that apply to this site.
   *
   * @return list<\Drupal\neo_migrate\Source\SourceAdapterInterface>
   */
  public function active(): array {
    $active = [];
    foreach ($this->adapters as $adapter) {
      if ($adapter->applies()) {
        $active[] = $adapter;
      }
    }
    return $active;
  }

}
