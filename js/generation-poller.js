/**
 * @file
 * AJAX polling for splat generation completion.
 */

(function (Drupal, drupalSettings, $, once) {
  'use strict';

  Drupal.behaviors.sharpGenerationPoller = {
    attach: function (context, settings) {
      const pollerSettings = drupalSettings.sharpWebSplats || {};
      const pollers = pollerSettings.pollers || {};

      once('splat-poller', '.splat-placeholder', context).forEach((placeholder) => {
        const imageFileId = placeholder.dataset.imageFileId;

        if (!imageFileId) {
          console.warn('SHARP Web Splats: No image file ID for poller');
          return;
        }

        const pollConfig = pollers['poller_' + imageFileId];
        if (!pollConfig || !pollConfig.pollingEnabled) {
          return;
        }

        // Poll for completion every 3 seconds.
        const pollInterval = setInterval(function() {
          $.ajax({
            url: '/sharp-web-splats/check-generation/' + imageFileId,
            method: 'GET',
            success: function(response) {
              if (response.status === 'complete' && response.splatUrl) {
                clearInterval(pollInterval);

                // Replace placeholder with viewer.
                if (response.viewerHtml) {
                  $(placeholder).replaceWith(response.viewerHtml);
                  // Re-attach Drupal behaviors to the new content.
                  Drupal.attachBehaviors(placeholder.parentElement);
                }
              }
              else if (response.status === 'failed') {
                clearInterval(pollInterval);
                $(placeholder).find('.splat-status').html(
                  '<div class="splat-error">' +
                  Drupal.t('Generation failed. Please try again.') +
                  '</div>'
                );
              }
              // If status is 'processing' or 'pending', keep polling.
            },
            error: function(xhr, status, error) {
              console.error('SHARP Web Splats: Polling error', error);
            }
          });
        }, 3000); // Poll every 3 seconds.

        // Stop polling after 5 minutes (safety timeout).
        setTimeout(function() {
          clearInterval(pollInterval);
          console.log('SHARP Web Splats: Polling timeout reached for file ' + imageFileId);
        }, 300000); // 5 minutes.
      });
    }
  };

})(Drupal, drupalSettings, jQuery, once);
