# SHARP Web Splats - Drupal Module

A Drupal field formatter that displays images as interactive 3D Gaussian Splats using Apple's SHARP (Scalable High-fidelity Appearance Representation) model.

## Features

- **Field Formatter**: Transform any image field into an interactive 3D viewer
- **Asynchronous Processing**: Queue-based splat generation via cron
- **PlayCanvas Integration**: WebXR-ready 3D viewer with VR/AR support
- **Flexible Storage**: Splats stored as Drupal file entities in `public://splats/`
- **Media Support**: Works with both Image fields and Media entities
- **Configurable**: Control auto-generation, viewer dimensions, compression format, and more

## Requirements

- Drupal 10.0+ or 11.0+
- PHP 8.1+
- Running SHARP Flask service (see Setup below)
- Public files directory (writable)

## Installation

### Via Composer (Recommended)

```bash
composer require drupal/sharp_web_splats
drush en sharp_web_splats
```

### Manual Installation

1. Download and extract to `web/modules/contrib/sharp_web_splats`
2. Enable the module:
   ```bash
   drush en sharp_web_splats
   ```

## Setup

### 1. Start the SHARP Flask Service

The module requires a running Python Flask service to generate splats.

**Option A: Using Docker** (recommended)

```bash
# From the module directory
docker-compose up sharp-web-splats
```

The service will be available at `http://localhost:8080`.

**Option B: Local Python**

```bash
# Install dependencies
pip install -r requirements.txt
git submodule update --init --recursive

# Run the Flask app
python app.py
```

### 2. Configure the Module

1. Navigate to `/admin/config/media/sharp-web-splats`
2. Set the SHARP Service URL (default: `http://localhost:8080`)
3. Click "Test Connection" to verify it's working
4. Configure other settings as needed

## Usage

### Basic Usage

1. Go to a content type with an image field (e.g., Article)
2. Navigate to **Manage Display** (e.g., `/admin/structure/types/manage/article/display`)
3. Change the formatter for your image field to **"3D Gaussian Splat Viewer"**
4. Configure formatter settings:
   - **Viewer Width/Height**: CSS dimensions (e.g., `100%`, `600px`)
   - **Auto-generate**: Enable to automatically create splats when images are displayed
   - **Show fallback image**: Display original image while generating
   - **Enable VR/AR**: Enable WebXR modes
   - **Compression format**: `.sog` (compressed) or `.ply` (uncompressed)

5. Save and view your content

### Formatter Settings

#### Viewer Dimensions
- **Width**: CSS width (default: `100%`)
- **Height**: CSS height (default: `600px`)

#### Auto-generation
- **Disabled by default** - Splats are only generated when explicitly enabled
- When enabled, splats are queued for generation when images are displayed
- Processing happens via cron

#### Formats
- **SOG (.sog)**: SuperSplat compressed format - smaller files, faster loading
- **PLY (.ply)**: Uncompressed point cloud - larger but more compatible

### How It Works

```
User uploads image → Field formatter checks for existing splat
                           ↓
                    [Exists?]
                     ↙     ↘
                [Yes]      [No + auto-gen enabled]
                  ↓              ↓
            Load viewer    Queue generation job
                              ↓
                        Cron processes queue
                              ↓
                        Flask service generates splat
                              ↓
                        Download & save as file entity
                              ↓
                        Page auto-updates via AJAX
```

## File Storage

Splats are stored using the following convention:

- **Location**: `public://splats/`
- **Naming**: `{image_file_id}.{format}` (e.g., `123.sog`)
- **File Entities**: Created automatically for each splat
- **Cleanup**: Splats are automatically deleted when the source image is deleted

## Cron Configuration

Splat generation is processed via Drupal's cron. Ensure cron is configured to run regularly:

```bash
# Via Drush
drush cron

# Or configure automated cron in settings.php
$config['automated_cron.settings']['interval'] = 3600; // Run every hour
```

**Queue Settings**:
- Queue name: `sharp_web_splats_generation`
- Processing time: Up to 60 seconds per cron run
- Items are processed one at a time

## Monitoring

### Status Report

Check `/admin/reports/status` for:
- **SHARP Service**: Connection status
- **Queue Status**: Number of pending splat generation jobs

### Queue Statistics

View queue stats programmatically:

```php
$queue_service = \Drupal::service('sharp_web_splats.generation_queue');
$stats = $queue_service->getQueueStats();
// Returns: ['items' => 5, 'queued' => [123, 456], 'in_progress' => [789]]
```

## Troubleshooting

### Splat Not Generating

1. **Check SHARP service is running**:
   ```bash
   curl http://localhost:8080/health
   # Should return: {"status":"ok","device":"cpu","model_loaded":true}
   ```

