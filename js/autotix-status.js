/**
 * @file
 * Autotix status dashboard — fetches recent webhook delivery stats
 * and renders them inline on the settings page.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  /**
   * Format a Unix timestamp as a human-readable "time ago" string.
   */
  function timeAgo(timestamp) {
    const seconds = Math.floor((Date.now() / 1000) - timestamp);
    if (seconds < 60) return seconds + 's ago';
    if (seconds < 3600) return Math.floor(seconds / 60) + 'm ago';
    if (seconds < 86400) return Math.floor(seconds / 3600) + 'h ago';
    return Math.floor(seconds / 86400) + 'd ago';
  }

  /**
   * Render the status summary into the container element.
   */
  function renderStatus(container, data) {
    const statusLabel = data.status.toUpperCase();
    const lastDelivery = data.lastDeliveryAt
      ? timeAgo(data.lastDeliveryAt)
      : 'never';

    const html = `
      <div class="autotix-status autotix-status--${data.status}">
        <span class="autotix-status__label">${statusLabel}</span>
        <span class="autotix-status__detail">
          ${data.totalDelivered} delivered &middot; ${data.totalFailed} failed
          &middot; last: ${lastDelivery}
        </span>
      </div>
    `;

    container.innerHTML = html;
  }

  Drupal.behaviors.autotixStatus = {
    attach: function (context) {
      once('autotix-status', '[data-autotix-status]', context).forEach(
        function (el) {
          const endpoint = drupalSettings.autotix?.statusEndpoint;
          if (!endpoint) return;

          fetch(endpoint, { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
              renderStatus(el, data);
            })
            .catch(function (err) {
              el.innerHTML =
                '<em class="color-warning">Could not load status.</em>';
              console.error('Autotix status fetch failed:', err);
            });
        }
      );
    },
  };
})(Drupal, drupalSettings, once);
