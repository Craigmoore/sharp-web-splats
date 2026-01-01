<?php

namespace Drupal\sharp_web_splats\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\sharp_web_splats\Service\SharpApiClient;
use Drupal\sharp_web_splats\Service\SplatFileManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;

/**
 * Processes splat generation queue items.
 *
 * @QueueWorker(
 *   id = "sharp_web_splats_generation",
 *   title = @Translation("SHARP Splat Generation Worker"),
 *   cron = {"time" = 60}
 * )
 */
class SplatGenerationWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The SHARP API client.
   *
   * @var \Drupal\sharp_web_splats\Service\SharpApiClient
   */
  protected $apiClient;

  /**
   * The splat file manager.
   *
   * @var \Drupal\sharp_web_splats\Service\SplatFileManager
   */
  protected $fileManager;

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
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a SplatGenerationWorker object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\sharp_web_splats\Service\SharpApiClient $api_client
   *   The SHARP API client.
   * @param \Drupal\sharp_web_splats\Service\SplatFileManager $file_manager
   *   The splat file manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    SharpApiClient $api_client,
    SplatFileManager $file_manager,
    EntityTypeManagerInterface $entity_type_manager,
    FileSystemInterface $file_system,
    StateInterface $state,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->apiClient = $api_client;
    $this->fileManager = $file_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->state = $state;
    $this->logger = $logger_factory->get('sharp_web_splats');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('sharp_web_splats.api_client'),
      $container->get('sharp_web_splats.file_manager'),
      $container->get('entity_type.manager'),
      $container->get('file_system'),
      $container->get('state'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $file_id = $data['file_id'];
    $options = $data['options'] ?? [];

    // Mark as in progress.
    $in_progress = $this->state->get('sharp_web_splats.in_progress', []);
    $in_progress[] = $file_id;
    $this->state->set('sharp_web_splats.in_progress', $in_progress);

    // Remove from queued list.
    $queued = $this->state->get('sharp_web_splats.queued', []);
    $queued = array_diff($queued, [$file_id]);
    $this->state->set('sharp_web_splats.queued', array_values($queued));

    try {
      /** @var \Drupal\file\FileInterface $file */
      $file = $this->entityTypeManager->getStorage('file')->load($file_id);

      if (!$file) {
        throw new \Exception("File {$file_id} not found");
      }

      // Get local file path.
      $image_path = $this->fileSystem->realpath($file->getFileUri());

      if (!$image_path || !file_exists($image_path)) {
        throw new \Exception("Image file not accessible: {$file->getFileUri()}");
      }

      // Generate splat via API.
      $this->logger->info('Generating splat for file @fid', ['@fid' => $file_id]);
      $result = $this->apiClient->generateSplat($image_path, $options);

      if (!$result || empty($result['url'])) {
        throw new \Exception('Splat generation failed or returned no URL');
      }

      // Download the splat file.
      $temp_file = $this->fileSystem->tempnam('temporary://', 'splat_');

      if (!$this->apiClient->downloadSplat($result['url'], $temp_file)) {
        throw new \Exception('Failed to download generated splat');
      }

      // Save to permanent location.
      $format = $options['format'] ?? 'sog';
      $splat_file = $this->fileManager->saveSplatFile($file, $temp_file, $format);

      if (!$splat_file) {
        throw new \Exception('Failed to save splat file');
      }

      $this->logger->info('Successfully generated and saved splat for file @fid', ['@fid' => $file_id]);

      // Clean up temp file.
      @unlink($temp_file);
    }
    catch (\Exception $e) {
      $this->logger->error('Error processing splat generation: @message', [
        '@message' => $e->getMessage(),
      ]);

      // Re-throw to mark job as failed (will retry based on queue config).
      throw $e;
    }
    finally {
      // Remove from in-progress.
      $in_progress = $this->state->get('sharp_web_splats.in_progress', []);
      $in_progress = array_diff($in_progress, [$file_id]);
      $this->state->set('sharp_web_splats.in_progress', array_values($in_progress));
    }
  }

}
