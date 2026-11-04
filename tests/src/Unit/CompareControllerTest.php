<?php

namespace Drupal\Tests\comparenator\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\comparenator\Controller\CompareController;
use Drupal\comparenator\Form\RefreshForm;
use Drupal\comparenator\Service\EnvironmentManager;
use Drupal\comparenator\Service\PixelDiffGenerator;
use Drupal\comparenator\Service\RevisionManager;
use Drupal\comparenator\Service\ScreenshotManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Verifies capture and refresh are wired through every environment view mode.
 */
class CompareControllerTest extends TestCase {

  #[DataProvider('modeProvider')]
  public function testCaptureToolbarAndUncachedViews(string $mode, string $theme): void {
    $screenshots = $this->createMock(ScreenshotManager::class);
    $screenshots->expects($this->once())->method('ensureComparison')
      ->with('test', 'laptop', 'prod', 'local', '/news')
      ->willReturn(['/reference.png?v=1', '/current.png?v=2']);
    $diff = $this->createMock(PixelDiffGenerator::class);
    if ($mode === 'diff') {
      $diff->expects($this->once())->method('generateDiff')->willReturn('/diff.png');
    }
    $controller = $this->controller($screenshots, $diff, $mode);
    $build = $controller->envCompare('test', 'laptop', 'prod', 'local');
    $this->assertSame($theme, $build['#theme']);
    $this->assertSame(0, $build['#cache']['max-age']);
    $toolbar = $build['#toolbar'];
    $this->assertSame('comparenator_toolbar', $toolbar['#theme']);
    $this->assertSame(['#markup' => 'refresh form'], $toolbar['#refresh']);
    foreach ($toolbar['#modes'] as $key => $link) {
      $this->assertSame('link', $link['#type']);
      $this->assertSame(['path' => '/news', 'mode' => $key], $link['#url']->getOption('query'));
    }
    $this->assertSame('phone', $toolbar['#sizes']['phone']['#url']->getRouteParameters()['size']);
    $this->assertSame(['path' => '/news', 'mode' => $mode], $toolbar['#sizes']['phone']['#url']->getOption('query'));
  }

  public static function modeProvider(): array {
    return [
      ['onion', 'comparenator_onion'],
      ['side', 'comparenator_side_by_side'],
      ['diff', 'comparenator_diff'],
    ];
  }

  public function testCaptureFailureKeepsRefreshAvailable(): void {
    $screenshots = $this->createMock(ScreenshotManager::class);
    $screenshots->method('ensureComparison')->willThrowException(new \RuntimeException('Browser failed'));
    $build = $this->controller($screenshots, $this->createMock(PixelDiffGenerator::class), 'onion')
      ->envCompare('test', 'laptop', 'prod', 'local');
    $this->assertSame('comparenator_missing', $build['#theme']);
    $this->assertStringContainsString('Unable to prepare screenshots', $build['#message']->getUntranslatedString());
    $this->assertNotEmpty($build['#toolbar']['#refresh']);
    $this->assertSame(0, $build['#cache']['max-age']);
  }

  private function controller(ScreenshotManager $screenshots, PixelDiffGenerator $diff, string $mode): CompareController {
    $stack = new RequestStack();
    $stack->push(Request::create('/comparenator/env/test/laptop/prod/local', 'GET', ['path' => '/news', 'mode' => $mode]));
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('screen_sizes')->willReturn(['laptop' => '1440x900', 'phone' => '375x812']);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $forms = $this->createMock(FormBuilderInterface::class);
    $forms->expects($this->once())->method('getForm')
      ->with(RefreshForm::class, ['identifier' => 'test', 'size' => 'laptop', 'reference' => 'prod', 'current' => 'local'], '/news', $mode)
      ->willReturn(['#markup' => 'refresh form']);
    $controller = new CompareController(
      $screenshots,
      $diff,
      $this->createMock(EnvironmentManager::class),
      $this->createMock(RevisionManager::class),
      $stack,
      $forms,
      $factory,
      new NullLogger(),
    );
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translate')->willReturnArgument(0);
    $controller->setStringTranslation($translation);
    return $controller;
  }

}
