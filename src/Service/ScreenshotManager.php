<?php

namespace Drupal\comparenator\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Psr\Log\LoggerInterface;

/**
 * Stores screenshots and captures missing or changed environment pages.
 */
class ScreenshotManager {

  public function __construct(
    protected FileSystemInterface $fileSystem,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected BrowserCapture $browser,
    protected KeyValueFactoryInterface $keyValueFactory,
    protected LockBackendInterface $lock,
  ) {}

  /**
   * Ensures reference and local screenshots exist for this page.
   */
  public function ensureComparison(string $identifier, string $size, string $reference, string $current, string $path, bool $force = FALSE): array {
    if ($reference !== 'prod' || $current !== 'local') {
      throw new \InvalidArgumentException('Automatic capture supports the prod/local environment pair.');
    }
    $config = $this->configFactory->get('comparenator.settings');
    $reference_base = rtrim((string) $config->get('reference_site_url'), '/');
    $current_base = rtrim((string) $config->get('current_site_url'), '/');
    foreach ([$reference_base, $current_base] as $base) {
      if (!filter_var($base, FILTER_VALIDATE_URL) || !in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], TRUE) || parse_url($base, PHP_URL_QUERY) !== NULL || parse_url($base, PHP_URL_FRAGMENT) !== NULL || parse_url($base, PHP_URL_USER) !== NULL) {
        throw new \InvalidArgumentException('Configure valid HTTP(S) reference and local base URLs without credentials, queries or fragments.');
      }
    }
    $dimensions = ($config->get('screen_sizes') ?? [])[$size] ?? '';
    if (!preg_match('/^([1-9][0-9]{0,3})x([1-9][0-9]{0,3})$/D', $dimensions, $matches) || (int) $matches[1] > 4096 || (int) $matches[2] > 4096) {
      throw new \InvalidArgumentException('Select a configured viewport between 1 and 4096 pixels per dimension.');
    }
    $decoded_path = rawurldecode($path);
    if (!str_starts_with($path, '/') || str_starts_with($decoded_path, '//') || preg_match('/[?#\\\\\x00-\x20]/', $decoded_path) || preg_match('@(?:^|/)\.\.?(?:/|$)@', $decoded_path) || preg_match('@^/comparenator(?:/|$)@', $decoded_path)) {
      throw new \InvalidArgumentException('The capture path must be a site-relative path without a query string.');
    }
    $this->getScreenshotPath($reference, $identifier, $size);
    $this->getScreenshotPath($current, $identifier, $size);
    $key = hash('sha256', serialize([$identifier, $size, $reference, $current]));
    $lock_name = 'comparenator:' . $key;
    if (!$this->lock->acquire($lock_name, 240)) {
      throw new \RuntimeException('This comparison is already being captured. Try again shortly.');
    }
    try {
      $store = $this->keyValueFactory->get('comparenator.captures');
      foreach ([$reference => $reference_base, $current => $current_base] as $environment => $base) {
        $uri = $this->getScreenshotPath($environment, $identifier, $size);
        $metadata_key = hash('sha256', $uri);
        $metadata = $store->get($metadata_key, []);
        $url = $base . $path;
        $exists = is_file($uri);
        $source_changed = ($metadata['url'] ?? NULL) !== $url || ($metadata['dimensions'] ?? NULL) !== $dimensions;
        if ($environment === $reference && $exists && !$force && !$source_changed) {
          continue;
        }
        $previous = $exists && !$source_changed ? ($metadata['fingerprint'] ?? NULL) : NULL;
        $capture = $this->browser->capture($url, (int) $matches[1], (int) $matches[2], $previous, $force || !$exists || $source_changed);
        if ($capture['data'] !== NULL) {
          $this->saveScreenshot($environment, $identifier, $size, $capture['data']);
        }
        $store->set($metadata_key, [
          'url' => $url,
          'dimensions' => $dimensions,
          'fingerprint' => $capture['fingerprint'],
        ]);
      }
      return [
        $this->getEnvironmentScreenshotUrl($reference, $identifier, $size),
        $this->getEnvironmentScreenshotUrl($current, $identifier, $size),
      ];
    }
    catch (\RuntimeException $exception) {
      $this->logger->error('Screenshot capture failed: @message', ['@message' => $exception->getMessage()]);
      throw $exception;
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Builds a filesystem-safe screenshot URI.
   */
  protected function getScreenshotPath(string $environment, string $identifier, string $size): string {
    foreach ([$environment, $identifier, $size] as $component) {
      if (!preg_match('/^[A-Za-z0-9_-]+$/D', $component)) {
        throw new \InvalidArgumentException('Screenshot keys may contain only letters, numbers, underscores and hyphens.');
      }
    }
    return "public://comparenator/$environment/$size/$identifier.png";
  }

  /**
   * Returns a cache-busted public URL, or FALSE if the PNG is missing.
   */
  public function getEnvironmentScreenshotUrl($env, $identifier, $size): string|false {
    $uri = $this->getScreenshotPath($env, $identifier, $size);
    if (!is_file($uri)) {
      return FALSE;
    }
    $hash = hash_file('sha256', $uri);
    if ($hash === FALSE) {
      throw new \RuntimeException('Unable to read a screenshot.');
    }
    return $this->fileUrlGenerator->generateString($uri) . '?v=' . $hash;
  }

  /**
   * Atomically replaces a screenshot after validating capture output.
   */
  public function saveScreenshot($env, $identifier, $size, $data): string {
    if (!is_string($data) || !str_starts_with($data, "\x89PNG\r\n\x1a\n")) {
      throw new \InvalidArgumentException('Screenshot data must be PNG.');
    }
    $uri = $this->getScreenshotPath($env, $identifier, $size);
    $directory = dirname($uri);
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY)) {
      throw new \RuntimeException('Unable to prepare screenshot storage.');
    }
    $temporary = $this->fileSystem->tempnam($directory, 'capture-');
    if ($temporary === FALSE) {
      throw new \RuntimeException('Unable to allocate screenshot storage.');
    }
    try {
      if (file_put_contents($temporary, $data) !== strlen($data)) {
        throw new \RuntimeException('Unable to write the screenshot.');
      }
      $this->fileSystem->move($temporary, $uri, FileExists::Replace);
    }
    finally {
      if (is_file($temporary)) {
        $this->fileSystem->delete($temporary);
      }
    }
    return $uri;
  }

  /**
   * Purge screenshots older than retention_days.
   */
  public function purgeOldScreenshots(): void {
    $days = max(1, (int) ($this->configFactory->get('comparenator.settings')->get('retention_days') ?? 7));
    $base = $this->fileSystem->realpath('public://comparenator');
    if (!$base || !is_dir($base)) {
      return;
    }
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      if (!$file->isLink() && $file->isFile() && $file->getExtension() === 'png' && $file->getMTime() < time() - $days * 86400) {
        $this->fileSystem->delete($file->getPathname());
        $relative = substr($file->getPathname(), strlen($base) + 1);
        $this->keyValueFactory->get('comparenator.captures')->delete(hash('sha256', 'public://comparenator/' . $relative));
      }
    }
  }

}

