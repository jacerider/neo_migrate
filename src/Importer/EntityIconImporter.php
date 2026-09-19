<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Importer;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\neo_icon\IconEntityTypeManager;
use Drupal\neo_migrate\IconNameResolver;

/**
 * Moves the icons micon gave content types and vocabularies into neo_icon.
 *
 * micon_content_type and micon_vocabulary keep a bundle's icon as a third
 * party setting; neo_icon keeps it as its own (`entity:<type>:<id>`), which
 * the admin and the toolbar's create menu show. Icons are read from the
 * bundle while the micon module is installed and from the sync directory once
 * it is not — so run it before the export that drops them. Font Awesome 4
 * names map onto neo_icon's libraries where they can, and otherwise stay as
 * they were (the site's imported `fa` library holds them). Creates config.
 */
final class EntityIconImporter {

  /**
   * The micon module holding each entity type's icon.
   */
  private const SOURCES = [
    'node_type' => 'micon_content_type',
    'taxonomy_vocabulary' => 'micon_vocabulary',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly StorageInterface $syncStorage,
    private readonly IconNameResolver $resolver,
    private readonly ?IconEntityTypeManager $icons = NULL,
  ) {}

  /**
   * Copies every bundle icon.
   *
   * @return list<array{entity: string, legacy: string, icon: string, action: string}>
   *   One row per bundle that had a legacy icon.
   */
  public function import(bool $dryRun = FALSE): array {
    if ($this->icons === NULL) {
      throw new \RuntimeException('neo_icon is not installed.');
    }
    $report = [];
    foreach (self::SOURCES as $entityTypeId => $module) {
      if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
        continue;
      }
      foreach ($this->entityTypeManager->getStorage($entityTypeId)->loadMultiple() as $entity) {
        /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface $entity */
        $legacy = $this->legacyIcon($entity, $module);
        if ($legacy === '') {
          continue;
        }
        $icon = $this->resolver->resolve($legacy) ?? $legacy;
        $current = $this->icons->getEntityIcon($entity);
        $row = ['entity' => $entity->getConfigDependencyName(), 'legacy' => $legacy, 'icon' => $icon, 'action' => $current === $icon ? 'unchanged' : ($current === '' ? 'set' : 'replaced')];
        if (!$dryRun && $current !== $icon) {
          $this->icons->setEntityIcon($entity, $icon);
          $entity->save();
        }
        $report[] = $row;
      }
    }
    return $report;
  }

  /**
   * The icon micon gave a bundle, from the bundle or the sync directory.
   */
  private function legacyIcon(ConfigEntityInterface $entity, string $module): string {
    if ($this->moduleHandler->moduleExists($module)) {
      return (string) $entity->getThirdPartySetting($module, 'icon', '');
    }
    $synced = $this->syncStorage->read($entity->getConfigDependencyName()) ?: [];
    return (string) ($synced['third_party_settings'][$module]['icon'] ?? '');
  }

}
