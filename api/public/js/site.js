/*
 * The public website's behaviour.
 *
 * No framework and no build step: this is a few hundred lines of DOM work for
 * a handful of static pages, and it loads deferred so it never blocks paint.
 *
 * Everything here is an enhancement. The site is complete and usable with this
 * file blocked — the reveal animations are the only thing that hides content,
 * and their hidden state is scoped to the `.js` class this file's companion
 * inline script sets, so a failure to load leaves the page fully visible
 * rather than blank.
 */
(function () {
  'use strict'

  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)')

  /* ------------------------------------------------------------- reveals */

  /**
   * Fade sections in as they enter the viewport.
   *
   * Once only — re-animating on every scroll past is the thing that makes
   * these effects tiresome by the third visit.
   */
  function initReveals() {
    var items = document.querySelectorAll('[data-reveal]')

    if (!items.length) return

    if (reduced.matches || !('IntersectionObserver' in window)) {
      items.forEach(function (el) { el.classList.add('is-in') })
      return
    }

    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return
          entry.target.classList.add('is-in')
          observer.unobserve(entry.target)
        })
      },
      // A negative bottom margin holds the reveal until the element is
      // properly on screen, not clipping its first pixel.
      { rootMargin: '0px 0px -12% 0px', threshold: 0.05 }
    )

    items.forEach(function (el, index) {
      // Staggers children of the same parent, so a row of cards arrives in
      // sequence rather than all at once.
      var siblings = el.parentElement ? el.parentElement.children : []
      var position = Array.prototype.indexOf.call(siblings, el)
      el.style.setProperty('--i', Math.min(position < 0 ? index : position, 6))
      observer.observe(el)
    })
  }

  /* ---------------------------------------------------------------- hero */

  /** Crossfade the hero photographs. */
  function initHero() {
    var slides = document.querySelectorAll('.hero__media img')

    if (!slides.length) return

    slides[0].classList.add('is-active')

    // One photograph, or a visitor who asked for less motion: leave it still.
    if (slides.length < 2 || reduced.matches) return

    var current = 0

    setInterval(function () {
      // Paused in a background tab — nothing is being watched, and the
      // repaints cost battery.
      if (document.hidden) return

      slides[current].classList.remove('is-active')
      current = (current + 1) % slides.length
      slides[current].classList.add('is-active')
    }, 6500)
  }

  /* -------------------------------------------------------------- header */

  /** Solid header once the page leaves the hero photograph. */
  function initHeader() {
    var header = document.querySelector('.header')

    if (!header) return

    var ticking = false

    function update() {
      header.classList.toggle('is-stuck', window.scrollY > 40)
      ticking = false
    }

    window.addEventListener(
      'scroll',
      function () {
        // Coalesced into one frame: a scroll handler that writes a class on
        // every event is the classic way to make a page feel heavy.
        if (ticking) return
        ticking = true
        window.requestAnimationFrame(update)
      },
      { passive: true }
    )

    update()
  }

  /* ------------------------------------------------------------ counters */

  /** Count the hero figures up from zero when they first appear. */
  function initCounters() {
    var counters = document.querySelectorAll('[data-count]')

    if (!counters.length) return

    function run(el) {
      var target = parseFloat(el.getAttribute('data-count')) || 0
      var suffix = el.getAttribute('data-suffix') || ''

      if (reduced.matches) {
        el.textContent = target + suffix
        return
      }

      var duration = 1100
      var started = null

      function step(now) {
        if (started === null) started = now

        var progress = Math.min((now - started) / duration, 1)
        // Ease-out cubic: fast at first, settling on the final figure.
        var eased = 1 - Math.pow(1 - progress, 3)

        el.textContent = Math.round(target * eased) + suffix

        if (progress < 1) window.requestAnimationFrame(step)
      }

      window.requestAnimationFrame(step)
    }

    if (!('IntersectionObserver' in window)) {
      counters.forEach(run)
      return
    }

    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return
          run(entry.target)
          observer.unobserve(entry.target)
        })
      },
      { threshold: 0.4 }
    )

    counters.forEach(function (el) { observer.observe(el) })
  }

  /* ------------------------------------------------------------ lightbox */

  /** Open a gallery photograph full size. */
  function initLightbox() {
    var buttons = document.querySelectorAll('[data-lightbox]')

    if (!buttons.length) return

    var box = null
    var image = null
    var caption = null
    var close = null
    var opener = null

    /**
     * Built on first use, not on load.
     *
     * An <img> with no src sitting in every page is reported as a broken
     * image by anything that audits the document, and most visitors never
     * open a photograph at all.
     */
    function build() {
      if (box) return

      box = document.createElement('div')
      box.className = 'lightbox'
      box.setAttribute('role', 'dialog')
      box.setAttribute('aria-modal', 'true')
      box.setAttribute('aria-label', 'Photograph')
      box.innerHTML =
        '<button class="lightbox__close" type="button" aria-label="Close">&times;</button>' +
        '<figure style="margin:0"><img alt=""><figcaption></figcaption></figure>'

      document.body.appendChild(box)

      image = box.querySelector('img')
      caption = box.querySelector('figcaption')
      close = box.querySelector('.lightbox__close')

      close.addEventListener('click', hide)

      box.addEventListener('click', function (event) {
        if (event.target === box) hide()
      })
    }

    function open(button) {
      var source = button.querySelector('img')

      if (!source) return

      build()
      opener = button
      image.src = source.getAttribute('data-full') || source.src
      image.alt = source.alt || ''
      caption.textContent = source.alt || ''

      // A frame between inserting the element and adding the class, or the
      // browser has nothing to transition from and the overlay simply appears.
      window.requestAnimationFrame(function () {
        box.classList.add('is-open')
      })

      // The page behind must not scroll under the overlay.
      document.body.style.overflow = 'hidden'
      close.focus()
    }

    function hide() {
      if (!box) return

      box.classList.remove('is-open')
      document.body.style.overflow = ''

      // Focus goes back where it came from, or a keyboard user is dropped at
      // the top of the document.
      if (opener) {
        opener.focus()
        opener = null
      }
    }

    buttons.forEach(function (button) {
      button.addEventListener('click', function () { open(button) })
    })

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && box && box.classList.contains('is-open')) hide()
    })
  }

  /* ----------------------------------------------------------------- nav */

  /** Close the phone menu after following a link within the page. */
  function initNav() {
    var state = document.getElementById('nav-state')

    if (!state) return

    document.querySelectorAll('.nav a').forEach(function (link) {
      link.addEventListener('click', function () { state.checked = false })
    })

    document.addEventListener('click', function (event) {
      if (!state.checked) return
      if (event.target.closest('.header__inner')) return
      state.checked = false
    })
  }

  function init() {
    initHeader()
    initHero()
    initReveals()
    initCounters()
    initLightbox()
    initNav()
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init)
  } else {
    init()
  }
})()
