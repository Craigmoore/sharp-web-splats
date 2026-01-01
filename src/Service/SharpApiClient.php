<?php

namespace Drupal\sharp_web_splats\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Client for communicating with the SHARP Flask service.
 */
class SharpApiClient {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a SharpApiClient object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('sharp_web_splats');
  }

  /**
   * Get the SHARP service base URL.
   *
   * @return string
   *   The base URL of the SHARP service.
   */
  protected function getBaseUrl() {
    $config = $this->configFactory->get('sharp_web_splats.settings');
    return $config->get('api_url') ?? 'http://localhost:8080';
  }

  /**
   * Check if the SHARP service is available.
   *
   * @return bool
   *   TRUE if the service is available and healthy, FALSE otherwise.
   */
  public function healthCheck() {
    try {
      $response = $this->httpClient->request('GET', $this->getBaseUrl() . '/health', [
        'timeout' => 5,
      ]);

      if ($response->getStatusCode() === 200) {
        $data = json_decode($response->getBody(), TRUE);
        return isset($data['status']) && $data['status'] === 'ok' && !empty($data['model_loaded']);
      }
    }
    catch (GuzzleException $e) {
      $this->logger->error('SHARP service health check failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return FALSE;
  }

  /**
   * Generate a splat from an image file.
   *
   * @param string $image_path
   *   Local filesystem path to the image.
   * @param array $options
   *   Options: 'format' => 'sog'|'ply'.
   *
   * @return array|null
   *   Response data with 'url' key, or NULL on failure.
   */
  public function generateSplat($image_path, array $options = []) {
    if (!file_exists($image_path)) {
      $this->logger->error('Image file not found: @path', ['@path' => $image_path]);
      return NULL;
    }

    try {
      $config = $this->configFactory->get('sharp_web_splats.settings');
      $timeout = $config->get('generation_timeout') ?? 60;

      $response = $this->httpClient->request('POST', $this->getBaseUrl() . '/generate', [
        'multipart' => [
          [
            'name' => 'image',
            'contents' => fopen($image_path, 'r'),
            'filename' => basename($image_path),
          ],
        ],
        'timeout' => $timeout,
      ]);

      if ($response->getStatusCode() === 200) {
        $data = json_decode($response->getBody(), TRUE);
        $this->logger->info('Successfully generated splat for @path', ['@path' => $image_path]);
        return $data;
      }
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to generate splat: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Download a splat file from the SHARP service.
   *
   * @param string $splat_url
   *   URL returned from /generate endpoint.
   * @param string $destination
   *   Local filesystem path where to save the file.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function downloadSplat($splat_url, $destination) {
    try {
      // Resolve relative URL to absolute.
      if (strpos($splat_url, 'http') !== 0) {
        $splat_url = $this->getBaseUrl() . $splat_url;
      }

      $response = $this->httpClient->request('GET', $splat_url, [
        'sink' => $destination,
        'timeout' => 30,
      ]);

      if ($response->getStatusCode() === 200 && file_exists($destination)) {
        $size = filesize($destination) / 1024 / 1024;
        $this->logger->info('Downloaded splat: @size MB to @dest', [
          '@size' => number_format($size, 2),
          '@dest' => $destination,
        ]);
        return TRUE;
      }
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to download splat: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return FALSE;
  }

}
