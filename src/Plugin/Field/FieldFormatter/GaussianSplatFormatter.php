<?php

namespace Drupal\sharp_web_splats\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\sharp_web_splats\Service\SplatFileManager;
use Drupal\sharp_web_splats\Service\SplatGenerationQueue;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Plugin implementation of the 'gaussian_splat' formatter.
 *
 * @FieldFormatter(
 *   id = "gaussian_splat",
 *   label = @Translation("3D Gaussian Splat Viewer"),
 *   field_types = {
 *     "image"
 *   }
 * )
 */
class GaussianSplatFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  /**
   * The splat file manager service.
   *
   * @var \Drupal\sharp_web_splats\Service\SplatFileManager
   */
  protected $splatFileManager;

  /**
   * The splat generation queue service.
   *
   * @var \Drupal\sharp_web_splats\Service\SplatGenerationQueue
   */
  protected $splatQueue;

  /**
   * Constructs a GaussianSplatFormatter object.
   *
   * @param string $plugin_id
   *   The plugin_id for the formatter.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the formatter is associated.
   * @param array $settings
   *   The formatter settings.
   * @param string $label
   *   The formatter label display setting.
   * @param string $view_mode
   *   The view mode.
   * @param array $third_party_settings
   *   Any third party settings.
   * @param \Drupal\sharp_web_splats\Service\SplatFileManager $splat_file_manager
   *   The splat file manager service.
   * @param \Drupal\sharp_web_splats\Service\SplatGenerationQueue $splat_queue
   *   The splat queue service.
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    $label,
    $view_mode,
    array $third_party_settings,
    SplatFileManager $splat_file_manager,
    SplatGenerationQueue $splat_queue
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
    $this->splatFileManager = $splat_file_manager;
    $this->splatQueue = $splat_queue;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('sharp_web_splats.file_manager'),
      $container->get('sharp_web_splats.generation_queue')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'viewer_width' => '100%',
      'viewer_height' => '600px',
      'auto_generate' => FALSE,
      'show_fallback_image' => TRUE,
      'enable_vr' => TRUE,
      'enable_ar' => FALSE,
      'placeholder_text' => 'Generating 3D view...',
      'compression_format' => 'sog',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);

    $elements['viewer_width'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Viewer Width'),
      '#default_value' => $this->getSetting('viewer_width'),
      '#description' => $this->t('CSS width (e.g., 100%, 800px)'),
    ];

    $elements['viewer_height'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Viewer Height'),
      '#default_value' => $this->getSetting('viewer_height'),
      '#description' => $this->t('CSS height (e.g., 600px)'),
    ];

    $elements['auto_generate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Automatically generate splats'),
      '#default_value' => $this->getSetting('auto_generate'),
      '#description' => $this->t('Queue splat generation when image is displayed without existing splat.'),
    ];

    $elements['show_fallback_image'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show fallback image'),
      '#default_value' => $this->getSetting('show_fallback_image'),
      '#description' => $this->t('Display original image while splat is being generated.'),
    ];

    $elements['enable_vr'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable VR mode'),
      '#default_value' => $this->getSetting('enable_vr'),
    ];

    $elements['enable_ar'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable AR mode'),
      '#default_value' => $this->getSetting('enable_ar'),
    ];

    $elements['placeholder_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Placeholder text'),
      '#default_value' => $this->getSetting('placeholder_text'),
    ];

    $elements['compression_format'] = [
      '#type' => 'select',
      '#title' => $this->t('Splat format'),
      '#options' => [
        'sog' => $this->t('SuperSplat (.sog) - Compressed'),
        'ply' => $this->t('PLY (.ply) - Uncompressed'),
      ],
      '#default_value' => $this->getSetting('compression_format'),
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
    $summary[] = $this->t('Size: @width × @height', [
      '@width' => $this->getSetting('viewer_width'),
      '@height' => $this->getSetting('viewer_height'),
    ]);
    $summary[] = $this->getSetting('auto_generate')
      ? $this->t('Auto-generate: Yes')
      : $this->t('Auto-generate: No');
    $summary[] = $this->t('Format: @format', [
      '@format' => strtoupper($this->getSetting('compression_format')),
    ]);
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    foreach ($items as $delta => $item) {
      /** @var \Drupal\file\FileInterface $file */
      $file = $item->entity;

      if (!$file) {
        continue;
      }

      // Check if splat exists.
      $splat_file = $this->splatFileManager->getSplatForImage($file);

      if ($splat_file) {
        // Splat exists - render viewer.
        $elements[$delta] = $this->renderViewer($file, $splat_file);
      }
      elseif ($this->getSetting('auto_generate')) {
        // Queue generation and show placeholder.
        $this->splatQueue->queueGeneration($file, [
          'format' => $this->getSetting('compression_format'),
        ]);
        $elements[$delta] = $this->renderPlaceholder($file);
      }
      else {
        // Auto-generate disabled - just show image.
        $elements[$delta] = $this->renderFallback($file);
      }
    }

    return $elements;
  }

  /**
   * Render the PlayCanvas viewer.
   *
   * @param \Drupal\file\FileInterface $image_file
   *   The image file entity.
   * @param \Drupal\file\FileInterface $splat_file
   *   The splat file entity.
   *
   * @return array
   *   A render array.
   */
  protected function renderViewer($image_file, $splat_file) {
    return [
      '#theme' => 'splat_viewer',
      '#splat_url' => \Drupal::service('file_url_generator')->generateAbsoluteString($splat_file->getFileUri()),
      '#image_file' => $image_file,
      '#settings' => $this->getSettings(),
      '#attached' => [
        'library' => [
          'sharp_web_splats/playcanvas-viewer',
        ],
        'drupalSettings' => [
          'sharpWebSplats' => [
            'viewers' => [
              'viewer_' . $splat_file->id() => [
                'splatUrl' => \Drupal::service('file_url_generator')->generateAbsoluteString($splat_file->getFileUri()),
                'enableVR' => $this->getSetting('enable_vr'),
                'enableAR' => $this->getSetting('enable_ar'),
              ],
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Render placeholder while generating.
   *
   * @param \Drupal\file\FileInterface $image_file
   *   The image file entity.
   *
   * @return array
   *   A render array.
   */
  protected function renderPlaceholder($image_file) {
    return [
      '#theme' => 'splat_placeholder',
      '#image_file' => $image_file,
      '#placeholder_text' => $this->getSetting('placeholder_text'),
      '#show_fallback' => $this->getSetting('show_fallback_image'),
      '#attached' => [
        'library' => [
          'sharp_web_splats/generation-poller',
        ],
        'drupalSettings' => [
          'sharpWebSplats' => [
            'pollers' => [
              'poller_' . $image_file->id() => [
                'pollingEnabled' => TRUE,
                'imageFileId' => $image_file->id(),
              ],
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Render fallback (original image).
   *
   * @param \Drupal\file\FileInterface $image_file
   *   The image file entity.
   *
   * @return array
   *   A render array.
   */
  protected function renderFallback($image_file) {
    return [
      '#theme' => 'image',
      '#uri' => $image_file->getFileUri(),
      '#alt' => $image_file->getFilename(),
    ];
  }

}
