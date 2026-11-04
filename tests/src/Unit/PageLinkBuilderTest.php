<?php

namespace Drupal\Tests\comparenator\Unit;

use Drupal\comparenator\Service\PageLinkBuilder;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Covers the every-page link and page-specific cache metadata.
 */
class PageLinkBuilderTest extends TestCase {

  #[DataProvider('pageProvider')]
  public function testPageLink(string $uri, array $server, string $path): void {
    $build = $this->builder(Request::create($uri, 'GET', [], [], [], $server))->build();
    $this->assertSame('link', $build['#type']);
    $this->assertTrue($build['#access']);
    $this->assertSame('comparenator.environment_compare', $build['#url']->getRouteName());
    $this->assertSame([
      'identifier' => $path === '/' ? 'frontpage' : hash('sha256', $path),
      'size' => 'laptop',
      'reference' => 'prod',
      'current' => 'local',
    ], $build['#url']->getRouteParameters());
    $this->assertSame(['mode' => 'onion', 'path' => $path], $build['#url']->getOption('query'));
    $this->assertSame('_blank', $build['#attributes']['target']);
    $this->assertSame('noopener noreferrer', $build['#attributes']['rel']);
    $this->assertSame(['comparenator/page_link'], $build['#attached']['library']);
    $this->assertContains('url.path', $build['#cache']['contexts']);
    $this->assertContains('url.query_args:path', $build['#cache']['contexts']);
    $this->assertContains('user.permissions', $build['#cache']['contexts']);
    $this->assertContains('config:comparenator.settings', $build['#cache']['tags']);
  }

  public static function pageProvider(): array {
    return [
      ['https://example.com/', [], '/'],
      ['https://example.com/news/example?preview=1', [], '/news/example'],
      ['https://example.com/admin/config', [], '/admin/config'],
      ['https://example.com/comparenator/env/id/laptop/prod/local?path=%2Fnews', [], '/news'],
      [
        'https://example.com/drupal/news/example',
        ['SCRIPT_NAME' => '/drupal/index.php', 'SCRIPT_FILENAME' => '/var/www/drupal/index.php'],
        '/news/example',
      ],
    ];
  }

  public function testAccessDenied(): void {
    $build = $this->builder(Request::create('/'), FALSE)->build();
    $this->assertFalse($build['#access']);
    $this->assertArrayNotHasKey('#url', $build);
    $this->assertContains('user.permissions', $build['#cache']['contexts']);
  }

  public function testFirstConfiguredViewport(): void {
    $build = $this->builder(Request::create('/'), TRUE, ['phone' => '375x812'])->build();
    $this->assertSame('phone', $build['#url']->getRouteParameters()['size']);
  }

  public function testNoConfiguredViewports(): void {
    $build = $this->builder(Request::create('/'), TRUE, [])->build();
    $this->assertSame('comparenator.page_compare', $build['#url']->getRouteName());
  }

  private function builder(Request $request, bool $allowed = TRUE, array $sizes = ['laptop' => '1440x900']): PageLinkBuilder {
    $stack = new RequestStack();
    $stack->push($request);
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('screen_sizes')->willReturn($sizes);
    $config->method('getCacheTags')->willReturn(['config:comparenator.settings']);
    $config->method('getCacheContexts')->willReturn([]);
    $config->method('getCacheMaxAge')->willReturn(-1);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('hasPermission')->with('view environment comparisons')->willReturn($allowed);
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translate')->willReturnArgument(0);
    return new PageLinkBuilder($stack, $factory, $account, $translation);
  }

}
