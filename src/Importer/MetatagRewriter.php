<?php

declare(strict_types=1);

namespace Drupal\neo_migrate\Importer;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\neo_migrate\LegacyCatalog;

/**
 * Swaps legacy tokens for Neo ones in the metatag defaults. Creates config.
 *
 * The replacements are the `token_map` in neo_migrate.legacy.yml. Run it at
 * the cutover: the Neo tokens read the component tree, which only renders on
 * the Neo front theme, and until the cutover the legacy tokens still work.
 * Tokens left over that belong to a legacy module are reported, because
 * uninstalling that module would leave them printing nothing.
 */
final class MetatagRewriter {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LegacyCatalog $catalog,
  ) {}

  /**
   * Rewrites every metatag default.
   *
   * @return list<array{config: string, tag: string, from: string, to: string}>
   *   One row per changed tag; `to` is empty for a leftover legacy token.
   */
  public function rewrite(bool $dryRun = FALSE): array {
    $map = $this->catalog->get('token_map');
    $fragments = $this->catalog->get('tokens');
    $report = [];
    foreach ($this->configFactory->listAll('metatag.metatag_defaults.') as $name) {
      $config = $this->configFactory->getEditable($name);
      $tags = $config->get('tags') ?? [];
      $changed = FALSE;
      foreach ($tags as $tag => $value) {
        if (!is_string($value)) {
          continue;
        }
        $new = strtr($value, $map);
        if ($new !== $value) {
          $report[] = ['config' => $name, 'tag' => $tag, 'from' => $value, 'to' => $new];
          $tags[$tag] = $new;
          $changed = TRUE;
        }
        foreach ($fragments as $fragment) {
          if (str_contains($new, $fragment)) {
            $report[] = ['config' => $name, 'tag' => $tag, 'from' => $new, 'to' => ''];
            break;
          }
        }
      }
      if ($changed && !$dryRun) {
        $config->set('tags', $tags)->save();
      }
    }
    return $report;
  }

}
