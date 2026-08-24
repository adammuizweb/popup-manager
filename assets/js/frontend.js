(function () {
  'use strict';

  var root = document.querySelector('[data-jpm-popup]');
  if (!root) return;
  var configNode = root.querySelector('[data-jpm-config]');
  var dialog = root.querySelector('.jpm-popup__dialog');
  if (!configNode || !dialog) return;

  var config;
  try { config = JSON.parse(configNode.textContent || '{}'); } catch (error) { return; }
  if (!config || !Number(config.id)) return;

  var baseKey = 'jpm:' + String(config.id) + ':' + String(config.revision || '1');
  var frequency = String(config.frequency || 'session');
  var stateKey = baseKey;
  var storage = null;
  function availableStorage(name) {
    try { return window[name] || null; } catch (error) { return null; }
  }
  if (frequency === 'session') storage = availableStorage('sessionStorage');
  if (frequency === 'path_session') {
    storage = availableStorage('sessionStorage');
    stateKey += ':path:' + encodeURIComponent(String(config.path || window.location.pathname));
  }
  if (frequency === 'visitor' || frequency === 'daily') storage = availableStorage('localStorage');

  function readState() {
    if (!storage) return null;
    try { return storage.getItem(stateKey); } catch (error) { return null; }
  }
  function shouldOpen() {
    if (frequency === 'every_view') return true;
    var value = readState();
    if (!value) return true;
    if (frequency !== 'daily') return false;
    var seenAt = Number(value);
    return !Number.isFinite(seenAt) || Date.now() - seenAt >= 86400000;
  }
  function markSeen() {
    if (!storage) return;
    try { storage.setItem(stateKey, frequency === 'daily' ? String(Date.now()) : '1'); } catch (error) {}
  }
  if (!shouldOpen()) return;

  var previouslyFocused = null;
  var closing = false;
  function focusable() {
    return Array.prototype.slice.call(dialog.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'));
  }
  function closePopup() {
    if (closing || root.hidden) return;
    closing = true;
    root.classList.remove('is-open');
    root.setAttribute('aria-hidden', 'true');
    document.documentElement.classList.remove('jpm-popup-lock');
    window.setTimeout(function () {
      root.hidden = true;
      closing = false;
      if (previouslyFocused && typeof previouslyFocused.focus === 'function') previouslyFocused.focus();
    }, 180);
  }
  function openPopup() {
    previouslyFocused = document.activeElement;
    markSeen();
    root.hidden = false;
    root.setAttribute('aria-hidden', 'false');
    document.documentElement.classList.add('jpm-popup-lock');
    window.requestAnimationFrame(function () {
      root.classList.add('is-open');
      var items = focusable();
      (items[0] || dialog).focus();
    });
  }

  root.querySelectorAll('[data-jpm-close]').forEach(function (button) {
    button.addEventListener('click', closePopup);
  });
  root.querySelector('[data-jpm-overlay]')?.addEventListener('click', function () {
    if (config.closeOnOverlay) closePopup();
  });
  document.addEventListener('keydown', function (event) {
    if (root.hidden) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      closePopup();
      return;
    }
    if (event.key !== 'Tab') return;
    var items = focusable();
    if (!items.length) {
      event.preventDefault();
      dialog.focus();
      return;
    }
    var first = items[0];
    var last = items[items.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  window.setTimeout(openPopup, Math.max(0, Math.min(60000, Number(config.delay) || 0)));
})();
