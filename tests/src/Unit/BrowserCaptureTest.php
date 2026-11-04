<?php

namespace Drupal\Tests\comparenator\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\File\FileSystemInterface;
use Drupal\comparenator\Service\BrowserCapture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Exercises the PHP/Node protocol with real Chromium and a local HTTP fixture.
 */
class BrowserCaptureTest extends TestCase {

  public function testCaptureProtocolAndCleanup(): void {
    if (!is_dir(dirname(__DIR__, 3) . '/node_modules/playwright')) {
      $this->markTestSkipped('Run npm ci and npm run browser:install to test the PHP/browser bridge.');
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, FALSE);
    fclose($socket);
    $server = new Process([PHP_BINARY, '-S', $address, dirname(__DIR__, 2) . '/fixtures/page.php']);
    $server->start();
    $temporary_files = [];
    $files = $this->createMock(FileSystemInterface::class);
    $files->method('tempnam')->willReturnCallback(static function () use (&$temporary_files) {
      $path = tempnam(sys_get_temp_dir(), 'comparenator-bridge-test-');
      $temporary_files[] = $path;
      return $path;
    });
    $files->method('realpath')->willReturnCallback(static fn ($path) => realpath($path));
    $files->method('delete')->willReturnCallback(static fn ($path) => unlink($path));
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('node_binary')->willReturn('node');
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $capture = new BrowserCapture($files, $factory);
    try {
      $ready = $server->waitUntil(static fn ($type, $output) => str_contains($output, 'Development Server'));
      $this->assertTrue($ready, $server->getErrorOutput());
      $first = $capture->capture("http://$address/", 375, 812);
      $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $first['data']);
      $this->assertSame(64, strlen($first['fingerprint']));
      $second = $capture->capture("http://$address/", 375, 812, $first['fingerprint']);
      $this->assertNull($second['data']);
      $forced = $capture->capture("http://$address/", 375, 812, $first['fingerprint'], TRUE);
      $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $forced['data']);
      $this->assertSame($first['fingerprint'], $forced['fingerprint']);
      try {
        $capture->capture("http://$address/error", 375, 812);
        $this->fail('HTTP errors must not return a successful capture.');
      }
      catch (\RuntimeException $exception) {
        $this->assertStringContainsString('HTTP 500', $exception->getMessage());
      }
      foreach ($temporary_files as $path) {
        $this->assertFileDoesNotExist($path, 'capture output must be removed after success and failure');
      }
    }
    finally {
      $server->stop();
      foreach ($temporary_files as $path) {
        if (is_file($path)) {
          unlink($path);
        }
      }
    }
  }

}
