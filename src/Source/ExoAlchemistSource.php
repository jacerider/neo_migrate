<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Source;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\neo_migrate\FieldNormalizer;

/**
 * Reads exo_alchemist components placed with Layout Builder.
 *
 * An exo_alchemist page is a Layout Builder override: sections whose regions
 * hold inline blocks, each a block_content revision of a component bundle
 * (exo_<hash>). A page without an override renders its display's default
 * layout, so its tree is read from there and marked `default`.
 *
 * Items are named by component id (mna_theme_hero_style_1), not bundle, and
 * their fields by component field name (title), not storage (exo_field_<hash>),
 * so the mapping reads like the component definitions. Each field also carries
 * its exo field type (`component_type`) and whether the editor hid it
 * (`hidden`): a hidden field keeps its value but does not render. The
 * component's modifiers (its style options) are its `behavior`; where it sits
 * on the page is its `placement`.
 */
final class ExoAlchemistSource implements SourceAdapterInterface {

  use RevisionFreeFingerprintTrait;

  /**
   * The field Layout Builder stores a per-entity layout in.
   */
  public const LAYOUT_FIELD = 'layout_builder__layout';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly FieldNormalizer $normalizer,
    private readonly ?object $componentManager = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'exo_alchemist';
  }

  /**
   * {@inheritdoc}
   */
  public function applies(): bool {
    return $this->componentManager !== NULL
      && $this->moduleHandler->moduleExists('exo_alchemist')
      && $this->moduleHandler->moduleExists('layout_builder');
  }

  /**
   * {@inheritdoc}
   *
   * Every bundle whose display lets an entity override its layout.
   */
  public function hosts(): array {
    if (!$this->applies()) {
      return [];
    }
    $bundles = [];
    foreach ($this->entityTypeManager->getStorage('entity_view_display')->loadMultiple() as $display) {
      if ($display instanceof LayoutEntityDisplayInterface && $display->status() && $display->isOverridable()) {
        $bundles[$display->getTargetEntityTypeId()][$display->getTargetBundle()] = TRUE;
      }
    }
    ksort($bundles);
    $hosts = [];
    foreach ($bundles as $entityTypeId => $names) {
      $names = array_keys($names);
      sort($names);
      $hosts[] = ['entity_type' => $entityTypeId, 'field' => self::LAYOUT_FIELD, 'bundles' => $names];
    }
    return $hosts;
  }

  /**
   * {@inheritdoc}
   */
  public function tree(ContentEntityInterface $host, string $field): array {
    $default = !$host->hasField($field) || $host->get($field)->isEmpty();
    $sections = $default ? $this->defaultSections($host) : array_map(static fn ($item) => $item->section, iterator_to_array($host->get($field)));
    $tree = [];
    foreach (array_values($sections) as $delta => $section) {
      foreach ($this->regions($section) as $region) {
        foreach ($section->getComponentsByRegion($region) as $component) {
          $placement = [
            'section' => $delta,
            'layout' => $section->getLayoutId(),
            'region' => $region,
            'weight' => $component->getWeight(),
            'default' => $default,
          ];
          $tree[] = $this->component($component) + ['placement' => $placement];
        }
      }
    }
    return $tree;
  }

  /**
   * One component bundle's definition id, or NULL when it is not a component.
   */
  public function componentId(string $bundle): ?string {
    if (!$this->applies() || !($type = $this->entityTypeManager->getStorage('block_content_type')->load($bundle))) {
      return NULL;
    }
    return $this->componentManager->getEntityBundleComponentDefinition($type)?->id();
  }

  /**
   * Every installed component, keyed by its block_content bundle.
   *
   * A bundle with no definition is reported with `id: NULL`: its rows cannot
   * render and must be deleted before exo_alchemist is uninstalled.
   *
   * @return array<string, array{id: ?string, label: string, provider: ?string, fields: array<string, array>, modifiers: list<string>}>
   */
  public function definitions(): array {
    if (!$this->applies()) {
      return [];
    }
    $found = [];
    foreach ($this->entityTypeManager->getStorage('block_content_type')->loadMultiple() as $bundle => $type) {
      $definition = $this->componentManager->getEntityBundleComponentDefinition($type);
      if (!$definition && !str_starts_with($bundle, 'exo_')) {
        continue;
      }
      $fields = [];
      foreach ($definition?->getFields() ?? [] as $field) {
        $fields[$field->getName()] = [
          'type' => $field->getType(),
          'cardinality' => (int) $field->getCardinality(),
          'computed' => (bool) $field->isComputed(),
        ];
      }
      $found[$bundle] = [
        'id' => $definition?->id(),
        'label' => (string) ($definition?->getLabel() ?? $type->label()),
        'provider' => $definition?->getProvider(),
        'fields' => $fields,
        'modifiers' => array_keys($definition?->getModifiers() ?? []),
      ];
    }
    ksort($found);
    return $found;
  }

  /**
   * The sections an entity without an override renders.
   *
   * @return list<\Drupal\layout_builder\Section>
   */
  private function defaultSections(ContentEntityInterface $host): array {
    $storage = $this->entityTypeManager->getStorage('entity_view_display');
    foreach (['full', 'default'] as $mode) {
      $display = $storage->load($host->getEntityTypeId() . '.' . $host->bundle() . '.' . $mode);
      if ($display instanceof LayoutEntityDisplayInterface && $display->status() && $display->isLayoutBuilderEnabled()) {
        return $display->getSections();
      }
    }
    return [];
  }

  /**
   * A section's regions in the order its layout renders them.
   *
   * @return list<string>
   */
  private function regions(Section $section): array {
    $regions = $section->getLayout()->getPluginDefinition()->getRegionNames();
    foreach ($section->getComponents() as $component) {
      if (!in_array($component->getRegion(), $regions, TRUE)) {
        $regions[] = $component->getRegion();
      }
    }
    return $regions;
  }

  /**
   * One placed block: an inline component, or any other block plugin.
   */
  private function component(SectionComponent $component): array {
    $plugin = $component->getPluginId();
    $configuration = $component->get('configuration') ?? [];
    if (!str_starts_with($plugin, 'inline_block:')) {
      return [
        'bundle' => 'block:' . $plugin,
        'id' => 0,
        'revision' => 0,
        'uuid' => $component->getUuid(),
        'status' => TRUE,
        'behavior' => ['configuration' => $configuration],
        'fields' => [],
      ];
    }
    $block = NULL;
    if (!empty($configuration['block_serialized'])) {
      $block = unserialize($configuration['block_serialized']);
    }
    elseif (!empty($configuration['block_revision_id'])) {
      $block = $this->entityTypeManager->getStorage('block_content')->loadRevision($configuration['block_revision_id']);
    }
    return $block instanceof ContentEntityInterface
      ? $this->item($block)
      : ['missing' => TRUE, 'plugin' => $plugin, 'target_revision_id' => $configuration['block_revision_id'] ?? NULL];
  }

  /**
   * One component and every component nested in its sequences.
   */
  private function item(ContentEntityInterface $block): array {
    $definition = $this->componentManager->getEntityComponentDefinition($block);
    $data = $block->hasField('alchemist_data') && !$block->get('alchemist_data')->isEmpty() ? $block->get('alchemist_data')->first()->getValue() : [];
    $hidden = array_values((array) ($data['hidden'] ?? []));

    // Component field name => [storage field name, exo field type].
    $names = [];
    if ($definition) {
      foreach ($definition->getFields() as $field) {
        $names[$field->getName()] = [$field->getFieldName(), $field->getType()];
      }
    }
    else {
      foreach ($block->getFieldDefinitions() as $name => $fieldDefinition) {
        if (!$fieldDefinition->getFieldStorageDefinition()->isBaseField()) {
          $names[$name] = [$name, NULL];
        }
      }
    }

    $fields = [];
    foreach ($names as $name => [$storageName, $type]) {
      if (!$block->hasField($storageName)) {
        // Computed: the component fills it at render time, nothing is stored.
        continue;
      }
      $items = $block->get($storageName);
      $fieldDefinition = $items->getFieldDefinition();
      if ($fieldDefinition->getType() === 'entity_reference_revisions' && $fieldDefinition->getSetting('target_type') === 'block_content') {
        $children = [];
        foreach ($items as $childItem) {
          $children[] = $childItem->entity instanceof ContentEntityInterface ? $this->item($childItem->entity) : [
            'missing' => TRUE,
            'target_id' => $childItem->target_id,
            'target_revision_id' => $childItem->target_revision_id,
          ];
        }
        $fields[$name] = ['type' => 'entity_reference_revisions', 'children' => $children];
      }
      else {
        $fields[$name] = $this->normalizer->normalize($items);
      }
      if ($type !== NULL) {
        $fields[$name]['component_type'] = $type;
      }
      if (in_array($name, $hidden, TRUE)) {
        $fields[$name]['hidden'] = TRUE;
      }
    }
    ksort($fields);

    $modifiers = $block->hasField('exo_modifiers') && !$block->get('exo_modifiers')->isEmpty()
      ? ($block->get('exo_modifiers')->first()->getValue()['value'] ?? [])
      : [];
    return [
      'bundle' => $definition?->id() ?? $block->bundle(),
      'id' => (int) $block->id(),
      'revision' => (int) $block->getRevisionId(),
      'uuid' => $block->uuid(),
      'status' => $block instanceof EntityPublishedInterface ? $block->isPublished() : TRUE,
      'behavior' => $modifiers ? ['modifiers' => $modifiers] : [],
      'fields' => $fields,
    ];
  }

}
