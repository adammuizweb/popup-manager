(function () {
  'use strict';

  var root = document.getElementById('jpm-admin');
  if (!root) return;

  function syncEditor() {
    var editor = root.matches('[data-jpm-editor]') ? root : root.querySelector('[data-jpm-editor]');
    if (!editor) return;
    var selectedType = editor.querySelector('input[name="content_type"]:checked');
    var imageFields = editor.querySelector('[data-jpm-image-fields]');
    var htmlFields = editor.querySelector('[data-jpm-html-fields]');
    var isHtml = selectedType && selectedType.value === 'html';
    if (imageFields) imageFields.hidden = isHtml;
    if (htmlFields) htmlFields.hidden = !isHtml;

    var targetMode = editor.querySelector('[data-jpm-target-mode]');
    var pathFields = editor.querySelector('[data-jpm-path-fields]');
    var contextFields = editor.querySelector('[data-jpm-context-fields]');
    if (pathFields) pathFields.hidden = !targetMode || targetMode.value !== 'paths';
    if (contextFields) contextFields.hidden = !targetMode || targetMode.value !== 'contexts';
    if (isHtml && htmlFields) {
      var htmlEditor = htmlFields.querySelector('[data-jpm-html-editor]');
      if (htmlEditor && typeof htmlEditor._jpmRefresh === 'function') window.setTimeout(htmlEditor._jpmRefresh, 0);
    }
  }

  root.querySelectorAll('input[name="content_type"]').forEach(function (input) {
    input.addEventListener('change', syncEditor);
  });
  root.querySelector('[data-jpm-target-mode]')?.addEventListener('change', syncEditor);

  function choosePublicMedia() {
    if (typeof window.openMediaSelector !== 'function') return Promise.resolve(null);
    var adminPath = window.ADMIN_PATH || '/adiwira';
    return window.openMediaSelector({url: adminPath + '/admin/modal_img/list_modal.php?embedded=1&visibility=public'}).then(function (detail) {
      var media = detail && detail.media && typeof detail.media === 'object' ? detail.media : detail;
      var visibility = media ? String(media.visibility || 'public').toLowerCase() : '';
      var storageDisk = media ? String(media.storage_disk || 'public').toLowerCase() : '';
      return media && media.url && visibility !== 'private' && storageDisk !== 'private' ? media : null;
    });
  }

  function initHtmlEditors() {
    var container = root.querySelector('[data-jpm-html-editor]');
    if (!container) return;
    var textarea = container.querySelector('#jpm-html-content');
    var codeArea = container.querySelector('[data-jpm-code-area]');
    var richArea = container.querySelector('[data-jpm-rich-area]');
    var richNode = container.querySelector('[data-jpm-rich-editor]');
    var modeInputs = Array.prototype.slice.call(container.querySelectorAll('input[name="html_editor_mode"]'));
    if (!textarea || !codeArea || !richArea || !richNode) return;
    var codeMirror = null;
    var quill = null;
    var quillRange = null;
    var syncing = false;
    var complexPromptOpen = false;
    var complexPattern = /<(script|style|iframe|embed|object|form|svg|canvas|php|link|meta|table|thead|tbody|tfoot|tr|th|td)[\s>]|on[a-z]+\s*=/i;

    if (typeof window.CodeMirror === 'function') {
      codeMirror = window.CodeMirror.fromTextArea(textarea, {
        mode: 'htmlmixed',
        lineNumbers: true,
        styleActiveLine: true,
        matchBrackets: true,
        autoCloseBrackets: true,
        autoCloseTags: true,
        indentUnit: 2,
        lineWrapping: true,
        theme: 'dracula',
        foldGutter: true,
        gutters: ['CodeMirror-linenumbers', 'CodeMirror-foldgutter']
      });
      codeMirror.setSize('100%', 420);
      codeMirror.on('change', function () {
        if (!syncing) textarea.value = codeMirror.getValue();
      });
    }

    if (typeof window.Quill === 'function') {
      quill = new window.Quill(richNode, {
        theme: 'snow',
        modules: {toolbar: [
          [{header: [1, 2, 3, 4, 5, 6, false]}],
          ['bold', 'italic', 'underline', 'strike'],
          [{color: []}, {background: []}],
          [{script: 'sub'}, {script: 'super'}],
          [{list: 'ordered'}, {list: 'bullet'}],
          [{indent: '-1'}, {indent: '+1'}],
          [{align: []}],
          ['blockquote', 'code-block'],
          ['link', 'image', 'video'],
          [{size: ['small', false, 'large', 'huge']}],
          ['clean']
        ]},
        placeholder: container.getAttribute('data-rich-placeholder') || 'Compose popup content...'
      });
      quill.clipboard.dangerouslyPasteHTML(textarea.value || '', 'silent');
      quill.on('selection-change', function (range) {
        if (range) quillRange = range;
      });
      quill.on('text-change', function () {
        if (!syncing && currentMode() === 'rich') textarea.value = quill.root.innerHTML;
      });
      quill.getModule('toolbar').addHandler('image', function () {
        var range = quill.getSelection() || quillRange || {index: Math.max(0, quill.getLength() - 1), length: 0};
        quill.root.blur();
        choosePublicMedia().then(function (media) {
          if (!media) return;
          quill.insertEmbed(range.index, 'image', String(media.url), 'user');
          quill.setSelection(range.index + 1, 0, 'silent');
        }).catch(function (error) {
          console.warn('[popup-manager] media selector failed', error);
        });
      });
    }

    function currentMode() {
      var selected = modeInputs.find(function (input) { return input.checked; });
      return selected ? selected.value : 'code';
    }

    function selectMode(mode) {
      modeInputs.forEach(function (input) { input.checked = input.value === mode; });
    }

    function codeValue() {
      return codeMirror ? codeMirror.getValue() : textarea.value;
    }

    function hasComplexHtml() {
      return complexPattern.test(codeValue().trim());
    }

    function confirmComplexSwitch() {
      var options = {
        title: container.getAttribute('data-complex-title') || 'Complex HTML detected',
        message: container.getAttribute('data-complex-message') || 'Rich Text may normalize unsupported HTML.',
        confirmText: container.getAttribute('data-complex-confirm') || 'Switch to Rich Text',
        cancelText: container.getAttribute('data-complex-cancel') || 'Stay in HTML Code'
      };
      if (window.NewNotifConfirm && typeof window.NewNotifConfirm.warning === 'function') {
        return Promise.resolve(window.NewNotifConfirm.warning(options));
      }
      return Promise.resolve(window.confirm(options.message));
    }

    function syncCodeToRich() {
      if (!quill) return;
      var html = codeMirror ? codeMirror.getValue() : textarea.value;
      syncing = true;
      try {
        textarea.value = html;
        quill.clipboard.dangerouslyPasteHTML(html, 'silent');
      } finally {
        syncing = false;
      }
    }

    function syncRichToCode() {
      if (!quill) return;
      var html = quill.root.innerHTML || '';
      syncing = true;
      try {
        textarea.value = html;
        if (codeMirror && codeMirror.getValue() !== html) codeMirror.setValue(html);
      } finally {
        syncing = false;
      }
    }

    function applyMode(syncFromPrevious) {
      var mode = currentMode();
      if (mode === 'rich' && !quill) mode = 'code';
      if (mode === 'code' && !codeMirror) mode = 'rich';
      selectMode(mode);
      if (syncFromPrevious) {
        if (mode === 'rich') syncCodeToRich();
        else syncRichToCode();
      }
      codeArea.hidden = mode !== 'code';
      richArea.hidden = mode !== 'rich';
      if (mode === 'code' && codeMirror) window.setTimeout(function () { codeMirror.refresh(); }, 0);
      try { window.localStorage.setItem('jpm_html_editor_mode', mode); } catch (error) {}
    }

    modeInputs.forEach(function (input) {
      input.addEventListener('change', function () {
        if (!input.checked) return;
        if (input.value !== 'rich' || !hasComplexHtml()) {
          applyMode(true);
          return;
        }
        selectMode('code');
        applyMode(false);
        if (complexPromptOpen) return;
        complexPromptOpen = true;
        confirmComplexSwitch().then(function (confirmed) {
          if (!confirmed) return;
          selectMode('rich');
          applyMode(true);
        }).catch(function (error) {
          console.warn('[popup-manager] complex HTML confirmation failed', error);
        }).finally(function () {
          complexPromptOpen = false;
        });
      });
    });
    container._jpmSetHtml = function (html) {
      syncing = true;
      try {
        textarea.value = html;
        if (codeMirror) codeMirror.setValue(html);
        if (quill) quill.clipboard.dangerouslyPasteHTML(html, 'silent');
      } finally {
        syncing = false;
      }
    };
    container._jpmRefresh = function () { if (codeMirror) codeMirror.refresh(); };
    textarea.form?.addEventListener('submit', function () {
      if (currentMode() === 'rich' && quill) textarea.value = quill.root.innerHTML || '';
      else if (codeMirror) codeMirror.save();
    });
    var preferred = 'code';
    try { preferred = window.localStorage.getItem('jpm_html_editor_mode') === 'rich' ? 'rich' : 'code'; } catch (error) {}
    if (preferred === 'rich' && (!quill || hasComplexHtml())) preferred = 'code';
    selectMode(preferred);
    applyMode(false);
  }

  root.querySelectorAll('[data-jpm-media]').forEach(function (field) {
    var idInput = field.querySelector('[data-jpm-media-id]');
    var preview = field.querySelector('[data-jpm-media-preview]');
    var empty = field.querySelector('[data-jpm-media-empty]');
    var choose = field.querySelector('[data-jpm-choose-media]');
    var clear = field.querySelector('[data-jpm-clear-media]');

    function setMedia(id, url) {
      if (idInput) idInput.value = id > 0 ? String(id) : '0';
      if (preview) {
        if (url) preview.src = url;
        else preview.removeAttribute('src');
        preview.hidden = !url;
      }
      if (empty) empty.hidden = !!url;
    }

    choose?.addEventListener('click', function () {
      choosePublicMedia().then(function (media) {
        if (!media) return;
        setMedia(parseInt(media.id, 10) || 0, String(media.url));
      }).catch(function (error) {
        console.warn('[popup-manager] media selector failed', error);
      });
    });
    clear?.addEventListener('click', function () { setMedia(0, ''); });
  });

  root.querySelector('[data-jpm-starter]')?.addEventListener('click', function () {
    var textarea = root.querySelector('#jpm-html-content');
    if (!textarea || textarea.value.trim()) return;
    var html = root.getAttribute('data-starter') || '';
    var editor = root.querySelector('[data-jpm-html-editor]');
    if (editor && typeof editor._jpmSetHtml === 'function') editor._jpmSetHtml(html);
    else textarea.value = html;
    var mode = root.querySelector('input[name="html_editor_mode"]:checked');
    if (mode && mode.value === 'rich') root.querySelector('[data-jpm-rich-editor] .ql-editor')?.focus();
    else if (editor) editor.querySelector('.CodeMirror textarea')?.focus();
    else textarea.focus();
  });

  root.querySelectorAll('[data-jpm-delete-form]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var message = root.getAttribute('data-delete-confirm') || 'Delete this popup campaign?';
      if (window.NewNotifConfirm && typeof window.NewNotifConfirm.danger === 'function') {
        window.NewNotifConfirm.danger({message: message, variant: 'danger'}).then(function (confirmed) {
          if (confirmed) form.submit();
        });
        return;
      }
      if (window.confirm(message)) form.submit();
    });
  });

  var bulkForm = root.querySelector('#jpm-bulk-form');
  var selectAll = root.querySelector('[data-jpm-select-all]');
  var rowChecks = Array.prototype.slice.call(root.querySelectorAll('[data-jpm-select-row]'));
  var selectedCount = root.querySelector('[data-jpm-selected-count]');
  var bulkApply = root.querySelector('[data-jpm-bulk-apply]');
  function syncBulk() {
    var checked = rowChecks.filter(function (input) { return input.checked; });
    if (selectedCount) selectedCount.textContent = (root.getAttribute('data-selected-template') || '%d selected').replace('%d', String(checked.length));
    if (bulkApply) bulkApply.disabled = checked.length === 0;
    if (selectAll) {
      selectAll.checked = rowChecks.length > 0 && checked.length === rowChecks.length;
      selectAll.indeterminate = checked.length > 0 && checked.length < rowChecks.length;
    }
    rowChecks.forEach(function (input) {
      var row = input.closest('[data-jpm-row]');
      if (row) row.classList.toggle('is-selected', input.checked);
    });
  }
  selectAll?.addEventListener('change', function () {
    rowChecks.forEach(function (input) { input.checked = selectAll.checked; });
    syncBulk();
  });
  rowChecks.forEach(function (input) { input.addEventListener('change', syncBulk); });
  bulkForm?.addEventListener('submit', function (event) {
    if (!rowChecks.some(function (input) { return input.checked; })) {
      event.preventDefault();
      return;
    }
    var action = bulkForm.querySelector('[name="bulk_action"]');
    if (!action || action.value !== 'delete') return;
    event.preventDefault();
    var message = root.getAttribute('data-bulk-delete-confirm') || 'Delete all selected popup campaigns?';
    if (window.NewNotifConfirm && typeof window.NewNotifConfirm.danger === 'function') {
      window.NewNotifConfirm.danger({message: message, variant: 'danger'}).then(function (confirmed) {
        if (confirmed) bulkForm.submit();
      });
      return;
    }
    if (window.confirm(message)) bulkForm.submit();
  });

  var columns = root.querySelector('[data-jpm-columns]');
  var columnToggles = Array.prototype.slice.call(root.querySelectorAll('[data-jpm-column-toggle]'));
  var columnStorageKey = 'jpm_popup_campaign_columns';
  var columnState = {};
  try {
    var storedColumns = JSON.parse(window.localStorage.getItem(columnStorageKey) || '{}');
    if (storedColumns && typeof storedColumns === 'object') columnState = storedColumns;
  } catch (error) {}
  function applyColumns() {
    columnToggles.forEach(function (toggle) {
      var key = toggle.value;
      var visible = columnState[key] !== false;
      toggle.checked = visible;
      root.querySelectorAll('[data-jpm-column="' + key + '"]').forEach(function (cell) {
        cell.classList.toggle('jpm-col-hidden', !visible);
      });
    });
  }
  columnToggles.forEach(function (toggle) {
    toggle.addEventListener('change', function () {
      columnState[toggle.value] = toggle.checked;
      try { window.localStorage.setItem(columnStorageKey, JSON.stringify(columnState)); } catch (error) {}
      applyColumns();
    });
  });

  var actionMenus = Array.prototype.slice.call(root.querySelectorAll('[data-jpm-action-menu]'));
  function actionPanel(menu) {
    return menu._jpmActionPanel || menu.querySelector('[data-jpm-action-panel]');
  }
  function actionControls(menu) {
    var panel = actionPanel(menu);
    return panel ? Array.prototype.slice.call(panel.querySelectorAll('a[href],button:not([disabled])')) : [];
  }
  function closeActionMenu(menu, restoreFocus) {
    var trigger = menu.querySelector('[data-jpm-action-trigger]');
    var panel = actionPanel(menu);
    if (!trigger || !panel || panel.hidden) return;
    panel.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
    menu.appendChild(panel);
    if (restoreFocus) trigger.focus();
  }
  function closeActionMenus(except, restoreFocus) {
    actionMenus.forEach(function (menu) {
      if (menu !== except) closeActionMenu(menu, restoreFocus === true);
    });
  }
  function positionActionPanel(trigger, panel) {
    var rect = trigger.getBoundingClientRect();
    var width = Math.max(180, panel.offsetWidth || 0);
    var left = Math.min(window.innerWidth - width - 8, Math.max(8, rect.right - width));
    var top = rect.bottom + 6;
    var height = panel.offsetHeight || 0;
    if (top + height > window.innerHeight - 8) top = Math.max(8, rect.top - height - 6);
    top = Math.min(Math.max(8, window.innerHeight - height - 8), Math.max(8, top));
    panel.style.left = String(left) + 'px';
    panel.style.top = String(top) + 'px';
  }
  function openActionMenu(menu, focusFirst) {
    var trigger = menu.querySelector('[data-jpm-action-trigger]');
    var panel = actionPanel(menu);
    if (!trigger || !panel) return;
    closeActionMenus(menu, false);
    menu._jpmActionPanel = panel;
    document.body.appendChild(panel);
    panel.hidden = false;
    trigger.setAttribute('aria-expanded', 'true');
    positionActionPanel(trigger, panel);
    if (focusFirst) actionControls(menu)[0]?.focus();
  }
  actionMenus.forEach(function (menu) {
    var trigger = menu.querySelector('[data-jpm-action-trigger]');
    var panel = menu.querySelector('[data-jpm-action-panel]');
    if (!trigger || !panel) return;
    menu._jpmActionPanel = panel;
    trigger.addEventListener('click', function () {
      if (panel.hidden) openActionMenu(menu, false);
      else closeActionMenu(menu, false);
    });
    trigger.addEventListener('keydown', function (event) {
      if (event.key !== 'ArrowDown') return;
      event.preventDefault();
      openActionMenu(menu, true);
    });
    panel.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        event.preventDefault();
        closeActionMenu(menu, true);
        return;
      }
      if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
      var controls = actionControls(menu);
      if (!controls.length) return;
      event.preventDefault();
      var current = controls.indexOf(document.activeElement);
      var next = event.key === 'Home' ? 0 : event.key === 'End' ? controls.length - 1
        : event.key === 'ArrowUp' ? (current <= 0 ? controls.length - 1 : current - 1)
          : (current + 1) % controls.length;
      controls[next].focus();
    });
    panel.addEventListener('click', function (event) {
      if (event.target.closest('a[href],button')) closeActionMenu(menu, false);
    });
  });
  document.addEventListener('click', function (event) {
    actionMenus.forEach(function (menu) {
      var panel = actionPanel(menu);
      if (!menu.contains(event.target) && (!panel || !panel.contains(event.target))) closeActionMenu(menu, false);
    });
    if (columns && columns.open && !columns.contains(event.target)) columns.open = false;
  });
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    var openMenu = actionMenus.find(function (menu) {
      var panel = actionPanel(menu);
      return panel && !panel.hidden;
    });
    if (openMenu) {
      event.preventDefault();
      closeActionMenu(openMenu, true);
      return;
    }
    if (!columns?.open) return;
    columns.open = false;
    columns.querySelector('summary')?.focus();
  });
  window.addEventListener('resize', function () { closeActionMenus(null, false); });
  window.addEventListener('scroll', function () { closeActionMenus(null, false); }, true);

  syncEditor();
  initHtmlEditors();
  syncEditor();
  syncBulk();
  applyColumns();
})();
