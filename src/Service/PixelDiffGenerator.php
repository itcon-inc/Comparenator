<?php

namespace Drupal\comparenator\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;

/**
 * Generates content-addressed PNG pixel diffs using GD.
 */
class PixelDiffGenerator {

  public function __construct(
    protected FileSystemInterface $fileSystem,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Generate a diff image and return its URL.
   *
   * @param string $ref_url
   * @param string $cur_url
   * @param string $identifier
   * @param string $size
   *
   * @return string URL of diff image.
   */
  public function generateDiff($ref_url, $cur_url, $identifier, $size) {
    $ref_path = $this->urlToPath($ref_url);
    $cur_path = $this->urlToPath($cur_url);

    $directory = 'public://comparenator/diff';
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY)) {
      throw new \RuntimeException('Unable to prepare pixel diff storage.');
    }
    $key = hash('sha256', hash_file('sha256', $ref_path) . hash_file('sha256', $cur_path));
    $uri = "$directory/$key.png";
    if (!is_file($uri)) {
      $temporary = $this->fileSystem->tempnam($directory, 'diff-');
      if ($temporary === FALSE) {
        throw new \RuntimeException('Unable to allocate pixel diff storage.');
      }
      try {
        $this->generateGdDiff($ref_path, $cur_path, $temporary);
        $this->fileSystem->move($temporary, $uri, \Drupal\Core\File\FileExists::Replace);
      }
      finally {
        if (is_file($temporary)) {
          $this->fileSystem->delete($temporary);
        }
      }
    }
    return $this->fileUrlGenerator->generateString($uri);
  }

  /**
   * Convert a public file URL to a filesystem path.
   */
  protected function urlToPath($url) {
    $base = rawurldecode(parse_url($this->fileUrlGenerator->generateString('public://'), PHP_URL_PATH));
    $path = rawurldecode(parse_url($url, PHP_URL_PATH));
    $prefix = rtrim($base, '/') . '/comparenator/';
    if (!str_starts_with($path, $prefix) || str_contains($path, '..')) {
      throw new \InvalidArgumentException('Diff inputs must be local Comparenator images.');
    }
    $uri = 'public://' . substr($path, strlen(rtrim($base, '/')) + 1);
    if (!is_file($uri)) {
      throw new \RuntimeException('A pixel diff input is missing.');
    }
    return $uri;
  }

  /**
   * GD-based pixel diff generator.
   */
  protected function generateGdDiff($ref_path, $cur_path, $diff_path) {
    if (!extension_loaded('gd')) {
      throw new \RuntimeException('Pixel diffs require the PHP GD extension.');
    }
    $ref = imagecreatefrompng($ref_path);
    $cur = imagecreatefrompng($cur_path);
    if (!$ref || !$cur) {
      throw new \RuntimeException('Unable to decode pixel diff inputs.');
    }
    $width = max(imagesx($ref), imagesx($cur));
    $height = max(imagesy($ref), imagesy($cur));
    // Bound the PHP pixel loop and allocation for unusually tall full pages.
    if ($width * $height > 16000000) {
      throw new \RuntimeException('These screenshots are too large for a pixel diff. Use side-by-side mode.');
    }
    $diff = imagecreatetruecolor($width, $height);
    if (!$diff) {
      throw new \RuntimeException('Unable to allocate pixel diff image.');
    }
    try {
      $red = imagecolorallocate($diff, 255, 0, 0);
      $white = imagecolorallocate($diff, 255, 255, 255);
      imagefill($diff, 0, 0, $white);
      for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
          if ($x >= imagesx($ref) || $y >= imagesy($ref) || $x >= imagesx($cur) || $y >= imagesy($cur) || imagecolorat($ref, $x, $y) !== imagecolorat($cur, $x, $y)) {
            imagesetpixel($diff, $x, $y, $red);
          }
        }
      }
      if (!imagepng($diff, $diff_path)) {
        throw new \RuntimeException('Unable to write the pixel diff.');
      }
    }
    finally {
      imagedestroy($ref);
      imagedestroy($cur);
      imagedestroy($diff);
    }
  }

}

