;(function () {
  'use strict'

  // Per-tbody state: { page, rpp }
  var _pg = {}

  // ── Inject CSS once ───────────────────────────────────────────────
  var _styled = false
  function ensureCSS() {
    if (_styled) return
    _styled = true
    var s = document.createElement('style')
    s.textContent =
      '.pg-hidden{display:none!important}' +
      '.pg-bar{display:flex;align-items:center;justify-content:space-between;' +
        'padding:14px 20px 16px;border-top:1px solid #f3f4f6;flex-wrap:wrap;gap:10px}' +
      '.pg-info{font-size:.8rem;color:#6b7280}' +
      '.pg-rpp{display:flex;align-items:center;gap:6px;font-size:.8rem;color:#6b7280}' +
      '.pg-btns{display:flex;align-items:center;gap:4px}' +
      '.pg-btn{width:32px;height:32px;border-radius:8px;border:1px solid #e5e7eb;' +
        'background:transparent;color:#374151;font-size:.82rem;font-weight:500;' +
        'font-family:\'Poppins\',sans-serif;cursor:pointer;' +
        'display:inline-flex;align-items:center;justify-content:center;' +
        'transition:background .12s,border-color .12s}' +
      '.pg-btn:hover:not([disabled]):not(.pg-btn-active){background:#f3f4f6;border-color:#d1d5db}' +
      '.pg-btn.pg-btn-active{background:#E8760A!important;color:#fff!important;' +
        'border-color:#E8760A!important;cursor:default}' +
      '.pg-btn[disabled]{opacity:.4;cursor:not-allowed;pointer-events:none}' +
      '.pg-ellipsis{width:32px;height:32px;display:inline-flex;align-items:center;' +
        'justify-content:center;color:#9ca3af;font-size:.82rem}' +
      // Both info groups used to be display:none here — Showing X–Y of Z and
      // the Rows per page selector simply weren't reachable on mobile at
      // all, not just visually secondary. Now they stack, centered, above
      // the page-number buttons instead. justify-content is forced with
      // !important because _render() below sets it inline per-call
      // ('center' or 'space-between' depending on page count) — inline
      // style always outranks a plain stylesheet rule, so without
      // !important this media query couldn't override it. Real centering
      // on mobile comes from align-items, since flex-direction is column
      // here — justify-content only controls the (irrelevant) vertical
      // spacing between the stacked groups once rotated.
      '@media(max-width:768px){' +
        '.pg-bar{flex-direction:column;align-items:center;text-align:center;justify-content:flex-start!important;gap:12px}' +
        '.pg-rpp{justify-content:center}' +
        '.pg-btn{width:28px;height:28px;font-size:.78rem}' +
      '}'
    document.head.appendChild(s)
  }

  // ── Public: init ──────────────────────────────────────────────────
  // Call from afterRender after the tbody is in the DOM.
  function initPagination(tbodyId, rpp) {
    ensureCSS()
    _pg[tbodyId] = { page: 1, rpp: rpp || 10 }
    _render(tbodyId)
  }

  // ── Public: refresh ───────────────────────────────────────────────
  // Re-paginate without resetting the current page.
  // Use after external filter changes that don't reset the page.
  function refreshPagination(tbodyId) {
    if (_pg[tbodyId]) _render(tbodyId)
  }

  // ── Public: snapshot / restore ────────────────────────────────────
  // Used by renderPage() (router.js) when the SAME page re-renders (after a
  // modal save, or the background poll): initPagination() starts every
  // table back on page 1 with the default rows-per-page, so the router
  // snapshots this state before re-rendering and puts it back afterwards.
  function pgSnapshot() {
    var snap = {}
    Object.keys(_pg).forEach(function (id) { snap[id] = { page: _pg[id].page, rpp: _pg[id].rpp } })
    return snap
  }
  function pgRestore(snap) {
    Object.keys(snap || {}).forEach(function (id) {
      if (!_pg[id] || !document.getElementById(id)) return
      _pg[id].page = snap[id].page
      _pg[id].rpp  = snap[id].rpp
      _render(id)   // clamps the page if rows were removed
    })
  }

  // ── Public: pgReset ───────────────────────────────────────────────
  // Reset to page 1 then re-paginate.
  // Call from search inputs and filter dropdowns.
  function pgReset(tbodyId) {
    if (!_pg[tbodyId]) return
    _pg[tbodyId].page = 1
    _render(tbodyId)
  }

  // ── Core render ───────────────────────────────────────────────────
  function _render(tbodyId) {
    var st = _pg[tbodyId]
    if (!st) return

    var tbody = document.getElementById(tbodyId)
    if (!tbody) return

    // Direct children with data-search: a table's rows, or the items of a
    // card list (Medical Record visits, Previous Clinic Records).
    var allRows = Array.from(tbody.querySelectorAll(':scope > [data-search]'))

    // Step 1: Remove pg-hidden so we can see the true filter state
    allRows.forEach(function (r) { r.classList.remove('pg-hidden') })

    // Step 2: Active rows = those not hidden by external filter (inline display)
    var active = allRows.filter(function (r) { return r.style.display !== 'none' })

    var total = active.length
    var pages = Math.max(1, Math.ceil(total / st.rpp))

    // Clamp current page
    if (st.page > pages) st.page = pages
    if (st.page < 1)     st.page = 1

    var start = (st.page - 1) * st.rpp
    var end   = start + st.rpp

    // Step 3: Hide rows outside current page using CSS class (not inline style)
    active.forEach(function (r, i) {
      if (i < start || i >= end) r.classList.add('pg-hidden')
    })

    // Step 4: Render the pagination bar below the table-wrap
    _renderBar(tbodyId, total, pages, st.page, st.rpp)
  }

  // ── Bar ───────────────────────────────────────────────────────────
  function _renderBar(tbodyId, total, totalPages, cur, rpp) {
    var tbody = document.getElementById(tbodyId)
    if (!tbody) return

    // The bar goes at the end of the table card, or of whatever wrapper a
    // card list marks with data-pg-host.
    var wrap = tbody.closest('.table-wrap, [data-pg-host]')
    if (!wrap) return

    var barId = 'pg-bar-' + tbodyId
    var bar   = document.getElementById(barId)
    if (!bar) {
      bar = document.createElement('div')
      bar.id        = barId
      bar.className = 'pg-bar'
      wrap.appendChild(bar)
    }

    // Hide the whole bar only when there's nothing to show at all -- not
    // just when everything fits on one page. It used to hide any time
    // total <= rpp, which meant picking "50" (or having a small table)
    // hid the rows-per-page selector along with it -- the only way back to
    // a smaller page size, gone with no way to reach it short of leaving
    // and reopening the page. The Showing-X-of-Y text and Prev/Next/page-
    // number buttons are what's actually pointless with a single page --
    // those still collapse; the selector itself never does.
    if (total === 0) { bar.style.display = 'none'; return }
    bar.style.display = 'flex'

    var s = (cur - 1) * rpp + 1
    var e = Math.min(cur * rpp, total)
    var onePage = total <= rpp
    // With everything else collapsed, "Rows per page" is the bar's only
    // child — space-between (the multi-item layout) just parks a lone
    // child at the left edge instead of centering it.
    bar.style.justifyContent = onePage ? 'center' : 'space-between'

    // Reuses the app's shared custom-select component (main.js) instead of a
    // raw <select> \u2014 this only ever runs inside the authenticated app, which
    // always has it loaded, so every dropdown in the app (including this
    // one) renders with the same popover styling instead of the OS's own.
    bar.innerHTML =
      (onePage ? '' : ('<span class="pg-info">Showing ' + s + '\u2013' + e + ' of ' + total + ' records</span>')) +
      '<span class="pg-rpp">Rows per page:\u00a0' +
        window.selectFieldHtml('pg-rpp-' + tbodyId, {
          value: String(rpp),
          options: [5, 10, 20, 50].map(function (n) { return { value: String(n), label: String(n) } }),
          onchange: 'window._pgRpp(\'' + tbodyId + '\',+this.value)',
          // No min-width — the trigger is a flex row (text, gap, icon), not
          // an icon positioned over reserved padding, so it can just hug a
          // 1-2 digit number tightly. minWidth is the popover's own floor
          // (see _custPositionPopover) — small on purpose, so the dropdown
          // doesn't balloon out to the shared 160px default for a list of
          // four short numbers.
          style: 'width:auto;padding:5px 10px;font-size:.8rem',
          minWidth: 70
        }) +
      '</span>' +
      (onePage ? '' : ('<span class="pg-btns">' + _btns(tbodyId, cur, totalPages) + '</span>'))
  }

  // ── Page buttons with ellipsis ────────────────────────────────────
  function _btns(tbodyId, cur, total) {
    var out = ''

    // Prev
    out += '<button class="pg-btn"' +
      (cur === 1
        ? ' disabled'
        : ' onclick="window._pgGo(\'' + tbodyId + '\',' + (cur - 1) + ')"') +
      '>\u2039</button>'

    // Collect visible page numbers
    var show = {}
    show[1] = true; show[total] = true; show[cur] = true
    if (cur > 1) show[cur - 1] = true
    if (cur < total) show[cur + 1] = true
    var nums = Object.keys(show).map(Number).sort(function (a, b) { return a - b })

    var prev = 0
    nums.forEach(function (p) {
      if (p - prev > 1) out += '<span class="pg-ellipsis">\u2026</span>'
      var active = p === cur
      out += '<button class="pg-btn' + (active ? ' pg-btn-active' : '') + '"' +
        (active ? '' : ' onclick="window._pgGo(\'' + tbodyId + '\',' + p + ')"') +
        '>' + p + '</button>'
      prev = p
    })

    // Next
    out += '<button class="pg-btn"' +
      (cur === total
        ? ' disabled'
        : ' onclick="window._pgGo(\'' + tbodyId + '\',' + (cur + 1) + ')"') +
      '>\u203a</button>'

    return out
  }

  // ── Exposed globals ───────────────────────────────────────────────
  window.initPagination    = initPagination
  window.refreshPagination = refreshPagination
  window.pgReset           = pgReset
  window.pgSnapshot        = pgSnapshot
  window.pgRestore         = pgRestore

  // Called by page buttons
  window._pgGo = function (tbodyId, page) {
    if (!_pg[tbodyId]) return
    _pg[tbodyId].page = page
    _render(tbodyId)
  }

  // Called by rows-per-page select
  window._pgRpp = function (tbodyId, rpp) {
    if (!_pg[tbodyId]) return
    _pg[tbodyId].rpp  = rpp
    _pg[tbodyId].page = 1
    _render(tbodyId)
  }

})()
