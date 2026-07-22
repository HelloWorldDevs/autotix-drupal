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
   * Format a FUTURE Unix timestamp as "in Xd" (or "due now" once passed).
   */
  function timeUntil(timestamp) {
    const seconds = Math.floor(timestamp - (Date.now() / 1000));
    if (seconds <= 0) return 'due now';
    if (seconds < 3600) return 'in ' + Math.max(1, Math.floor(seconds / 60)) + 'm';
    if (seconds < 86400) return 'in ' + Math.floor(seconds / 3600) + 'h';
    return 'in ' + Math.floor(seconds / 86400) + 'd';
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
    container.appendChild(renderSetupHealth(data));
  }

  /**
   * Setup-health checklist: is the module wired end to end?  Each row is
   * a plain OK / attention line built with textContent only.
   */
  function renderSetupHealth(data) {
    const list = document.createElement('ul');
    list.className = 'autotix-status__health';

    function row(ok, text) {
      const li = document.createElement('li');
      li.className =
        'autotix-status__health-item autotix-status__health-item--' +
        (ok === true ? 'ok' : ok === false ? 'warn' : 'muted');
      li.textContent = (ok === true ? '✓ ' : ok === false ? '✗ ' : '· ') + text;
      list.appendChild(li);
    }

    row(Boolean(data.enabled), data.enabled
      ? 'Error forwarding enabled'
      : 'Error forwarding disabled — enable it above');

    // credentialOk reflects the credential the CURRENT auth method needs.
    // (tokenConfigured is the pre-1.0.2 field name — fall back for a stale
    // cached response.)
    const credentialOk =
      data.credentialOk != null ? data.credentialOk : data.tokenConfigured;
    if (data.authMethod === 'none') {
      row(true, 'Authentication disabled (no credential required)');
    } else if (data.authMethod === 'hmac') {
      row(Boolean(credentialOk), credentialOk
        ? 'HMAC secret configured'
        : 'No HMAC secret — set a Key or the AUTOTIX_HMAC_SECRET env var');
    } else {
      row(Boolean(credentialOk), credentialOk
        ? 'Auth token configured'
        : 'No auth token — set a Key or the AUTOTIX_AUTH_TOKEN env var');
    }

    if (data.lastDeliveryAt) {
      row(data.status === 'ok',
        'Last error delivery ' + (data.status === 'ok' ? 'succeeded ' : 'FAILED ') +
        timeAgo(data.lastDeliveryAt));
    } else {
      row(null, 'No errors delivered yet — use "Send test error" to verify');
    }

    // Environment reporting (Vulnerability Scanning feed).
    if (!data.envLastReportAt) {
      row(null,
        'Environment not reported yet — runs on cron, or use "Report environment now"');
    } else if (data.envSent === false) {
      row(false,
        'Environment report FAILED ' + timeAgo(data.envLastReportAt) +
        ' — check connectivity/token');
    } else if (data.envStored === false) {
      row(false,
        'Environment report declined ' + timeAgo(data.envLastReportAt) +
        (data.envReason
          ? ' — ' + data.envReason
          : ' — enable Vulnerability Scanning for this org in Autotix'));
    } else {
      row(true,
        'Environment reported ' + timeAgo(data.envLastReportAt) +
        (data.envNextCheckAt
          ? ' · next check ' + timeUntil(data.envNextCheckAt)
          : ''));
    }

    return list;
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
