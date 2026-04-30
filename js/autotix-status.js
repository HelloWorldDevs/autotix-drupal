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
   * Whitelist of known status values. Anything else falls back to "unknown".
   */
  const KNOWN_STATUSES = ['ok', 'failed', 'unknown'];

  /**
   * Render the status summary into the container element using DOM APIs
   * (textContent only) so values from the endpoint can't inject HTML.
   */
  function renderStatus(container, data) {
    const rawStatus =
      typeof data.status === 'string' && data.status.trim()
        ? data.status.trim().toLowerCase()
        : 'unknown';
    const status = KNOWN_STATUSES.includes(rawStatus) ? rawStatus : 'unknown';

    const lastDelivery = data.lastDeliveryAt
      ? timeAgo(data.lastDeliveryAt)
      : 'never';
    const totalDelivered = Number(data.totalDelivered) || 0;
    const totalFailed = Number(data.totalFailed) || 0;

    container.replaceChildren();

    const wrapper = document.createElement('div');
    wrapper.className = 'autotix-status autotix-status--' + status;

    const label = document.createElement('span');
    label.className = 'autotix-status__label';
    label.textContent = status.toUpperCase();
    wrapper.appendChild(label);

    const detail = document.createElement('span');
    detail.className = 'autotix-status__detail';
    detail.textContent =
      totalDelivered + ' delivered · ' +
      totalFailed + ' failed · last: ' +
      lastDelivery;
    wrapper.appendChild(detail);

    container.appendChild(wrapper);
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
              el.replaceChildren();
              const em = document.createElement('em');
              em.className = 'color-warning';
              em.textContent = 'Could not load status.';
              el.appendChild(em);
              console.error('Autotix status fetch failed:', err);
            });
        }
      );
    },
  };
})(Drupal, drupalSettings, once);
