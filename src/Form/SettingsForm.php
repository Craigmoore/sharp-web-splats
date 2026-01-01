<?php

namespace Drupal\sharp_web_splats\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\sharp_web_splats\Service\SharpApiClient;

/**
 * Configure SHARP Web Splats settings.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The SHARP API client.
   *
   * @var \Drupal\sharp_web_splats\Service\SharpApiClient
   */
  protected $apiClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->apiClient = $container->get('sharp_web_splats.api_client');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['sharp_web_splats.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'sharp_web_splats_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('sharp_web_splats.settings');

    $form['api_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('SHARP Service URL'),
      '#default_value' => $config->get('api_url'),
      '#description' => $this->t('URL of the Flask SHARP service (e.g., http://localhost:8080)'),
      '#required' => TRUE,
    ];

    // Test connection button.
    $form['test_connection'] = [
      '#type' => 'button',
      '#value' => $this->t('Test Connection'),
      '#ajax' => [
        'callback' => '::testConnection',
        'wrapper' => 'connection-status',
      ],
    ];

    $form['connection_status'] = [
      '#type' => 'markup',
      '#markup' => '<div id="connection-status"></div>',
    ];

    $form['default_format'] = [
      '#type' => 'select',
      '#title' => $this->t('Default format'),
      '#options' => [
        'sog' => $this->t('SuperSplat (.sog) - Compressed'),
        'ply' => $this->t('PLY (.ply) - Uncompressed'),
      ],
      '#default_value' => $config->get('default_format'),
    ];

    $form['generation_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Generation timeout'),
      '#default_value' => $config->get('generation_timeout'),
      '#description' => $this->t('Timeout in seconds for generation requests'),
      '#min' => 10,
      '#max' => 300,
    ];

    $form['max_file_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum image file size'),
      '#default_value' => $config->get('max_file_size'),
      '#description' => $this->t('Maximum file size in bytes (0 = no limit)'),
      '#min' => 0,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * AJAX callback to test connection.
   */
  public function testConnection(array &$form, FormStateInterface $form_state) {
    $status = $this->apiClient->healthCheck();

    $message = $status
      ? $this->t('✓ Connection successful. SHARP service is running and ready.')
      : $this->t('✗ Connection failed. Check service URL and ensure Flask app is running.');

    $form['connection_status']['#markup'] = '<div id="connection-status" class="messages messages--' . ($status ? 'status' : 'error') . '">' . $message . '</div>';

    return $form['connection_status'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('sharp_web_splats.settings')
      ->set('api_url', $form_state->getValue('api_url'))
      ->set('default_format', $form_state->getValue('default_format'))
      ->set('generation_timeout', $form_state->getValue('generation_timeout'))
      ->set('max_file_size', $form_state->getValue('max_file_size'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
