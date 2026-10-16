// ================================================================
//  CANAOPTICALCLINIC — public-nav.js
//  Shared across the public pages (index.html, pages/*.html).
//  If the visitor has an active session, swaps the navbar's "Login"
//  link for a profile dropdown (name/avatar + Dashboard + Sign Out),
//  does the same for the mobile collapsed menu, and pre-fills the
//  contact form with their account name/email if present on the page.
//  Also syncs clinic branding (logo, favicon, and on the Contact page,
//  the address/phone/email/hours) from the admin's Clinic Information
//  settings, so a change there reflects everywhere without code edits.
// ================================================================
;(function () {
  var base = window.location.pathname.indexOf('/pages/') !== -1 ? '../' : ''

  // ── Mobile nav toggle (replaces Bootstrap collapse to avoid inline-height interference) ──
  var navToggle = document.querySelector('.nav-toggle')
  var navMenu   = document.getElementById('navMenu')
  if (navToggle && navMenu) {
    // Mobile side drawer (see navbar.css): a header (logo, name, close),
    // an icon per link, and a dimmed backdrop — all hidden on desktop.
    var ICONS = {
      'index.html':    '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
      'services.html': '<circle cx="12" cy="12" r="3"/><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>',
      'doctors.html':  '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
      'contact.html':  '<path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><polyline points="22,6 12,13 2,6"/>',
      'app.html':      '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>',
      'book':          '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>'
    }
    navMenu.querySelectorAll('.nav-links > li > a.nav-link').forEach(function (a) {
      var href = a.getAttribute('href') || ''
      var key = href.indexOf('#book') !== -1 ? 'book' : href.split('#')[0].split('/').pop()
      if (!ICONS[key] || a.querySelector('.nav-ico')) return
      a.insertAdjacentHTML('afterbegin', '<span class="nav-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + ICONS[key] + '</svg></span>')
    })

    var logo = document.getElementById('site-logo-img')
    var head = document.createElement('div')
    head.className = 'nav-drawer-head'
    head.innerHTML =
      '<img src="' + (logo ? logo.getAttribute('src') : base + 'assets/images/logo/clinic-logo.png') + '" alt="" class="nav-drawer-logo">' +
      '<span class="nav-drawer-name">Cana Optical Clinic</span>' +
      '<button type="button" class="nav-drawer-close" aria-label="Close menu">' +
        '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
      '</button>'
    navMenu.insertBefore(head, navMenu.firstChild)

    var backdrop = document.createElement('div')
    backdrop.className = 'nav-backdrop'
    navMenu.parentNode.insertBefore(backdrop, navMenu)

    function setDrawer(open) {
      navMenu.classList.toggle('show', open)
      document.body.classList.toggle('nav-drawer-open', open)
      navToggle.setAttribute('aria-expanded', open ? 'true' : 'false')
      // Lock the page behind the drawer so it doesn't scroll along
      document.documentElement.style.overflow = open ? 'hidden' : ''
      if (open) setTimeout(function () { var c = head.querySelector('.nav-drawer-close'); c && c.focus() }, 50)
    }
    navToggle.addEventListener('click', function () { setDrawer(!navMenu.classList.contains('show')) })
    head.querySelector('.nav-drawer-close').addEventListener('click', function () { setDrawer(false); navToggle.focus() })
    backdrop.addEventListener('click', function () { setDrawer(false) })
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && navMenu.classList.contains('show')) { setDrawer(false); navToggle.focus() }
    })
    // Following a link (incl. same-page anchors) closes the drawer
    navMenu.addEventListener('click', function (e) {
      if (e.target.closest('a.nav-link')) setDrawer(false)
    })
    // Rotating/resizing up to desktop width shouldn't leave the page locked
    window.matchMedia('(min-width: 992px)').addEventListener('change', function (mq) {
      if (mq.matches) setDrawer(false)
    })
  }

  function syncSessionUI() {
    fetch(base + 'api/auth/me.php')
      .then(function (r) { return r.json() })
      .then(function (d) {
        if (!d || !d.success || !d.user) return
        applyProfileUI(d.user, base, d.role)
        prefillContactForm(d.user)
        // Hide the hero "Register" button — logged-in users already have an account
        document.querySelectorAll('a[href*="#register"]').forEach(function (el) {
          el.style.display = 'none'
        })
      })
      .catch(function () { /* not logged in, or PHP unavailable — leave the default Login UI */ })
  }
  syncSessionUI()

  // Re-check on bfcache restore — most mobile browsers (iOS Safari/Chrome
  // especially) restore a page navigated to via Back instantly from the
  // back-forward cache, replaying the DOM exactly as it was frozen at
  // navigation-away time WITHOUT re-running this script at all. If that
  // snapshot was taken before the fetch above had resolved (e.g. the visitor
  // logged in on app.html, then backed onto this page, whose own earlier
  // load never got to swap the navbar before it was frozen), the restored
  // page shows the stale "Login" link even though the session is still
  // active — previously nothing re-ran until a manual refresh. `persisted`
  // is true only for a bfcache restore, not a normal fresh load (where this
  // event also fires, redundantly, right after the top-level call above).
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) syncSessionUI()
  })

  // Pre-sync from localStorage so name shows immediately before the API responds
  try {
    var _pn = localStorage.getItem('_canaopticalclinic_clinicName')
    var _pl = localStorage.getItem('_canaopticalclinic_logo_url')
    if (_pn) document.querySelectorAll('.nav-logo-name, .footer-logo-name').forEach(function (el) { el.textContent = _pn })
    if (_pl) {
      // _pl is stored root-relative (e.g. "assets/images/logo/x.png") by
      // whichever page last saved it (app.html/index.html, both at the
      // site root) — needs `base` prepended here or it 404s one level
      // down on /pages/*.html, resolving against the wrong directory.
      var _plUrl = base + _pl
      document.querySelectorAll('#site-logo-img, .footer-logo-img, .nav-drawer-logo').forEach(function (img) { img.src = _plUrl })
      document.querySelectorAll('#site-favicon').forEach(function (link) { link.href = _plUrl })
    }
  } catch (e) {}

  fetch(base + 'api/clinic/public.php')
    .then(function (r) { return r.json() })
    .then(function (d) {
      if (!d || !d.success || !d.clinic) return
      applyClinicBranding(d.clinic, base)
    })
    .catch(function () { /* PHP unavailable — leave the static defaults in the HTML */ })

  function initials(name) {
    return (name || '').trim().split(/\s+/).map(function (p) { return p[0] }).slice(0, 2).join('').toUpperCase()
  }

  function applyProfileUI(user, base, role) {
    var dashboardHref = base + 'app.html'
    var logoutHref    = base + 'api/auth/logout.php'
    var indexHref     = base + 'index.html'

    // Admin, staff, and doctors don't need booking buttons — hide them all.
    // Whole CTA sections (not just their button) are hidden for the two that
    // lead with booking-specific copy — "Ready to take care of your vision?"
    // (services.html) and "Ready to meet your doctor? Book an appointment…"
    // (doctors.html) — otherwise that text is left dangling with no button.
    if (role === 'admin' || role === 'staff' || role === 'doctor') {
      var toHide = [
        'a.nav-book',                      // desktop navbar "Book Now"
        'li.nav-link-book',                // mobile nav "Book Now" item
        '.services-cta',                   // Services page's whole CTA block
        '.doctors-cta',                    // Doctors page's whole CTA block
        'a.btn-solid[href*="app.html"]',   // hero "Book Appointment" button
      ]
      toHide.forEach(function (sel) {
        document.querySelectorAll(sel).forEach(function (el) {
          el.style.display = 'none'
        })
      })
      // Footer's Quick Links list is a flex column with its own `gap`
      // between items (.footer-links, public.css) — hiding just the <a>
      // still left its <li> sitting there as an empty flex item, which
      // still claimed its share of that gap and showed up as a blank space
      // at the bottom of the list. Hiding the whole <li> instead closes it.
      document.querySelectorAll('a.footer-booknow').forEach(function (el) {
        var li = el.closest('li')
        ;(li || el).style.display = 'none'
      })
    }

    // ── Desktop: replace the standalone "Login" link with a profile button ──
    document.querySelectorAll('.nav-actions > a.nav-login').forEach(function (loginLink) {
      var actions = loginLink.parentElement
      var wrap = document.createElement('div')
      wrap.className = 'nav-profile'

      var avatarInner = user.photoUrl
        ? '<img src="' + base + user.photoUrl + '" alt="" onerror="this.remove();this.parentElement&&(this.parentElement.textContent=' + "'" + initials(user.name).replace(/'/g, "\\'") + "'" + ')">'
        : initials(user.name)

      wrap.innerHTML =
        '<button type="button" class="nav-profile-btn" aria-haspopup="true" aria-expanded="false">' +
          '<span class="nav-profile-avatar">' + avatarInner + '</span>' +
          '<span class="nav-profile-name">' + esc(user.firstName || user.name || 'Account') + '</span>' +
          '<svg class="nav-profile-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="12" height="12"><polyline points="6 9 12 15 18 9"/></svg>' +
        '</button>' +
        '<div class="nav-profile-dropdown">' +
          '<div class="nav-profile-dropdown-head">' +
            '<div class="nav-profile-dropdown-name">' + esc(user.name || '') + '</div>' +
            '<div class="nav-profile-dropdown-email">' + esc(user.email || '') + '</div>' +
          '</div>' +
          '<div class="nav-profile-dropdown-divider"></div>' +
          '<a href="' + dashboardHref + '" class="nav-profile-dropdown-item">Dashboard</a>' +
          '<button type="button" class="nav-profile-dropdown-item nav-profile-logout">Sign Out</button>' +
        '</div>'

      actions.replaceChild(wrap, loginLink)

      var btn = wrap.querySelector('.nav-profile-btn')
      var dd  = wrap.querySelector('.nav-profile-dropdown')
      btn.addEventListener('click', function (e) {
        e.stopPropagation()
        var open = dd.classList.toggle('open')
        btn.setAttribute('aria-expanded', open ? 'true' : 'false')
      })
      document.addEventListener('click', function () {
        dd.classList.remove('open')
        btn.setAttribute('aria-expanded', 'false')
      })
      wrap.querySelector('.nav-profile-logout').addEventListener('click', function (e) {
        e.stopPropagation()
        signOut(logoutHref, indexHref)
      })
    })

    // ── Mobile: the collapsed menu's "Login" item becomes an account
    //    header (name, non-clickable) followed by explicit "Dashboard"
    //    and "Sign Out" items — mirrors the desktop dropdown structure
    //    so it's unambiguous these are actions, not just a label. ──
    document.querySelectorAll('li.nav-link-login').forEach(function (li) {
      if (li.parentElement.querySelector('.nav-link-account')) return // already added

      var avatarInner = user.photoUrl
        ? '<img src="' + base + user.photoUrl + '" alt="" onerror="this.remove();this.parentElement&&(this.parentElement.textContent=' + "'" + initials(user.name).replace(/'/g, "\\'") + "'" + ')">'
        : initials(user.name)

      li.className = 'nav-item nav-link-account'
      li.innerHTML =
        '<div class="nav-mobile-account">' +
          '<span class="nav-profile-avatar">' + avatarInner + '</span>' +
          '<span class="nav-mobile-account-name">' + esc(user.name || 'Account') + '</span>' +
        '</div>'

      var dashLi = document.createElement('li')
      dashLi.className = 'nav-item nav-link-dashboard'
      dashLi.innerHTML =
        '<a href="' + dashboardHref + '" class="nav-link">' +
          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>' +
          '<span>Dashboard</span>' +
        '</a>'
      li.insertAdjacentElement('afterend', dashLi)

      var signOutLi = document.createElement('li')
      signOutLi.className = 'nav-item nav-link-logout'
      signOutLi.innerHTML =
        '<a href="#" class="nav-link">' +
          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>' +
          '<span>Sign Out</span>' +
        '</a>'
      dashLi.insertAdjacentElement('afterend', signOutLi)
      signOutLi.querySelector('a').addEventListener('click', function (e) {
        e.preventDefault()
        signOut(logoutHref, indexHref)
      })
    })
  }

  function applyClinicBranding(clinic, base) {
    if (clinic.logoUrl) {
      document.querySelectorAll('#site-logo-img, .footer-logo-img, .nav-drawer-logo').forEach(function (img) { img.src = base + clinic.logoUrl })
      document.querySelectorAll('#site-favicon').forEach(function (link) { link.href = base + clinic.logoUrl })
    }

    var displayName = clinic.name
    if (displayName) {
      document.querySelectorAll('.nav-logo-name, .footer-logo-name').forEach(function (el) { el.textContent = displayName })
    }
    // A custom copyright line (Clinic Information > Footer Copyright Text)
    // wins if the admin set one; otherwise keep auto-generating it from the
    // clinic name + current year, same as before this field existed.
    var footerLine = document.querySelector('.footer-line')
    if (footerLine) {
      if (clinic.footerCopyrightText) footerLine.textContent = clinic.footerCopyrightText
      else if (displayName) footerLine.textContent = '© ' + new Date().getFullYear() + ' ' + displayName + '. All rights reserved.'
    }
    // Footer tagline (Clinic Information > Footer Tagline) — leaves the
    // static HTML description alone when nothing's been set yet.
    if (clinic.tagline) {
      document.querySelectorAll('.footer-desc').forEach(function (el) { el.textContent = clinic.tagline })
    }

    // Contact page info card (phone/email stay clickable there)
    var addressEl = document.getElementById('ci-pub-address')
    if (addressEl && clinic.address) addressEl.textContent = clinic.address

    var phoneEl = document.getElementById('ci-pub-phone')
    if (phoneEl && clinic.phone) {
      phoneEl.textContent = clinic.phone
      phoneEl.setAttribute('href', 'tel:' + clinic.phone.replace(/\D/g, ''))
    }

    var emailEl = document.getElementById('ci-pub-email')
    if (emailEl && clinic.email) {
      emailEl.textContent = clinic.email
      emailEl.setAttribute('href', 'mailto:' + clinic.email)
    }

    var hoursEl = document.getElementById('ci-pub-hours')
    if (hoursEl && clinic.hours) hoursEl.textContent = clinic.hours

    // Footer contact details (plain text, no links)
    var fAddr  = document.getElementById('footer-address')
    var fPhone = document.getElementById('footer-phone')
    var fEmail = document.getElementById('footer-email')
    var fHours = document.getElementById('footer-hours')
    if (fAddr  && clinic.address) fAddr.textContent  = clinic.address
    if (fPhone && clinic.phone)   fPhone.textContent = clinic.phone
    if (fEmail && clinic.email)   fEmail.textContent = clinic.email
    if (fHours && clinic.hours)   fHours.textContent = clinic.hours
  }

  // Mirrors logout() in auth.js (the sidebar's Sign Out, app.html) so
  // signing out from these public pages is exactly as reliable: the same
  // up-to-3-attempt retry (a single fire-and-forget request used to mean a
  // transient network blip left the session row never actually deleted
  // server-side, even though the page moved on as if it had signed out),
  // and clearing the SAME '_canaopticalclinic_hadSession' localStorage flag
  // (HAD_SESSION_KEY, auth.js) that flag must stay in sync with. Without
  // clearing it here, the next time app.html loads on this browser it'd
  // find that flag still set from the earlier login, find no valid
  // session (correctly, since this really did sign out), and wrongly show
  // "You were signed out because this session ended" — the message meant
  // for a session dying unexpectedly, not this deliberate one.
  function signOut(logoutHref, indexHref) {
    var attempt = 0
    function tryOnce() {
      attempt++
      return fetch(logoutHref, { method: 'POST' })
        .then(function (r) { return r.ok })
        .catch(function () { return false })
        .then(function (ok) {
          if (ok || attempt >= 3) return
          return new Promise(function (resolve) { setTimeout(resolve, 400 * attempt) }).then(tryOnce)
        })
    }
    tryOnce().then(function () {
      try { localStorage.removeItem('_canaopticalclinic_hadSession') } catch (e) {}
      window.location.href = indexHref
    })
  }

  function prefillContactForm(user) {
    var nameEl  = document.getElementById('cf-name')
    var emailEl = document.getElementById('cf-email')
    if (nameEl && !nameEl.value)   nameEl.value  = user.name  || ''
    if (emailEl && !emailEl.value) emailEl.value = user.email || ''
  }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    })
  }

  // ── Hide navbar when footer is visible, restore when footer leaves ──
  // On tall/large-screen viewports the whole page (hero → footer) can fit
  // without any scrolling at all — the footer is "visible" from the instant
  // the page loads, and stays that way forever since there's nothing to
  // scroll past. Folding the navbar in that case is simply wrong: there's
  // no long page underneath it to reclaim room from, so it should never
  // hide. The atTop check alone only catches this at the exact moment the
  // observer's callback happens to fire — if content loads in async after
  // (e.g. the clinic video section revealing itself) and changes the
  // page's total height, the fold state needs re-checking from scratch,
  // not just once at whatever instant IntersectionObserver first ran.
  var navbar = document.getElementById('navbar')
  var footer = document.getElementById('footer')
  if (navbar && footer) {
    var updateNavbarFold = function () {
      var wholePageFits = document.documentElement.scrollHeight <= window.innerHeight + 4
      var atTop         = window.scrollY < 24
      var footerRect    = footer.getBoundingClientRect()
      var footerVisible = footerRect.top < window.innerHeight && footerRect.bottom > 0
      navbar.classList.toggle('navbar-folded', footerVisible && !atTop && !wholePageFits)
    }
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(updateNavbarFold, { threshold: 0.05 }).observe(footer)
    }
    window.addEventListener('resize', updateNavbarFold)
    updateNavbarFold() // don't wait on the observer's first async callback

    // IntersectionObserver alone isn't enough: its callback is async and
    // threshold-batched, so on iOS/Safari in particular — which delays and
    // coalesces these callbacks further during inertial (momentum)
    // scrolling — the navbar's unfold visibly lags a beat behind the
    // actual scroll position when flinging back up away from the footer.
    // A scroll listener recalculates in real time instead; rAF-throttled
    // (rather than every 'scroll' event, which fires far too often on
    // touch devices) so it stays cheap while still tracking every frame.
    var foldRaf = null
    window.addEventListener('scroll', function () {
      if (foldRaf) return
      foldRaf = requestAnimationFrame(function () {
        foldRaf = null
        updateNavbarFold()
      })
    }, { passive: true })
  }

  // ── Scroll-to-top button ──────────────────────────────────────────
  var scrollBtn = document.createElement('button')
  scrollBtn.className = 'scroll-top-btn'   // public.css hides it while the menu drawer / photo viewer is open
  scrollBtn.setAttribute('aria-label', 'Back to top')
  scrollBtn.innerHTML =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><polyline points="18 15 12 9 6 15"/></svg>'
  scrollBtn.style.cssText =
    'position:fixed;bottom:28px;right:28px;z-index:9000;' +
    'width:44px;height:44px;border-radius:50%;border:none;cursor:pointer;' +
    'background:#E8760A;color:#fff;' +
    'display:flex;align-items:center;justify-content:center;' +
    'box-shadow:0 2px 6px rgba(0,0,0,.15);' +
    'opacity:0;transform:translateY(12px);pointer-events:none;' +
    'transition:opacity .22s ease,transform .22s ease,background .15s ease;'
  scrollBtn.addEventListener('mouseenter', function () { this.style.background = '#d06800' })
  scrollBtn.addEventListener('mouseleave', function () { this.style.background = '#E8760A' })
  scrollBtn.addEventListener('click', function () {
    window.scrollTo({ top: 0, behavior: 'smooth' })
  })
  document.body.appendChild(scrollBtn)

  window.addEventListener('scroll', function () {
    var show = window.scrollY > 320
    scrollBtn.style.opacity      = show ? '1' : '0'
    scrollBtn.style.transform    = show ? 'translateY(0)' : 'translateY(12px)'
    scrollBtn.style.pointerEvents = show ? '' : 'none'
  }, { passive: true })

  // Real-time branding sync: when admin saves clinic settings in another tab
  window.addEventListener('storage', function (e) {
    if (e.key === '_canaopticalclinic_logo_url' && e.newValue) {
      // Same root-relative-path fix as the pre-sync block above — the
      // stored value has no `../`, so it needs `base` prepended on
      // /pages/*.html or the browser resolves it against the wrong folder.
      var url = base + e.newValue
      document.querySelectorAll('#site-logo-img, .footer-logo-img, .nav-drawer-logo').forEach(function (img) { img.src = url })
      document.querySelectorAll('#site-favicon').forEach(function (link) { link.href = url })
    }
    if (e.key === '_canaopticalclinic_clinicName' && e.newValue) {
      document.querySelectorAll('.nav-logo-name, .footer-logo-name').forEach(function (el) { el.textContent = e.newValue })
      var footerLine = document.querySelector('.footer-line')
      if (footerLine) footerLine.textContent = '© ' + new Date().getFullYear() + ' ' + e.newValue + '. All rights reserved.'
    }
  })
})()
