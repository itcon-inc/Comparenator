<?php

namespace Drupal\comparenator\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs the bundled Playwright script without shell interpolation.
 */
class BrowserCapture {

  public function __construct(
    protected FileSystemInterface $fileSystem,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns a content fingerprint and PNG bytes if a new capture was needed.
   */
  public function capture(string $url, int $width, int $height, ?string $previous = NULL, bool $force = FALSE): array {
    $temporary = $this->fileSystem->tempnam('temporary://', 'comparenator-');
    if ($temporary === FALSE) {
      throw new \RuntimeException('Unable to allocate a temporary screenshot file.');
    }
    try {
      $output = $this->fileSystem->realpath($temporary);
      if (!$output) {
        throw new \RuntimeException('Unable to resolve temporary screenshot storage.');
      }
      $node = $this->configFactory->get('comparenator.settings')->get('node_binary') ?: 'node';
      $process = new Process([$node, dirname(__DIR__, 2) . '/scripts/capture.mjs']);
      $process->setTimeout(90);
      $process->setInput(json_encode([
        'url' => $url,
        'width' => $width,
        'height' => $height,
        'output' => $output,
        'previousFingerprint' => $previous,
        'force' => $force,
      ], JSON_THROW_ON_ERROR));
      try {
        $process->run();
      }
      catch (ProcessTimedOutException $exception) {
        throw new \RuntimeException('Screenshot capture timed out.', 0, $exception);
      }
      if (!$process->isSuccessful()) {
        throw new \RuntimeException('Playwright capture failed: ' . trim($process->getErrorOutput()));
      }
      try {
        $result = json_decode($process->getOutput(), TRUE, 512, JSON_THROW_ON_ERROR);
      }
      catch (\JsonException $exception) {
        throw new \RuntimeException('Playwright returned an invalid capture response.', 0, $exception);
      }
      if (!is_array($result) || !is_string($result['fingerprint'] ?? NULL) || !preg_match('/^[a-f0-9]{64}$/D', $result['fingerprint']) || !is_bool($result['changed'] ?? NULL)) {
        throw new \RuntimeException('Playwright returned an incomplete capture response.');
      }
      if (!$result['changed'] && ($force || $previous === NULL || $result['fingerprint'] !== $previous)) {
        throw new \RuntimeException('Playwright did not produce the required new capture.');
      }
      $data = $result['changed'] ? file_get_contents($output) : NULL;
      if ($result['changed'] && ($data === FALSE || !str_starts_with($data, "\x89PNG\r\n\x1a\n"))) {
        throw new \RuntimeException('Playwright did not produce a PNG screenshot.');
      }
      return ['fingerprint' => $result['fingerprint'], 'data' => $data];
    }
    finally {
      $this->fileSystem->delete($temporary);
    }
  }

}

