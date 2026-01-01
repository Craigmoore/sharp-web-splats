<?php

namespace Drupal\sharp_web_splats\Service;

use Drupal\Core\Queue\QueueFactory;
use Drupal\file\FileInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;

/**
 * Service for managing the splat generation queue.
 */
class SplatGenerationQueue {

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a SplatGenerationQueue object.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    QueueFactory $queue_factory,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->queueFactory = $queue_factory;
    $this->logger = $logger_factory->get('sharp_web_splats');
  }

  /**
   * Queue a splat generation job.
   *
   * @param \Drupal\file\FileInterface $image_file
   *   The image file to process.
   * @param array $options
   *   Generation options.
   */
  public function queueGeneration(FileInterface $image_file, array $options = []) {
    $queue = $this->queueFactory->get('sharp_web_splats_generation');

    // Check if already queued (prevent duplicates).
    $state = \Drupal::state();
    $queued = $state->get('sharp_web_splats.queued', []);

    if (in_array($image_file->id(), $queued)) {
      $this->logger->info('Image @fid already queued for generation', ['@fid' => $image_file->id()]);
      return;
    }

    $item = [
      'file_id' => $image_file->id(),
      'options' => $options,
      'queued_time' => time(),
    ];

    $queue->createItem($item);

    // Track queued items.
    $queued[] = $image_file->id();
    $state->set('sharp_web_splats.queued', $queued);

    $this->logger->info('Queued splat generation for image @fid', ['@fid' => $image_file->id()]);
  }

  /**
   * Get queue statistics.
   *
   * @return array
   *   Statistics about the queue.
   */
  public function getQueueStats() {
    $queue = $this->queueFactory->get('sharp_web_splats_generation');

    return [
      'items' => $queue->numberOfItems(),
      'queued' => \Drupal::state()->get('sharp_web_splats.queued', []),
      'in_progress' => \Drupal::state()->get('sharp_web_splats.in_progress', []),
    ];
  }

}
