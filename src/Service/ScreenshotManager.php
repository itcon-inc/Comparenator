<?php

namespace Drupal\comparenator\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Handles storage and retrieval of comparison screenshots.
 */
class ScreenshotManager {

  /**
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructor.
   */
  public function __construct(
    FileSystemInterface $file_system,
    ConfigFactoryInterface $config_factory,
    LoggerChannelInterface $logger
  ) {
    $this->fileSystem = $file_system;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
  }

  /**
   * Get full screenshot directory for an environment + size.
   */
  protected function getBaseDirectory($env, $size) {
    return "public://comparenator/$env/$size";
  }

  /**
   * Get screenshot file path.
   */
  protected function getScreenshotPath($env, $identifier, $size) {
    $dir = $this->getBaseDirectory($env, $size);
    return "$dir/$identifier.png";
  }

  /**
   * Get environment screenshot URL; does not create screenshot.
   *
   * @return string|false
   */
  public function getEnvironmentScreenshotUrl($env, $identifier, $size) {
    $path = $this->getScreenshotPath($env, $identifier, $size);

    if (!file_exists($this->fileSystem->realpath($path))) {
      $this->logger->warning("Screenshot missing: $path");
      return FALSE;
    }

    return file_create_url($path);
  }

  /**
   * Save a screenshot file (used by external screenshot generator).
   */
  public function saveScreenshot($env, $identifier, $size, $data) {
    $dir = $this->getBaseDirectory($env, $size);
    $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);

    $path = $this->getScreenshotPath($env, $identifier, $size);
    file_put_contents($this->fileSystem->realpath($path), $data);

    return $path;
  }

  /**
   * Purge screenshots older than retention_days.
   */
  public function purgeOldScreenshots() {
    $config = $this->configFactory->get('comparenator.settings');
    $days = (int) ($config->get('retention_days') ?? 7);
    $threshold = time() - ($days * 86400);

    $base = "public://comparenator";
    $real_base = $this->fileSystem->realpath($base);
    if (!is_dir($real_base)) {
      return;
    }

    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($real_base, \FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
      if ($file->isFile()) {
        if ($file->getMTime() < $threshold) {
          unlink($file->getPathname());
          $this->logger->notice("Purged old screenshot: " . $file->getFilename());
        }
      }
    }
  }

}
