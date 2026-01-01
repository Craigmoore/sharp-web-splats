<?php

namespace Drupal\sharp_web_splats\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\sharp_web_splats\Service\SplatFileManager;
use Drupal\sharp_web_splats\Service\SplatGenerationQueue;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for SHARP Web Splats AJAX endpoints.
 */
class SplatController extends ControllerBase {

  /**
   * The splat file manager.
   *
   * @var \Drupal\sharp_web_splats\Service\SplatFileManager
   */
  protected $fileManager;

  /**
   * The generation queue service.
   *
   * @var \Drupal\sharp_web_splats\Service\SplatGenerationQueue
   */
  protected $queue;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->fileManager = $container->get('sharp_web_splats.file_manager');
    $instance->queue = $container->get('sharp_web_splats.generation_queue');
    return $instance;
  }

  /**
   * Check generation status for an image file.
   *
   * @param int $file_id
   *   The file ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with status.
   */
  public function checkGeneration($file_id) {
    $file = $this->entityTypeManager()->getStorage('file')->load($file_id);

    if (!$file) {
      return new JsonResponse(['status' => 'error', 'message' => 'File not found'], 404);
    }

    $splat_file = $this->fileManager->getSplatForImage($file);

    if ($splat_file) {
      // Generate viewer HTML.
      $build = [
        '#theme' => 'splat_viewer',
        '#splat_url' => \Drupal::service('file_url_generator')->generateAbsoluteString($splat_file->getFileUri()),
        '#image_file' => $file,
        '#settings' => [
          'viewer_width' => '100%',
          'viewer_height' => '600px',
          'enable_vr' => TRUE,
          'enable_ar' => FALSE,
        ],
      ];

      $renderer = \Drupal::service('renderer');
      $html = $renderer->renderPlain($build);

      return new JsonResponse([
        'status' => 'complete',
        'splatUrl' => \Drupal::service('file_url_generator')->generateAbsoluteString($splat_file->getFileUri()),
        'viewerHtml' => (string) $html,
      ]);
    }

    if ($this->fileManager->isGenerationInProgress($file)) {
      return new JsonResponse(['status' => 'processing']);
    }

    return new JsonResponse(['status' => 'pending']);
  }

  /**
   * Manually trigger regeneration of a splat.
   *
   * @param int $file_id
   *   The file ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with status.
   */
  public function regenerate($file_id) {
    $file = $this->entityTypeManager()->getStorage('file')->load($file_id);

    if (!$file) {
      return new JsonResponse(['status' => 'error', 'message' => 'File not found'], 404);
    }

    // Delete existing splat.
    $this->fileManager->deleteSplatForImage($file);

    // Queue new generation.
    $this->queue->queueGeneration($file);

    return new JsonResponse(['status' => 'queued']);
  }

}
