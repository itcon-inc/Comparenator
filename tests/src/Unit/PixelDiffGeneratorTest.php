<?php

namespace Drupal\Tests\comparenator\Unit;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\StreamWrapper\LocalStream;
use Drupal\comparenator\Service\PixelDiffGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Verifies refreshed source images cannot reuse an obsolete diff.
 */
class PixelDiffGeneratorTest extends TestCase {

  private string $directory;
  private PixelDiffGenerator $generator;

  protected function setUp(): void {
    $this->directory = sys_get_temp_dir() . '/comparenator-diff-test-' . bin2hex(random_bytes(8));
    mkdir($this->directory . '/comparenator/diff', 0777, TRUE);
    TestPublicStream::$directory = $this->directory;
    stream_wrapper_register('public', TestPublicStream::class);
    $files = $this->createMock(FileSystemInterface::class);
    $files->method('prepareDirectory')->willReturn(TRUE);
    $files->method('tempnam')->willReturnCallback(fn () => tempnam($this->directory . '/comparenator/diff', 'diff-'));
    $files->method('move')->willReturnCallback(function ($source, $destination) {
      rename($source, str_replace('public://', $this->directory . '/', $destination));
      return $destination;
    });
    $files->method('delete')->willReturnCallback(static fn ($path) => unlink($path));
    $urls = $this->createMock(FileUrlGeneratorInterface::class);
    $urls->method('generateString')->willReturnCallback(static fn ($uri) => str_replace('public://', '/drupal/files/', $uri));
    $this->generator = new PixelDiffGenerator($files, $urls);
  }

  protected function tearDown(): void {
    stream_wrapper_unregister('public');
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
      $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->directory);
  }

  public function testUnequalDimensionsAndContentAddressedDiff(): void {
    $this->png('reference', 1, 1, 255);
    $this->png('current', 2, 1, 255);
    $first = $this->generator->generateDiff('/drupal/files/comparenator/reference.png?v=old', '/drupal/files/comparenator/current.png?v=old', 'test', 'laptop');
    $uri = str_replace('/drupal/files/', 'public://', $first);
    $image = imagecreatefrompng($uri);
    $this->assertSame(2, imagesx($image));
    $this->assertSame(0xFFFFFF, imagecolorat($image, 0, 0));
    $this->assertSame(0xFF0000, imagecolorat($image, 1, 0), 'pixels outside the smaller image must be marked');
    $this->png('current', 2, 1, 0);
    $second = $this->generator->generateDiff('/drupal/files/comparenator/reference.png?v=new', '/drupal/files/comparenator/current.png?v=new', 'test', 'laptop');
    $this->assertNotSame($first, $second);
    $this->assertSame($second, $this->generator->generateDiff('/drupal/files/comparenator/reference.png', '/drupal/files/comparenator/current.png', 'test', 'laptop'));
  }

  private function png(string $name, int $width, int $height, int $shade): void {
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, $shade, $shade, $shade));
    imagepng($image, "public://comparenator/$name.png");
  }

}

/**
 * Maps Drupal public URIs to an isolated temporary test directory.
 */
class TestPublicStream extends LocalStream {

  public static string $directory;

  public function getDirectoryPath() {
    return self::$directory;
  }

  public function getName() {
    return 'Test public files';
  }

  public function getDescription() {
    return 'Test public files';
  }

  public function getExternalUrl() {
    return '/drupal/files/' . $this->getTarget();
  }

}
