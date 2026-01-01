/**
 * @file
 * PlayCanvas Gaussian Splat Viewer initialization.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.sharpSplatViewer = {
    attach: function (context, settings) {
      const viewerSettings = drupalSettings.sharpWebSplats || {};
      const viewers = viewerSettings.viewers || {};

      // Initialize all viewers.
      once('splat-viewer', '.splat-viewer', context).forEach((viewer) => {
        const splatUrl = viewer.dataset.splatUrl;

        if (!splatUrl) {
          console.warn('SHARP Web Splats: No splat URL provided for viewer');
          return;
        }

        // PlayCanvas web components handle the initialization automatically.
        // The viewer is already set up via the template.
        // We just need to add any custom event listeners if needed.

        const pcApp = viewer.querySelector('pc-app');
        if (pcApp) {
          // Wait for PlayCanvas app to be ready.
          pcApp.addEventListener('ready', function() {
            console.log('SHARP Web Splats: Viewer initialized successfully');
          });

          // Handle XR buttons if enabled.
          const vrBtn = viewer.querySelector('#vrBtn');
          const arBtn = viewer.querySelector('#arBtn');

          if (vrBtn) {
            vrBtn.addEventListener('click', function() {
              if (pcApp.xr && pcApp.xr.isAvailable('immersive-vr')) {
                pcApp.xr.start(pcApp.root.findByName('camera'), 'immersive-vr');
              }
            });
          }

          if (arBtn) {
            arBtn.addEventListener('click', function() {
              if (pcApp.xr && pcApp.xr.isAvailable('immersive-ar')) {
                pcApp.xr.start(pcApp.root.findByName('camera'), 'immersive-ar');
              }
            });
          }
        }
      });
    }
  };

})(Drupal, drupalSettings, once);
