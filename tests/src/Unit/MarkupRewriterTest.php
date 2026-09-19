<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_migrate\Unit;

use Drupal\neo_migrate\Content\MarkupRewriter;
use Drupal\neo_migrate\Content\TreeConverter;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\neo_migrate\Content\MarkupRewriter
 * @group neo_migrate
 */
final class MarkupRewriterTest extends UnitTestCase {

  private const RULES = [
    'classes' => [
      'button' => 'btn btn-primary',
      'button outline' => 'btn btn-outline-primary',
    ],
    'unwrap' => ['span'],
    'drop' => ['data-list-item-id'],
  ];

  /**
   * Class lists are matched as sets, in any order, and only whole sets.
   *
   * @covers ::rewrite
   */
  public function testClasses(): void {
    $rewriter = new MarkupRewriter();
    $this->assertSame('<p><a class="btn btn-primary" href="/a">A</a></p>', $rewriter->rewrite('<p><a class="button" href="/a">A</a></p>', self::RULES));
    $this->assertSame('<p><a class="btn btn-outline-primary" href="/b">B</a></p>', $rewriter->rewrite('<p><a class="outline  button" href="/b">B</a></p>', self::RULES));
    $this->assertSame('<p><a class="button large" href="/c">C</a></p>', $rewriter->rewrite('<p><a class="button large" href="/c">C</a></p>', self::RULES));
  }

  /**
   * Nested wrappers are unwrapped and listed attributes dropped, text kept.
   *
   * @covers ::rewrite
   */
  public function testUnwrapAndDrop(): void {
    $rewriter = new MarkupRewriter();
    $html = '<p><span><span><strong>Rest</strong> assured</span></span></p><ul><li data-list-item-id="e1">One</li></ul>';
    $this->assertSame('<p><strong>Rest</strong> assured</p><ul><li>One</li></ul>', $rewriter->rewrite($html, self::RULES));
    $this->assertSame('', $rewriter->rewrite("  \n", self::RULES));
  }

  /**
   * Visible text ignores tags, entities and runs of whitespace.
   *
   * @covers ::text
   */
  public function testText(): void {
    $this->assertSame('Q: Why? A: Because.', MarkupRewriter::text("<p><strong>Q: Why?</strong><br>\nA:&nbsp;Because.</p>"));
  }

  /**
   * A derived UUID is stable, well formed, and differs by name.
   *
   * @covers \Drupal\neo_migrate\Content\TreeConverter::derivedUuid
   */
  public function testDerivedUuid(): void {
    $uuid = TreeConverter::derivedUuid('node:7:title_s1');
    $this->assertSame($uuid, TreeConverter::derivedUuid('node:7:title_s1'));
    $this->assertNotSame($uuid, TreeConverter::derivedUuid('node:8:title_s1'));
    $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
  }

}
