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
    if (pathFields) pathFields.hidden = !targetMode || targetMode.value !== 'paths';
  }

  root.querySelectorAll('input[name="content_type"]').forEach(function (input) {
    input.addEventListener('change', syncEditor);
  });
  root.querySelector('[data-jpm-target-mode]')?.addEventListener('change', syncEditor);

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
      if (typeof window.openMediaSelector !== 'function') return;
      var adminPath = window.ADMIN_PATH || '/adiwira';
      window.openMediaSelector({url: adminPath + '/admin/modal_img/list_modal.php?embedded=1&visibility=public'}).then(function (detail) {
        var media = detail && detail.media && typeof detail.media === 'object' ? detail.media : detail;
        if (!media || !media.url) return;
        setMedia(parseInt(media.id, 10) || 0, String(media.url));
      });
    });
    clear?.addEventListener('click', function () { setMedia(0, ''); });
  });

  root.querySelector('[data-jpm-starter]')?.addEventListener('click', function () {
    var textarea = root.querySelector('#jpm-html-content');
    if (!textarea || textarea.value.trim()) return;
    textarea.value = root.getAttribute('data-starter') || '';
    textarea.focus();
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

  syncEditor();
})();
