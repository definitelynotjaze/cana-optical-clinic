;(function () {
  'use strict'

  // Per-tbody state: { key, type, dir, context }
  var _sort = {}

  // ── Inject CSS once ───────────────────────────────────────────────
  var _styled = false
  function ensureCSS() {
    if (_styled) return
    _styled = true
    var s = document.createElement('style')
    s.textContent =
      '.th-sortable{cursor:pointer;user-select:none;white-space:nowrap;transition:color .15s}' +
      '.th-sortable:hover{color:#E8760A}' +
      // Arrow is always orange — full opacity when active, dimmed when idle
      '.th-sortable .sort-icon{display:inline-flex;margin-left:4px;color:#E8760A;opacity:.35;vertical-align:-2px;transition:transform .2s ease,opacity .15s ease}' +
      '.th-sortable.th-sort-asc .sort-icon,.th-sortable.th-sort-desc .sort-icon{opacity:1}' +
      '.th-sortable.th-sort-desc .sort-icon{transform:rotate(180deg)}' +
      // Mobile "Sort by" bar — on mobile the table collapses into cards and
      // its header row (the desktop sort controls) is hidden (global.css,
      // max-width:767px), so this bar takes over. Hidden on desktop.
      '.tbl-sort-bar{display:none}' +
      '@media print{.tbl-sort-bar{display:none!important}}' +
      '@media (max-width:767px){' +
        '.tbl-sort-bar{display:flex;align-items:center;gap:8px;margin:0 0 10px;font-family:Poppins,sans-serif}' +
        '.tbl-sort-bar-label{font-size:.75rem;font-weight:600;line-height:1;color:#6B7280;white-space:nowrap;flex-shrink:0}' +
        '.tbl-sort-bar .cust-select{flex:1;min-width:0;display:flex}' +
        // Dropdown + direction button share one exact box (height, padding,
        // border, font, line-height) so they sit perfectly level — the
        // dropdown otherwise inherits .form-input's taller padding/font.
        '.tbl-sort-bar .cust-select-trigger,.tbl-sort-dir{height:36px;box-sizing:border-box;padding:0 12px;border:1.5px solid #e5e7eb;' +
          'border-radius:8px;background:#fff;font-family:Poppins,sans-serif;font-size:.8rem;line-height:1;color:#1C1C1C}' +
        '.tbl-sort-bar .cust-select-trigger{width:100%;font-weight:500}' +
        '.tbl-sort-dir{display:inline-flex;align-items:center;justify-content:center;gap:6px;flex-shrink:0;font-weight:500;' +
          'white-space:nowrap;cursor:pointer;transition:border-color .15s ease,color .15s ease}' +
        '.tbl-sort-dir:hover{border-color:#E8760A;color:#E8760A}' +
        '.tbl-sort-dir:active{transform:scale(.97)}' +
        '.tbl-sort-dir:focus-visible{outline:2px solid #E8760A;outline-offset:2px}' +
        '.tbl-sort-dir svg{color:#E8760A;transition:transform .2s ease}' +
        '.tbl-sort-dir.is-desc svg{transform:rotate(180deg)}' +
      '}'
    document.head.appendChild(s)
  }

  var ICON = '<svg class="sort-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="11" height="11"><polyline points="6 15 12 9 18 15"/></svg>'

  // Sets or clears the active sort visual on a <th>: class + ASC/DESC label + tooltip.
  function _setThVisual(th, dir, type) {
    th.classList.remove('th-sort-asc', 'th-sort-desc')
    if (dir === 1 || dir === -1) {
      th.classList.add(dir === 1 ? 'th-sort-asc' : 'th-sort-desc')
      th.title = _nextTip(type || 'text', dir)
    } else {
      th.title = _nextTip(type || 'text', 0)
    }
  }

  // ── Public: init ──────────────────────────────────────────────────
  // Call from afterRender, after the table is in the DOM. Wires up any
  // <th data-sort-key="..." data-sort-type="text|date|number"> headers
  // found in the same table as the given tbody, plus the mobile Sort-by bar.
  // defaultSort: optional { key, type, dir, context }. Applied when no prior sort exists for
  // this tbody, OR when `context` is given and differs from the last call's context — this lets
  // pages that reuse one tbody id across multiple tabs/filters (e.g. patient-appts: All/Today/
  // Approved/Pending/...) each keep their own default instead of inheriting whichever tab was
  // visited first. Omit `context` for single-view tables where the cached sort should just persist
  // across re-renders (e.g. after the table's data refreshes).
  function initSortable(tbodyId, defaultSort) {
    ensureCSS()
    var tbody = document.getElementById(tbodyId)
    if (!tbody) return
    var table = tbody.closest('table')
    if (!table) return

    var st = _sort[tbodyId]
    var contextChanged = !!(defaultSort && st && defaultSort.context !== st.context)
    if (!st || contextChanged) {
      // Use the explicit default if given, otherwise fall back to ascending on the first sortable column
      var auto = defaultSort
      if (!auto) {
        var firstTh = table.querySelector('th[data-sort-key]')
        if (firstTh) auto = { key: firstTh.getAttribute('data-sort-key'), type: firstTh.getAttribute('data-sort-type') || 'text', dir: 1 }
      }
      if (auto) st = _sort[tbodyId] = auto
    }

    table.querySelectorAll('th[data-sort-key]').forEach(function (th) {
      th.classList.add('th-sortable')
      if (!th.querySelector('.sort-icon')) th.insertAdjacentHTML('beforeend', ICON)
      th.onclick = function () { _toggleSort(tbodyId, th) }
      var thKey  = th.getAttribute('data-sort-key')
      var thType = th.getAttribute('data-sort-type') || 'text'
      _setThVisual(th, (st && st.key === thKey) ? st.dir : 0, thType)
    })

    _buildSortBar(tbodyId, table)

    // Re-apply a previously chosen sort (e.g. after the table re-rendered)
    if (st) _applySort(tbodyId, st.key, st.type, st.dir)
  }

  // Desktop: clicking a header — same column flips direction, a new column starts ascending.
  function _toggleSort(tbodyId, th) {
    var key  = th.getAttribute('data-sort-key')
    var type = th.getAttribute('data-sort-type') || 'text'
    var prev = _sort[tbodyId]
    var dir  = (prev && prev.key === key) ? -prev.dir : 1
    _setSort(tbodyId, key, type, dir)
  }

  // Single entry point for every sort change (header click or mobile bar),
  // so the headers' arrows and the mobile bar always agree.
  function _setSort(tbodyId, key, type, dir) {
    var tbody = document.getElementById(tbodyId)
    var table = tbody && tbody.closest('table')
    if (!table) return
    var prev = _sort[tbodyId]
    table.querySelectorAll('th[data-sort-key]').forEach(function (h) {
      _setThVisual(h, h.getAttribute('data-sort-key') === key ? dir : 0, h.getAttribute('data-sort-type') || 'text')
    })
    _sort[tbodyId] = { context: prev && prev.context, key: key, type: type, dir: dir }
    _syncSortBar(tbodyId)
    _applySort(tbodyId, key, type, dir)
  }

  // ── Mobile "Sort by" bar ──────────────────────────────────────────
  // Column dropdown (the same columns that are sortable on desktop, via the
  // app's own selectFieldHtml) + a direction toggle. Inserted just above the
  // table, inside its .tbl-scroll tray, so it sits on top of the card list.
  function _barId(tbodyId) { return 'sortbar-' + tbodyId }

  function _sortColumns(table) {
    return Array.from(table.querySelectorAll('th[data-sort-key]')).map(function (th) {
      var clone = th.cloneNode(true)
      clone.querySelectorAll('svg').forEach(function (n) { n.remove() })
      return { key: th.getAttribute('data-sort-key'), type: th.getAttribute('data-sort-type') || 'text', label: clone.textContent.trim() }
    })
  }

  function _dirLabel(type, dir) {
    if (type === 'date')   return dir === 1 ? 'Oldest first' : 'Newest first'
    if (type === 'number') return dir === 1 ? 'Lowest first' : 'Highest first'
    return dir === 1 ? 'A → Z' : 'Z → A'
  }

  function _buildSortBar(tbodyId, table) {
    var cols = _sortColumns(table)
    var id   = _barId(tbodyId)
    var existing = document.getElementById(id)
    if (existing) existing.remove()
    if (!cols.length || typeof window.selectFieldHtml !== 'function') return

    var st  = _sort[tbodyId] || { key: cols[0].key, dir: 1 }
    var bar = document.createElement('div')
    bar.className = 'tbl-sort-bar'
    bar.id = id
    bar.innerHTML =
      '<span class="tbl-sort-bar-label">Sort by</span>' +
      window.selectFieldHtml(id + '-key', {
        value: st.key,
        options: cols.map(function (c) { return { value: c.key, label: c.label } }),
        onchange: "window._sortBarPick('" + tbodyId + "')",
        minWidth: 140
      }) +
      '<button type="button" class="tbl-sort-dir" id="' + id + '-dir" onclick="window._sortBarFlip(\'' + tbodyId + '\')">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="12" height="12"><polyline points="6 15 12 9 18 15"/></svg>' +
        '<span></span>' +
      '</button>'
    table.parentNode.insertBefore(bar, table)
    _syncSortBar(tbodyId)
  }

  // Reflects the current sort onto the bar (column shown + direction label).
  function _syncSortBar(tbodyId) {
    var id = _barId(tbodyId), st = _sort[tbodyId]
    if (!st || !document.getElementById(id)) return
    var hidden = document.getElementById(id + '-key')
    if (hidden && hidden.value !== st.key && window.setSelectFieldValue) window.setSelectFieldValue(id + '-key', st.key)
    var btn = document.getElementById(id + '-dir')
    if (btn) {
      btn.classList.toggle('is-desc', st.dir === -1)
      btn.querySelector('span').textContent = _dirLabel(st.type || 'text', st.dir)
      btn.setAttribute('aria-label', 'Sort direction: ' + _dirLabel(st.type || 'text', st.dir) + '. Tap to reverse.')
    }
  }

  // Picking a column from the dropdown — a new column starts ascending,
  // same as clicking a new header on desktop.
  window._sortBarPick = function (tbodyId) {
    var hidden = document.getElementById(_barId(tbodyId) + '-key')
    var tbody  = document.getElementById(tbodyId)
    if (!hidden || !tbody) return
    var st = _sort[tbodyId]
    if (st && st.key === hidden.value) return // programmatic sync, not a real change
    var col = _sortColumns(tbody.closest('table')).filter(function (c) { return c.key === hidden.value })[0]
    if (col) _setSort(tbodyId, col.key, col.type, 1)
  }

  // The direction button — reverses the current column's order.
  window._sortBarFlip = function (tbodyId) {
    var st = _sort[tbodyId]
    if (st) _setSort(tbodyId, st.key, st.type || 'text', -st.dir)
  }

  // Returns a tooltip showing the current sort state and what the next click will do.
  // dir=0 means unsorted (next click = ascending).
  function _nextTip(type, dir) {
    if (dir === 1) {
      return type === 'date'   ? 'Ascending · Click to sort newest first'
           : type === 'number' ? 'Ascending · Click to sort highest first'
           :                     'Ascending · Click to sort Z → A'
    }
    if (dir === -1) {
      return type === 'date'   ? 'Descending · Click to sort oldest first'
           : type === 'number' ? 'Descending · Click to sort lowest first'
           :                     'Descending · Click to sort A → Z'
    }
    // Unsorted
    return   type === 'date'   ? 'Click to sort oldest first'
           : type === 'number' ? 'Click to sort lowest first'
           :                     'Click to sort A → Z'
  }

  function _applySort(tbodyId, key, type, dir) {
    var tbody = document.getElementById(tbodyId)
    if (!tbody) return
    var rows = Array.from(tbody.querySelectorAll('tr[data-search]'))
    rows.sort(function (a, b) {
      var av = _val(a, key, type), bv = _val(b, key, type)
      if (av < bv) return -1 * dir
      if (av > bv) return 1 * dir
      return 0
    })
    rows.forEach(function (r) { tbody.appendChild(r) })

    // Let pagination recompute which rows show on the current page
    if (window.refreshPagination) window.refreshPagination(tbodyId)
  }

  function _val(row, key, type) {
    var raw = row.getAttribute('data-sort-' + key) || ''
    if (type === 'number') return parseFloat(raw) || 0
    if (type === 'date')   return raw ? (Date.parse(raw) || 0) : 0
    return raw.toLowerCase()
  }

  window.initSortable = initSortable
  // For an outside control (the record filter bar's Newest / Oldest first
  // dropdown) to sort a table with its header arrows kept in step.
  window.setTableSort = function (tbodyId, key, type, dir) { _setSort(tbodyId, key, type, dir) }
})()
