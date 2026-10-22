/*!
 * «Ежедневный стандарт» — editorial desk interactions. Vanilla JS, no build step.
 * Progressive enhancement: every form works without it; this adds safety (unsaved-change
 * guard, local draft copy), speed (counters, live previews, reorder) and clarity.
 */
(function () {
  'use strict';

  var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
  var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); };
  var desk = $('.es-desk');
  if (!desk) { return; }
  var store = {
    get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* quota/private mode */ } },
    del: function (k) { try { window.localStorage.removeItem(k); } catch (e) { /* ignore */ } }
  };
  var params = new URLSearchParams(window.location.search);

  /* -------------------------------------------------------- autogrow */
  function grow(el) { el.style.height = 'auto'; el.style.height = Math.min(el.scrollHeight + 2, 900) + 'px'; }
  function bindGrow(ctx) { $$('textarea[data-es-autogrow]', ctx).forEach(function (t) { grow(t); t.addEventListener('input', function () { grow(t); }); }); }
  bindGrow(desk);

  /* -------------------------------------------------------- counters */
  function words(text) { var m = String(text).trim().match(/[\p{L}\p{N}][\p{L}\p{N}'’-]*/gu); return m ? m.length : 0; }
  function plural(n, one, few, many) { var a = n % 10, b = n % 100; return a === 1 && b !== 11 ? one : (a >= 2 && a <= 4 && (b < 12 || b > 14) ? few : many); }
  function bindCounter(field) {
    var hint = $('[data-es-count-for="' + field.id + '"]');
    if (!hint) { return; }
    var base = hint.textContent;
    var mode = hint.getAttribute('data-es-count-mode') || 'chars';
    var range = (hint.getAttribute('data-es-range') || '').split('-').map(Number);
    var update = function () {
      var n = mode === 'words' ? words(field.value) : field.value.trim().length;
      var unit = mode === 'words' ? plural(n, 'слово', 'слова', 'слов') : 'симв.';
      hint.textContent = base + ' · ' + n + ' ' + unit;
      hint.classList.remove('is-ok', 'is-warn');
      if (range.length === 2 && n > 0) { hint.classList.add(n >= range[0] && n <= range[1] ? 'is-ok' : 'is-warn'); }
    };
    field.addEventListener('input', update);
    update();
  }
  $$('[data-es-count]').forEach(bindCounter);

  /* -------------------------------------------------------- unsaved changes */
  var submitting = false;
  $$('form[data-es-dirty-guard]').forEach(function (form) {
    var label = $('[data-es-dirty-label]', form) || $('[data-es-dirty-label]');
    var mark = function () {
      form.classList.add('is-dirty');
      $$('[data-es-dirty-label]', form).forEach(function (l) { l.textContent = 'Есть несохранённые изменения'; });
      if (label && !form.contains(label)) { label.textContent = 'Есть несохранённые изменения'; }
    };
    form.addEventListener('input', mark);
    form.addEventListener('change', mark);
    form.addEventListener('es:dirty', mark);
    form.addEventListener('submit', function () { submitting = true; });
  });
  window.addEventListener('beforeunload', function (e) {
    if (submitting) { return; }
    if ($('form.is-dirty')) { e.preventDefault(); e.returnValue = ''; }
  });

  /* -------------------------------------------------------- confirmations */
  $$('form[data-es-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (!window.confirm(form.getAttribute('data-es-confirm'))) { e.preventDefault(); submitting = false; }
    });
  });
  // Irreversible actions (publish, open to readers, withdraw, finish upgrade): the button
  // wakes up only after the explicit tick. The server checks the same field again.
  $$('form').forEach(function (form) {
    var box = $('input[type="checkbox"][name="confirm"][required]', form);
    if (!box || box.disabled) { return; }
    var buttons = $$('button[type="submit"], button:not([type])', form).filter(function (b) { return !b.disabled; });
    function sync() { buttons.forEach(function (b) { b.disabled = !box.checked; }); }
    box.addEventListener('change', sync);
    sync();
  });

  /* -------------------------------------------------------- repeaters */
  function nextIndex(items) {
    var max = -1;
    $$('[name]', items).forEach(function (el) {
      var m = el.name.match(/\[(sources|claims|quotes)\]\[(\d+)\]/);
      if (m) { max = Math.max(max, parseInt(m[2], 10)); }
    });
    return max + 1;
  }
  function addRow(repeat) {
    var tpl = $('template[data-es-template]', repeat);
    var items = $('[data-es-items]', repeat);
    var html = tpl.innerHTML.replace(/__INDEX__/g, String(nextIndex(items)));
    var holder = document.createElement('div');
    holder.innerHTML = html.trim();
    var row = holder.firstElementChild;
    items.appendChild(row);
    bindRow(row);
    syncSources();
    var first = $('input:not([type=hidden]), textarea', row);
    if (first) { first.focus(); }
    row.closest('form').dispatchEvent(new Event('es:dirty'));
    return row;
  }
  function bindRow(row) {
    bindGrow(row);
    var remove = $('[data-es-remove-row]', row);
    if (remove) {
      remove.addEventListener('click', function () {
        var filled = $$('input[type=text], input[type=url], textarea', row).some(function (f) { return f.value.trim() !== ''; });
        if (filled && !window.confirm('Убрать эту строку вместе с её содержимым?')) { return; }
        var form = row.closest('form');
        row.remove();
        syncSources();
        if (form) { form.dispatchEvent(new Event('es:dirty')); }
      });
    }
    var level = $('[data-es-level]', row);
    if (level) {
      level.addEventListener('change', function () {
        row.className = row.className.replace(/es-claim-row--\S+/, '') + ' es-claim-row--' + level.value;
      });
    }
    var url = $('[data-es-url]', row);
    var open = $('[data-es-open-url]', row);
    if (url && open) { url.addEventListener('input', function () { open.href = url.value; }); }
    var publisher = $('[data-es-source-publisher]', row);
    var title = $('[data-es-source-title]', row);
    if (publisher && title) { publisher.addEventListener('input', function () { title.textContent = publisher.value || 'Новый источник'; }); }
    var sid = $('[data-es-source-id]', row);
    var badge = $('[data-es-source-label]', row);
    if (sid) { sid.addEventListener('input', function () { if (badge) { badge.textContent = sid.value; } syncSources(); }); }
    var now = $('[data-es-now]', row);
    var checked = $('[data-es-checked-at]', row);
    if (now && checked) {
      now.addEventListener('click', function () {
        var s = new Date().toLocaleString('sv-SE', { timeZone: 'Europe/Moscow' });
        checked.value = s.slice(0, 16).replace(' ', 'T');
        checked.dispatchEvent(new Event('input', { bubbles: true }));
      });
    }
  }
  $$('[data-es-repeat]').forEach(function (repeat) {
    $$('[data-es-row]', $('[data-es-items]', repeat)).forEach(bindRow);
    var add = $('[data-es-add-row]', repeat);
    if (add) { add.addEventListener('click', function () { addRow(repeat); }); }
  });

  /* Claims pick sources by checkbox; quotes by select. Both follow the source list live. */
  function sourceIds() {
    var repeat = $('[data-es-repeat="sources"]');
    if (!repeat) { return []; }
    return $$('[data-es-items] [data-es-source-id]', repeat).map(function (i) { return i.value.trim(); }).filter(Boolean);
  }
  function syncSources() {
    var ids = sourceIds();
    $$('[data-es-items] [data-es-claim-sources]').forEach(function (box) {
      var name = box.getAttribute('data-name');
      var selected = $$('input:checked', box).map(function (i) { return i.value; });
      box.innerHTML = '';
      ids.forEach(function (id) {
        var label = document.createElement('label');
        label.className = 'es-chip-check';
        var input = document.createElement('input');
        input.type = 'checkbox'; input.name = name; input.value = id; input.checked = selected.indexOf(id) !== -1;
        var span = document.createElement('span'); span.textContent = id;
        label.appendChild(input); label.appendChild(span); box.appendChild(label);
      });
    });
    $$('[data-es-items] [data-es-source-select]').forEach(function (select) {
      var current = select.value;
      select.innerHTML = '';
      ids.forEach(function (id) { var o = document.createElement('option'); o.value = id; o.textContent = id; o.selected = id === current; select.appendChild(o); });
    });
  }

  /* -------------------------------------------------------- social preview */
  var socialToggle = $('[data-es-social-toggle]');
  var socialFields = $('[data-es-social-fields]');
  if (socialToggle && socialFields) {
    var applySocial = function () { socialFields.classList.toggle('is-off', !socialToggle.checked); };
    socialToggle.addEventListener('change', applySocial);
    applySocial();
  }
  var cardText = $('[data-es-card-text]');
  var cardPreview = $('[data-es-card-preview]');
  if (cardText && cardPreview) { cardText.addEventListener('input', function () { cardPreview.textContent = cardText.value; }); }
  var rubric = $('[data-es-card-rubric]');
  if (rubric) {
    var updateRubric = function () {
      var region = $('input[name="article[region]"]:checked');
      var category = $('select[name="article[category]"]');
      var r = region ? region.nextElementSibling.textContent : '';
      var c = category ? category.options[category.selectedIndex].textContent : '';
      rubric.textContent = r + ' · ' + c;
    };
    $$('[data-es-rubric-part]').forEach(function (el) { el.addEventListener('change', updateRubric); });
  }

  /* -------------------------------------------------------- media pickers */
  function picker(title, onPick) {
    if (!window.wp || !window.wp.media) { window.alert('Медиатека недоступна на этой странице.'); return; }
    var frame = window.wp.media({ title: title, library: { type: 'image' }, multiple: false, button: { text: 'Выбрать' } });
    frame.on('select', function () { onPick(frame.state().get('selection').first().toJSON()); });
    frame.open();
  }
  var mediaPick = $('[data-es-media-pick]');
  if (mediaPick) {
    mediaPick.addEventListener('click', function () {
      picker('Фото к материалу', function (img) {
        var input = $('[data-es-media-url]');
        input.value = img.url;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        var preview = $('[data-es-media-preview]');
        preview.innerHTML = '';
        var el = document.createElement('img'); el.src = img.url; el.alt = '';
        preview.appendChild(el);
        var credit = $('input[name="article[media_rights][credit]"]');
        if (credit && !credit.value && img.caption) { credit.value = img.caption.replace(/<[^>]+>/g, ''); }
      });
    });
  }
  // Issue cover: show only the fields of the chosen kind; the picker fills id, alt and credit.
  var cover = $('[data-es-cover]');
  if (cover) {
    var syncCover = function () {
      var checked = $('input[name="cover_kind"]:checked', cover);
      var kind = checked ? checked.value : 'lead';
      $$('[data-es-cover-for]', cover).forEach(function (el) { el.hidden = el.getAttribute('data-es-cover-for') !== kind; });
    };
    $$('input[name="cover_kind"]', cover).forEach(function (r) { r.addEventListener('change', syncCover); });
    syncCover();
    var coverPick = $('[data-es-cover-pick]', cover);
    if (coverPick) {
      coverPick.addEventListener('click', function () {
        picker('Обложка номера', function (img) {
          var id = $('[data-es-cover-id]', cover);
          id.value = img.id;
          id.dispatchEvent(new Event('input', { bubbles: true }));
          var thumb = $('[data-es-cover-thumb]', cover);
          thumb.innerHTML = '';
          var el = document.createElement('img');
          el.src = (img.sizes && img.sizes.medium && img.sizes.medium.url) || img.url; el.alt = '';
          thumb.appendChild(el);
          var alt = $('[data-es-cover-alt]', cover);
          if (alt && !alt.value && img.alt) { alt.value = img.alt; }
          var credit = $('[data-es-cover-credit]', cover);
          if (credit && !credit.value && img.caption) { credit.value = img.caption.replace(/<[^>]+>/g, ''); }
        });
      });
    }
  }

  // Appearance: previews of the three reading modes load on demand; the dark switch reloads them.
  var previews = $('[data-es-design-previews]');
  if (previews) {
    var dark = $('[data-es-preview-dark]', previews);
    var loadPreviews = function () {
      $$('iframe[data-es-preview-src]', previews).forEach(function (frame) {
        var src = frame.getAttribute('data-es-preview-src').replace(/([?&]theme=)(light|dark)/, '$1' + (dark && dark.checked ? 'dark' : 'light'));
        if (frame.getAttribute('src') !== src) { frame.setAttribute('src', src); }
      });
    };
    previews.addEventListener('toggle', function () { if (previews.open) { loadPreviews(); } });
    if (dark) { dark.addEventListener('change', loadPreviews); }
  }

  var logoPick = $('[data-es-logo-pick]');
  if (logoPick) {
    logoPick.addEventListener('click', function () {
      picker('Логотип газеты', function (img) {
        var input = $('[data-es-logo-id]');
        var thumb = $('[data-es-logo-thumb]');
        var clear = $('[data-es-logo-clear]');
        input.value = img.id;
        if (thumb) {
          var src = (img.sizes && img.sizes.medium && img.sizes.medium.url) || img.url;
          thumb.textContent = '';
          var el = document.createElement('img');
          el.src = src; el.alt = '';
          thumb.appendChild(el);
        }
        if (clear) { clear.hidden = false; }
        input.closest('form').dispatchEvent(new Event('es:dirty'));
      });
    });
  }
  var logoClear = $('[data-es-logo-clear]');
  if (logoClear) {
    logoClear.addEventListener('click', function () {
      var input = $('[data-es-logo-id]');
      var thumb = $('[data-es-logo-thumb]');
      input.value = '0';
      if (thumb) { thumb.innerHTML = '<span class="es-muted">Не выбран — в шапке набранное название</span>'; }
      logoClear.hidden = true;
      input.closest('form').dispatchEvent(new Event('es:dirty'));
    });
  }

  /* -------------------------------------------------------- reorder stories */
  var sortable = $('[data-es-sortable]');
  if (sortable) {
    var order = $('[data-es-order]');
    var renumber = function () {
      var items = $$('.es-story', sortable);
      items.forEach(function (li, i) { var pos = $('.es-story__pos', li); if (pos) { pos.textContent = (i < 9 ? '0' : '') + (i + 1); } });
      order.value = items.map(function (li) { return li.getAttribute('data-id'); }).join(',');
      sortable.closest('form').dispatchEvent(new Event('es:dirty'));
    };
    var dragged = null;
    sortable.addEventListener('dragstart', function (e) {
      dragged = e.target.closest('.es-story');
      if (!dragged) { return; }
      dragged.classList.add('is-dragging');
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', dragged.getAttribute('data-id')); } catch (err) { /* old browsers */ }
    });
    sortable.addEventListener('dragover', function (e) {
      var over = e.target.closest('.es-story');
      if (!dragged || !over || over === dragged) { return; }
      e.preventDefault();
      $$('.es-story', sortable).forEach(function (li) { li.classList.toggle('is-drop-target', li === over); });
      var rect = over.getBoundingClientRect();
      sortable.insertBefore(dragged, e.clientY > rect.top + rect.height / 2 ? over.nextSibling : over);
    });
    sortable.addEventListener('dragend', function () {
      if (dragged) { dragged.classList.remove('is-dragging'); }
      $$('.es-story', sortable).forEach(function (li) { li.classList.remove('is-drop-target'); });
      dragged = null;
      renumber();
    });
    sortable.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-es-move]');
      if (!btn) { return; }
      var li = btn.closest('.es-story');
      var dir = parseInt(btn.getAttribute('data-es-move'), 10);
      if (dir < 0 && li.previousElementSibling) { sortable.insertBefore(li, li.previousElementSibling); }
      if (dir > 0 && li.nextElementSibling) { sortable.insertBefore(li.nextElementSibling, li); }
      renumber();
      btn.focus();
    });
  }

  /* -------------------------------------------------------- local draft copy (article editor) */
  var editor = $('form[data-es-draft-key]');
  if (editor) {
    var key = editor.getAttribute('data-es-draft-key');
    var tinymceBody = function () { return window.tinymce && window.tinymce.get ? window.tinymce.get('es_article_body') : null; };
    var snapshot = function () {
      var mce = tinymceBody();
      if (mce && !mce.isHidden()) { mce.save(); }
      var data = {};
      new FormData(editor).forEach(function (value, name) {
        if (name === '_wpnonce' || name === '_wp_http_referer' || name === 'version' || name === 'intent') { return; }
        (data[name] = data[name] || []).push(String(value));
      });
      return { at: Date.now(), data: data };
    };
    var save = function () { store.set(key, JSON.stringify(snapshot())); };
    var timer = null;
    editor.addEventListener('input', function () { window.clearTimeout(timer); timer = window.setTimeout(save, 700); });
    editor.addEventListener('change', save);
    editor.addEventListener('submit', save);
    window.setInterval(function () { if (editor.classList.contains('is-dirty')) { save(); } }, 5000);

    var ensureRows = function (data) {
      ['sources', 'claims', 'quotes'].forEach(function (kind) {
        var repeat = $('[data-es-repeat="' + kind + '"]');
        if (!repeat) { return; }
        var wanted = {};
        Object.keys(data).forEach(function (name) {
          var m = name.match(new RegExp('^article\\[' + kind + '\\]\\[(\\d+)\\]'));
          if (m) { wanted[m[1]] = true; }
        });
        var items = $('[data-es-items]', repeat);
        var have = {};
        $$('[name]', items).forEach(function (el) {
          var m = el.name.match(new RegExp('^article\\[' + kind + '\\]\\[(\\d+)\\]'));
          if (m) { have[m[1]] = true; }
        });
        Object.keys(have).forEach(function (index) {
          if (!wanted[index]) {
            var any = $('[name^="article[' + kind + '][' + index + ']"]', items);
            var row = any ? any.closest('[data-es-row]') : null;
            if (row) { row.remove(); }
          }
        });
        Object.keys(wanted).forEach(function (index) {
          if (have[index]) { return; }
          var tpl = $('template[data-es-template]', repeat);
          var holder = document.createElement('div');
          holder.innerHTML = tpl.innerHTML.replace(/__INDEX__/g, index).trim();
          var row = holder.firstElementChild;
          items.appendChild(row);
          bindRow(row);
        });
      });
      syncSources();
    };
    var restore = function (saved) {
      ensureRows(saved.data);
      Object.keys(saved.data).forEach(function (name) {
        var values = saved.data[name];
        var fields = $$('[name="' + name.replace(/"/g, '\\"') + '"]', editor);
        fields.forEach(function (field) {
          if (field.type === 'checkbox' || field.type === 'radio') {
            field.checked = values.indexOf(field.value) !== -1;
          } else if (field.type !== 'file' && field.type !== 'hidden') {
            field.value = values[0];
            if (field.tagName === 'TEXTAREA') { grow(field); }
          }
        });
        if (name === 'body') {
          var mce = tinymceBody();
          if (mce) { mce.setContent(values[0]); }
        }
      });
      $$('[data-es-count]').forEach(function (f) { f.dispatchEvent(new Event('input')); });
      editor.dispatchEvent(new Event('es:dirty'));
    };
    var raw = store.get(key);
    var saved = null;
    try { saved = raw ? JSON.parse(raw) : null; } catch (e) { saved = null; }
    if ($('[data-es-clear-draft]')) {
      store.del(key);
      // A brand-new article is saved under its own id; its "new" copy is no longer needed either.
      store.del(key.split('-').slice(0, 3).join('-') + '-new');
    } else if (saved && params.get('es_error') === '1') {
      // TinyMCE initialises after DOMContentLoaded; give it a moment.
      window.setTimeout(function () {
        restore(saved);
        var hint = $('[data-es-restore-hint]');
        if (hint) { hint.hidden = false; }
      }, 600);
    } else if (saved && Date.now() - saved.at < 7 * 24 * 3600 * 1000) {
      var bar = document.createElement('div');
      bar.className = 'es-alert es-alert--warn';
      var when = new Date(saved.at).toLocaleString('ru-RU', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });
      bar.innerHTML = '<strong>Есть несохранённая копия этого материала</strong> от ' + when + '. ';
      var yes = document.createElement('button'); yes.type = 'button'; yes.className = 'es-btn es-btn--small'; yes.textContent = 'Восстановить';
      var no = document.createElement('button'); no.type = 'button'; no.className = 'es-link'; no.textContent = 'Удалить копию';
      bar.appendChild(yes); bar.appendChild(document.createTextNode(' ')); bar.appendChild(no);
      editor.parentNode.insertBefore(bar, editor);
      yes.addEventListener('click', function () { restore(saved); bar.remove(); });
      no.addEventListener('click', function () { store.del(key); bar.remove(); });
    }

    document.addEventListener('keydown', function (e) {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        var btn = $('button[name="intent"][value="save"]', editor);
        if (btn) { btn.click(); }
      }
    });
  }

  /* -------------------------------------------------------- JSON helpers (import) */
  $$('form[data-es-json-form]').forEach(function (form) {
    var area = $('[data-es-json]', form);
    var status = $('[data-es-json-status]', form);
    var file = $('[data-es-file]', form);
    var drop = $('[data-es-drop]', form);
    var expect = area ? area.getAttribute('data-es-expect-date') : '';
    var check = function () {
      if (!area || !status) { return; }
      status.className = 'es-json-status';
      var text = area.value.trim();
      if (!text) { status.textContent = ''; return; }
      try {
        var doc = JSON.parse(text);
        var n = Array.isArray(doc.articles) ? doc.articles.length : 0;
        var msg = 'JSON читается: выпуск ' + (doc.edition_date || '—') + ', ' + n + ' ' + plural(n, 'материал', 'материала', 'материалов') + ', схема ' + (doc.schema_version || '?') + '.';
        if (expect && doc.edition_date !== expect) {
          status.classList.add('is-bad');
          status.textContent = msg + ' Дата не совпадает с черновиком (' + expect + ').';
          return;
        }
        status.classList.add('is-ok');
        status.textContent = msg + ' Полная проверка — на сервере.';
      } catch (err) {
        status.classList.add('is-bad');
        status.textContent = 'Это не JSON: ' + err.message;
      }
    };
    if (area) { area.addEventListener('input', check); }
    var load = function (f) {
      if (!f) { return; }
      if (f.size > 262144) { status.className = 'es-json-status is-bad'; status.textContent = 'Файл больше 256 КиБ.'; return; }
      var reader = new FileReader();
      reader.onload = function () { area.value = String(reader.result); check(); form.dispatchEvent(new Event('es:dirty')); };
      reader.readAsText(f);
    };
    if (file) { file.addEventListener('change', function () { load(file.files[0]); }); }
    if (drop) {
      ['dragenter', 'dragover'].forEach(function (t) { drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
      ['dragleave', 'drop'].forEach(function (t) { drop.addEventListener(t, function () { drop.classList.remove('is-over'); }); });
      drop.addEventListener('drop', function (e) { e.preventDefault(); load(e.dataTransfer.files[0]); });
    }
  });

  /* -------------------------------------------------------- appearance preview */
  var mast = $('[data-es-mast-preview]');
  if (mast) {
    $$('[data-es-preview]').forEach(function (input) {
      var key = input.getAttribute('data-es-preview');
      var apply = function () {
        if (input.type === 'radio' && !input.checked) { return; }
        var value = input.value;
        if (key === 'name') {
          var parts = value.trim().split(/\s+/);
          $('[data-es-p-main]', mast).textContent = parts.pop() || '';
          $('[data-es-p-over]', mast).textContent = parts.join(' ');
        } else if (key === 'subtitle') { $('[data-es-p-subtitle]', mast).textContent = value; }
        else if (key === 'motto') { $('[data-es-p-motto]', mast).textContent = value; }
        else if (key === 'typeface') { mast.classList.remove('is-georgia', 'is-times'); if (value !== 'lora') { mast.classList.add('is-' + value); } }
        else if (key === 'background') { mast.style.setProperty('--p-bg', value); }
        else if (key === 'paper') { mast.style.setProperty('--p-paper', value); }
        else if (key === 'ink') { mast.style.setProperty('--p-ink', value); }
        else if (key === 'accent') { mast.style.setProperty('--p-accent', value); }
        var code = input.parentNode.querySelector('code');
        if (code && input.type === 'color') { code.textContent = value; }
      };
      input.addEventListener('input', apply);
      input.addEventListener('change', apply);
    });
  }

  /* -------------------------------------------------------- sources filter */
  var filterbar = $('[data-es-filterbar]');
  if (filterbar) {
    var q = $('[data-es-filter-input]', filterbar);
    var run = function () {
      var term = q.value.trim().toLowerCase();
      var allowed = $$('[data-es-filter-priority]:checked', filterbar).map(function (c) { return c.value; });
      $$('[data-es-source]').forEach(function (card) {
        var ok = allowed.indexOf(card.getAttribute('data-priority')) !== -1 && (!term || card.textContent.toLowerCase().indexOf(term) !== -1);
        card.hidden = !ok;
      });
    };
    filterbar.addEventListener('input', run);
    filterbar.addEventListener('change', run);
  }

  /* -------------------------------------------------------- copy helpers */
  $$('[data-es-copy]').forEach(function (el) {
    el.style.cursor = 'copy';
    el.title = 'Нажмите, чтобы скопировать';
    el.addEventListener('click', function () {
      if (navigator.clipboard) { navigator.clipboard.writeText(el.textContent.trim()).then(function () { el.classList.add('is-copied'); window.setTimeout(function () { el.classList.remove('is-copied'); }, 1200); }); }
    });
  });
  $$('textarea[data-es-select-all]').forEach(function (t) { t.addEventListener('focus', function () { t.select(); }); });
})();
