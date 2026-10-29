<?php

namespace Drupal\comparenator\Service;

use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Generates pixel diffs using ImageMagick if available, otherwise GD.
 */
class PixelDiffGenerator {

  /**
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructor.
   */
  public function __construct(LoggerChannelInterface $logger) {
    $this->logger = $logger;
  }

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

    $diff_dir = 'public://comparenator/diff';
    file_prepare_directory($diff_dir, FILE_CREATE_DIRECTORY);

    $diff_path = "$diff_dir/{$identifier}-{$size}.png";
    $real_diff_path = \Drupal::service('file_system')->realpath($diff_path);

    // Attempt ImageMagick first.
    if ($this->hasImageMagick()) {
      $cmd = sprintf(
        'compare -metric AE %s %s %s 2>&1',
        escapeshellarg($ref_path),
        escapeshellarg($cur_path),
        escapeshellarg($real_diff_path)
      );

      exec($cmd, $output, $return);

      if (file_exists($real_diff_path)) {
        return file_create_url($diff_path);
      }

      $this->logger->warning('ImageMagick compare failed; falling back to GD.');
    }

    // Fallback to GD diff.
    $this->generateGdDiff($ref_path, $cur_path, $real_diff_path);

    return file_create_url($diff_path);
  }

  /**
   * Check if ImageMagick "compare" command is available.
   */
  protected function hasImageMagick() {
    $path = trim(shell_exec('command -v compare'));
    return !empty($path) && is_executable($path);
  }

  /**
   * Convert a public file URL to a filesystem path.
   */
  protected function urlToPath($url) {
    $local = str_replace(file_create_url('public://'), 'public://', $url);
    return \Drupal::service('file_system')->realpath($local);
  }

  /**
   * GD-based pixel diff generator.
   */
  protected function generateGdDiff($ref_path, $cur_path, $diff_path) {
    $ref = @imagecreatefrompng($ref_path);
    $cur = @imagecreatefrompng($cur_path);

    if (!$ref || !$cur) {
      $this->logger->error('Unable to open images for GD diff.');
      return;
    }

    $width = imagesx($ref);
    $height = imagesy($ref);

    $diff = imagecreatetruecolor($width, $height);
    $red = imagecolorallocate($diff, 255, 0, 0);

    for ($x = 0; $x < $width; $x++) {
      for ($y = 0; $y < $height; $y++) {
        $pixelA = imagecolorat($ref, $x, $y);
        $pixelB = imagecolorat($cur, $x, $y);

        if ($pixelA !== $pixelB) {
          imagesetpixel($diff, $x, $y, $red);
        }
      }
    }

    imagepng($diff, $diff_path);

    imagedestroy($ref);
    imagedestroy($cur);
    imagedestroy($diff);
  }

}
