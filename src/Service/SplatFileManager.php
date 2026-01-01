<?php

namespace Drupal\sharp_web_splats\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;

/**
 * Manages splat file storage and associations.
 */
class SplatFileManager {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a SplatFileManager object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('sharp_web_splats');
  }

  /**
   * Get the splat file associated with an image file.
   *
   * Uses naming convention: public://splats/{image_fid}.sog
   *
   * @param \Drupal\file\FileInterface $image_file
   *   The image file entity.
   *
   * @return \Drupal\file\FileInterface|null
   *   The splat file entity, or NULL if not found.
   */
  public function getSplatForImage(FileInterface $image_file) {
    $splat_uri = $this->generateSplatUri($image_file);

    if (file_exists($splat_uri)) {
      // Load existing file entity.
      $file_storage = $this->entityTypeManager->getStorage('file');
      $files = $file_storage->loadByProperties(['uri' => $splat_uri]);

      if (!empty($files)) {
        return reset($files);
      }

      // File exists but no entity - create one.
      $file = $file_storage->create([
        'uri' => $splat_uri,
        'status' => 1,
        'uid' => $image_file->getOwnerId(),
      ]);
      $file->save();
      return $file;
    }

    return NULL;
  }

  /**
   * Save a splat file and associate it with an image.
   *
   * @param \Drupal\file\FileInterface $image_file
   *   The source image file.
   * @param string $source_path
   *   Local path to the downloaded splat file.
   * @param string $format
   *   File format: 'sog' or 'ply'.
   *
   * @return \Drupal\file\FileInterface|null
   *   The created file entity, or NULL on failure.
   */
  public function saveSplatFile(FileInterface $image_file, $source_path, $format = 'sog') {
    $destination_uri = $this->generateSplatUri($image_file, $format);

    // Ensure directory exists.
    $directory = dirname($destination_uri);
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    // Copy file to destination.
    try {
      $final_uri = $this->fileSystem->copy($source_path, $destination_uri, FileSystemInterface::EXISTS_REPLACE);

      if ($final_uri) {
        // Create or update file entity.
        $file_storage = $this->entityTypeManager->getStorage('file');
        $existing_files = $file_storage->loadByProperties(['uri' => $final_uri]);

        if (!empty($existing_files)) {
          // Update existing file.
          $file = reset($existing_files);
        }
        else {
          // Create new file entity.
          $file = $file_storage->create([
            'uri' => $final_uri,
            'status' => 1,
            'uid' => $image_file->getOwnerId(),
          ]);
        }

        $file->save();

        $this->logger->info('Saved splat file @uri for image @fid', [
          '@uri' => $final_uri,
          '@fid' => $image_file->id(),
        ]);

        return $file;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to save splat file: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Generate the URI for a splat file.
   *
   * @param \Drupal\file\FileInterface $image_file
   *   The image file.
   * @param string $format
   *   File format extension.
   *
   * @return string
   *   The splat file URI.
   */
  protected function generateSplatUri(FileInterface $image_file, $format = 'sog') {
    // Store in public://splats/{file_id}.{format}.
    return 'public://splats/' . $image_file->id() . '.' . $format;
  }

  /**
   * Delete splat file associated with an image.
   *
   * @param \Drupal\file\FileInterface $image_file
   *   The image file.
   */
  public function deleteSplatForImage(FileInterface $image_file) {
    $splat_file = $this->getSplatForImage($image_file);

    if ($splat_file) {
      $splat_file->delete();
      $this->logger->info('Deleted splat file for image @fid', ['@fid' => $image_file->id()]);
    }
  }

  /**
   * Check if splat generation is in progress for an image.
   *
   * @param \Drupal\file\FileInterface $image_file
   *   The image file.
   *
   * @return bool
   *   TRUE if generation is in progress.
   */
  public function isGenerationInProgress(FileInterface $image_file) {
    $state = \Drupal::state();
    $in_progress = $state->get('sharp_web_splats.in_progress', []);

    return in_array($image_file->id(), $in_progress);
  }

}
