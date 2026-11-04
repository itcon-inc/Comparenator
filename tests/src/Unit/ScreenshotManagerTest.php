<?php

namespace Drupal\Tests\comparenator\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\MemoryStorage;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\comparenator\Service\BrowserCapture;
use Drupal\comparenator\Service\ScreenshotManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Exercises capture orchestration against actual temporary screenshot files.
 */
class ScreenshotManagerTest extends TestCase {

  private string $directory;
  private array $settings;
  private array $calls = [];
  private string $fingerprint = 'original';
  private bool $fail = FALSE;
  private ScreenshotManager $manager;

  protected function setUp(): void {
    $this->directory = sys_get_temp_dir() . '/comparenator-test-' . bin2hex(random_bytes(8));
    mkdir($this->directory);
    $this->settings = [
      'reference_site_url' => 'https://reference.example/drupal',
      'current_site_url' => 'https://local.example/drupal',
      'screen_sizes' => ['laptop' => '1440x900'],
    ];
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn ($key) => $this->settings[$key] ?? NULL);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $files = $this->createMock(FileSystemInterface::class);
    $files->method('prepareDirectory')->willReturnCallback(static function (&$directory) {
      return is_dir($directory) || mkdir($directory, 0777, TRUE);
    });
    $files->method('tempnam')->willReturnCallback(static fn ($directory, $prefix) => tempnam($directory, $prefix));
    $files->method('move')->willReturnCallback(static function ($source, $destination) {
      if (!rename($source, $destination)) {
        throw new \RuntimeException('Test file move failed.');
      }
      return $destination;
    });
    $files->method('delete')->willReturnCallback(static fn ($path) => unlink($path));
    $urls = $this->createMock(FileUrlGeneratorInterface::class);
    $urls->method('generateString')->willReturnCallback(static fn ($uri) => '/files/' . basename($uri));
    $browser = $this->createMock(BrowserCapture::class);
    $browser->method('capture')->willReturnCallback(function ($url, $width, $height, $previous, $force) {
      $this->calls[] = [$url, $width, $height, $previous, $force];
      if ($this->fail) {
        throw new \RuntimeException('Test browser failure');
      }
      return [
        'fingerprint' => $this->fingerprint,
        'data' => $force || $previous !== $this->fingerprint ? "\x89PNG\r\n\x1a\n" . $this->fingerprint : NULL,
      ];
    });
    $storage = new MemoryStorage('comparenator.captures');
    $keyvalue = $this->createMock(KeyValueFactoryInterface::class);
    $keyvalue->method('get')->willReturn($storage);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    $lock->method('release')->willReturnCallback(function () {
      $this->calls[] = ['release'];
    });
    $this->manager = new class($files, $factory, new NullLogger(), $urls, $browser, $keyvalue, $lock) extends ScreenshotManager {
      public string $directory;

      protected function getScreenshotPath(string $environment, string $identifier, string $size): string {
        $uri = parent::getScreenshotPath($environment, $identifier, $size);
        return $this->directory . '/' . substr($uri, strlen('public://'));
      }
    };
    $this->manager->directory = $this->directory;
  }

  protected function tearDown(): void {
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
      $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->directory);
  }

  public function testMissingUnchangedChangedAndForcedCaptures(): void {
    $first = $this->compare();
    $this->assertCount(3, $this->calls);
    $this->assertSame('https://reference.example/drupal/news/example', $this->calls[0][0]);
    $this->assertSame('https://local.example/drupal/news/example', $this->calls[1][0]);
    $this->assertSame([1440, 900], array_slice($this->calls[1], 1, 2));
    $this->assertTrue($this->calls[0][4]);

    $this->calls = [];
    $this->assertSame($first, $this->compare());
    $this->assertCount(2, $this->calls, 'only local content is checked after the first capture');
    $this->assertSame('original', $this->calls[0][3]);
    $this->assertFalse($this->calls[0][4]);

    $this->fingerprint = 'updated';
    $updated = $this->compare();
    $this->assertSame($first[0], $updated[0], 'reference remains unchanged');
    $this->assertNotSame($first[1], $updated[1], 'updated local PNG has a new cache key');

    $this->calls = [];
    $forced = $this->compare(TRUE);
    $this->assertCount(3, $this->calls);
    $this->assertTrue($this->calls[0][4]);
    $this->assertTrue($this->calls[1][4]);
    $this->assertNotSame($first[0], $forced[0], 'forced refresh replaces reference too');
  }

  public function testViewportChangeRecapturesBothImages(): void {
    $this->compare();
    $this->calls = [];
    $this->settings['screen_sizes']['laptop'] = '800x600';
    $this->compare();
    $this->assertCount(3, $this->calls);
    $this->assertSame([800, 600], array_slice($this->calls[0], 1, 2));
    $this->assertTrue($this->calls[0][4]);
    $this->assertTrue($this->calls[1][4]);
  }

  public function testMissingPngWithExistingMetadataIsRecaptured(): void {
    $this->compare();
    unlink($this->directory . '/comparenator/local/laptop/news_example.png');
    $this->calls = [];
    $this->compare();
    $this->assertTrue($this->calls[0][4]);
    $this->assertNull($this->calls[0][3]);
  }

  public function testFailureDoesNotReturnSuccessAndReleasesLock(): void {
    $this->fail = TRUE;
    try {
      $this->compare();
      $this->fail('Capture failure must propagate.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame('Test browser failure', $exception->getMessage());
    }
    $this->assertSame(['release'], $this->calls[1]);
    $this->assertFalse($this->manager->getEnvironmentScreenshotUrl('prod', 'news_example', 'laptop'));
  }

  #[DataProvider('invalidPaths')]
  public function testRejectsUnsafeSourcePaths(string $path): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->manager->ensureComparison('test', 'laptop', 'prod', 'local', $path);
  }

  public static function invalidPaths(): array {
    return array_map(static fn ($path) => [$path], [
      'https://elsewhere.example/', '//elsewhere.example/', '/%2felsewhere.example/',
      '/../outside', '/%2e%2e/outside', '/page?query=1', '/page#fragment',
      '/comparenator/env/test', '/path\\outside',
    ]);
  }

  public function testRejectsUnknownViewport(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->manager->ensureComparison('test', 'unknown', 'prod', 'local', '/');
  }

  public function testRejectsTraversalInFileKeys(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->manager->getEnvironmentScreenshotUrl('prod', '../outside', 'laptop');
  }

  private function compare(bool $force = FALSE): array {
    return $this->manager->ensureComparison('news_example', 'laptop', 'prod', 'local', '/news/example', $force);
  }

}