2. **Verify auto-generation is enabled** in field formatter settings

3. **Run cron manually**:
   ```bash
   drush cron
   ```

4. **Check logs**:
   ```bash
   drush watchdog:show --type=sharp_web_splats
   ```

### Connection Failed

- Verify Flask service is running on the configured port
- Check firewall settings
- Ensure `api_url` in settings matches service location
- For Docker: Use `http://sharp-web-splats:8080` if running in same network

### Placeholder Stuck on "Generating"

- Check queue has been processed (run cron)
- Verify SHARP service didn't crash
- Check browser console for JavaScript errors
- Look for errors in Drupal logs

### File Permission Errors

Ensure `public://splats/` directory exists and is writable:

```bash
mkdir -p web/sites/default/files/splats
chmod 755 web/sites/default/files/splats
```

## API Reference

### Services

#### SharpApiClient

```php
$api_client = \Drupal::service('sharp_web_splats.api_client');

// Check service health
$is_healthy = $api_client->healthCheck();

// Generate splat
$result = $api_client->generateSplat('/path/to/image.jpg', ['format' => 'sog']);

// Download splat
$api_client->downloadSplat($result['url'], '/tmp/splat.sog');
```

#### SplatFileManager

```php
$file_manager = \Drupal::service('sharp_web_splats.file_manager');

// Get splat for image
$splat_file = $file_manager->getSplatForImage($image_file);

// Save splat
$splat_file = $file_manager->saveSplatFile($image_file, '/tmp/splat.sog', 'sog');

// Delete splat
$file_manager->deleteSplatForImage($image_file);

// Check if generating
$in_progress = $file_manager->isGenerationInProgress($image_file);
```

#### SplatGenerationQueue

```php
$queue = \Drupal::service('sharp_web_splats.generation_queue');

// Queue generation
$queue->queueGeneration($image_file, ['format' => 'sog']);

// Get stats
$stats = $queue->getQueueStats();
```

## Performance Optimization

### For High Traffic Sites

1. **Use SOG format** for faster loading
2. **Pre-generate splats** for known content
3. **Increase cron frequency** to process queue faster
4. **Use external queue** (e.g., Redis) for better scalability
5. **CDN integration** for serving splat files

### Pre-generating Splats

```php
// Example: Pre-generate for all article images
$nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['type' => 'article']);
$queue = \Drupal::service('sharp_web_splats.generation_queue');

foreach ($nodes as $node) {
  if ($node->hasField('field_image') && !$node->get('field_image')->isEmpty()) {
    $image_file = $node->get('field_image')->entity;
    $queue->queueGeneration($image_file);
  }
}
```

## Advanced Configuration

### Docker Networking

If running Drupal and SHARP service in Docker:

```yaml
# docker-compose.yml
services:
  drupal:
    links:
      - sharp-service

  sharp-service:
    build: .
    ports:
      - "8080:8080"
```

**Module configuration**: Set API URL to `http://sharp-service:8080`

### Custom Format

To add custom output formats, extend the queue worker:

```php
// In a custom module
function mymodule_queue_worker_alter(&$info) {
  $info['sharp_web_splats_generation']['class'] = '\Drupal\mymodule\Plugin\QueueWorker\CustomSplatWorker';
}
```

## Development

### Running Tests

```bash
# Unit tests
vendor/bin/phpunit modules/contrib/sharp_web_splats/tests/src/Unit

# Functional tests
vendor/bin/phpunit modules/contrib/sharp_web_splats/tests/src/Functional
```

### Debugging

Enable verbose logging:

```php
// In settings.php or settings.local.php
$config['system.logging']['error_level'] = 'verbose';
```

Watch logs:

```bash
drush watchdog:tail --filter=sharp_web_splats
```

## Known Limitations

- **Processing time**: Splat generation takes 1-5 seconds per image
- **Model size**: First-time download of SHARP model is ~2.7GB
- **Queue-based**: Real-time generation not supported (by design)
- **Single service**: Only one SHARP service URL supported per site

## Roadmap

- [ ] Batch regeneration UI
- [ ] Media library preview support
- [ ] Multiple SHARP service URLs (load balancing)
- [ ] WebSocket-based live updates
- [ ] CDN integration module
- [ ] Drush commands for bulk operations

## Support

- **Issues**: https://github.com/Craigmoore/sharp-web-splats/issues
- **Documentation**: https://github.com/Craigmoore/sharp-web-splats/wiki
- **Drupal.org**: https://www.drupal.org/project/sharp_web_splats

## License

GPL-2.0-or-later

## Credits

- **SHARP Model**: Apple Inc.
- **PlayCanvas**: PlayCanvas Ltd.
- **Module Author**: Craig Moore
