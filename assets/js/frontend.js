(function () {
  'use strict';

  var queueRoot = document.querySelector('[data-jpm-queue]');
  if (!queueRoot) return;

  function availableStorage(name) {
    try { return window[name] || null; } catch (error) { return null; }
  }

  function createItem(root) {
    var configNode = root.querySelector('[data-jpm-config]');
    var dialog = root.querySelector('.jpm-popup__dialog');
    if (!configNode || !dialog) return null;
    var config;
    try { config = JSON.parse(configNode.textContent || '{}'); } catch (error) { return null; }
    if (!config || !Number(config.id)) return null;

    var frequency = String(config.frequency || 'session');
    var stateKey = 'jpm:' + String(config.id) + ':' + String(config.revision || '1');
    var storage = null;
    if (frequency === 'session') storage = availableStorage('sessionStorage');
    if (frequency === 'path_session') {
      storage = availableStorage('sessionStorage');
      stateKey += ':path:' + encodeURIComponent(String(config.path || window.location.pathname));
    }
    if (frequency === 'visitor' || frequency === 'daily') storage = availableStorage('localStorage');

    return {
      root: root,
      dialog: dialog,
      config: config,
      frequency: frequency,
      stateKey: stateKey,
      storage: storage,
      tracked: {},
      slides: Array.prototype.slice.call(root.querySelectorAll('[data-jpm-slide]')),
      dots: Array.prototype.slice.call(root.querySelectorAll('[data-jpm-dot]')),
      slideIndex: 0
    };
  }

  function readState(item) {
    if (!item.storage) return null;
    try { return item.storage.getItem(item.stateKey); } catch (error) { return null; }
  }

  function shouldOpen(item) {
    if (item.frequency === 'every_view') return true;
    var value = readState(item);
    if (!value) return true;
    if (item.frequency !== 'daily') return false;
    var seenAt = Number(value);
    return !Number.isFinite(seenAt) || Date.now() - seenAt >= 86400000;
  }

  function markSeen(item) {
    if (!item.storage) return;
    try { item.storage.setItem(item.stateKey, item.frequency === 'daily' ? String(Date.now()) : '1'); } catch (error) {}
  }

  function track(item, eventName) {
    if (item.tracked[eventName] || !item.config.eventToken || !item.config.eventUrl) return;
    item.tracked[eventName] = true;
    var body = JSON.stringify({event: eventName, token: item.config.eventToken});
    try {
      if (navigator.sendBeacon) {
        navigator.sendBeacon(item.config.eventUrl, new Blob([body], {type: 'application/json'}));
        return;
      }
      window.fetch(item.config.eventUrl, {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: {'Content-Type': 'application/json'},
        body: body
      }).catch(function () {});
    } catch (error) {}
  }

  var items = Array.prototype.map.call(queueRoot.querySelectorAll('[data-jpm-popup]'), createItem).filter(Boolean);
  if (!items.length) return;
  var currentIndex = -1;
  var current = null;
  var closing = false;
  var previouslyFocused = null;

  function focusable(item) {
    return Array.prototype.slice.call(item.dialog.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])')).filter(function (control) {
      return !control.closest('[hidden]');
    });
  }

  function showSlide(item, index) {
    if (item.slides.length < 2) return;
    var next = (index + item.slides.length) % item.slides.length;
    item.slideIndex = next;
    item.slides.forEach(function (slide, slideIndex) {
      var active = slideIndex === next;
      slide.hidden = !active;
      slide.setAttribute('aria-hidden', active ? 'false' : 'true');
    });
    item.dots.forEach(function (dot, dotIndex) {
      dot.setAttribute('aria-current', dotIndex === next ? 'true' : 'false');
    });
    var status = item.root.querySelector('[data-jpm-status]');
    if (status) status.textContent = String(next + 1) + ' / ' + String(item.slides.length);
  }

  function nextEligible() {
    for (var index = currentIndex + 1; index < items.length; index++) {
      if (shouldOpen(items[index])) return index;
    }
    return -1;
  }

  function scheduleNext() {
    var next = nextEligible();
    if (next < 0) {
      current = null;
      if (previouslyFocused && typeof previouslyFocused.focus === 'function') previouslyFocused.focus();
      return;
    }
    currentIndex = next;
    current = items[next];
    window.setTimeout(openCurrent, Math.max(0, Math.min(60000, Number(current.config.delay) || 0)));
  }

  function closeCurrent() {
    if (!current || closing || current.root.hidden) return;
    closing = true;
    track(current, 'close');
    current.root.classList.remove('is-open');
    current.root.setAttribute('aria-hidden', 'true');
    document.documentElement.classList.remove('jpm-popup-lock');
    window.setTimeout(function () {
      current.root.hidden = true;
      closing = false;
      scheduleNext();
    }, 180);
  }

  function openCurrent() {
    if (!current) return;
    showSlide(current, 0);
    markSeen(current);
    track(current, 'impression');
    current.root.hidden = false;
    current.root.setAttribute('aria-hidden', 'false');
    document.documentElement.classList.add('jpm-popup-lock');
    window.requestAnimationFrame(function () {
      if (!current) return;
      current.root.classList.add('is-open');
      var controls = focusable(current);
      (controls[0] || current.dialog).focus();
    });
  }

  items.forEach(function (item) {
    item.root.querySelectorAll('[data-jpm-close]').forEach(function (button) {
      button.addEventListener('click', closeCurrent);
    });
    var overlay = item.root.querySelector('[data-jpm-overlay]');
    if (overlay) overlay.addEventListener('click', function () {
      if (current === item && item.config.closeOnOverlay) closeCurrent();
    });
    item.root.addEventListener('click', function (event) {
      if (current === item && event.target.closest('a[href]')) track(item, 'click');
    });
    item.root.querySelector('[data-jpm-prev]')?.addEventListener('click', function () { showSlide(item, item.slideIndex - 1); });
    item.root.querySelector('[data-jpm-next]')?.addEventListener('click', function () { showSlide(item, item.slideIndex + 1); });
    item.dots.forEach(function (dot) {
      dot.addEventListener('click', function () { showSlide(item, Number(dot.getAttribute('data-jpm-slide-index')) || 0); });
    });
  });

  document.addEventListener('keydown', function (event) {
    if (!current || current.root.hidden) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      closeCurrent();
      return;
    }
    if ((event.key === 'ArrowLeft' || event.key === 'ArrowRight') && !event.target.closest('[data-jpm-slide]')) {
      event.preventDefault();
      showSlide(current, current.slideIndex + (event.key === 'ArrowRight' ? 1 : -1));
      return;
    }
    if (event.key !== 'Tab') return;
    var controls = focusable(current);
    if (!controls.length) {
      event.preventDefault();
      current.dialog.focus();
      return;
    }
    var first = controls[0];
    var last = controls[controls.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  previouslyFocused = document.activeElement;
  scheduleNext();
})();
