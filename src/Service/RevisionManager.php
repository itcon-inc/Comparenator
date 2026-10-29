<?php

namespace Drupal\comparenator\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Handles screenshot lookup and metadata for node revisions.
 */
class RevisionManager {

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructor.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelInterface $logger
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
  }

  /**
   * Get a screenshot URL for a given node revision and screen size.
   *
   * @param int $nid
   * @param int $revision_id
   * @param string $size
   *
   * @return stringfalse
   */
  public function getRevisionScreenshotUrl($nid, $revision_id, $size) {
    $storage = $this->entityTypeManager->getStorage('node');
    $revision = $storage->loadRevision($revision_id);

    if (!$revision) {
      $this->logger->warning("Revision $revision_id for node $nid not found.");
      return FALSE;
    }

    // Path pattern:
    // public://comparenator/revisions/{nid}/{rev}/{size}.png
    $path = "public://comparenator/revisions/$nid/$revision_id/$size.png";
    $real_path = \Drupal::service('file_system')->realpath($path);

    if (!file_exists($real_path)) {
      $this->logger->warning("Revision screenshot missing: $path");
      return FALSE;
    }

    return file_create_url($path);
  }

  /**
   * Save a screenshot for a revision (for external screenshot pipelines).
   */
  public function saveRevisionScreenshot($nid, $revision_id, $size, $image_data) {
    $dir = "public://comparenator/revisions/$nid/$revision_id";
    file_prepare_directory($dir, FILE_CREATE_DIRECTORY);

    $path = "$dir/$size.png";
    $real_path = \Drupal::service('file_system')->realpath($path);

    file_put_contents($real_path, $image_data);

    return $path;
  }

}
