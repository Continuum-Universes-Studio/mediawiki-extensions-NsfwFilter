/* modules/nsfw-blur.top.js */
(function () {
  'use strict';

  // Proof-of-life marker (you can keep or remove later)
  document.documentElement.classList.add('nsfwblur-top-loaded');

  function isTruthy(v) {
    return v === true || v === 1 || v === '1' || v === 'true';
  }

  // If user opted to unblur everywhere, do nothing.
  if (isTruthy(mw.config.get('wgNSFWUnblur'))) return;

  var BODY_PREBLUR = 'nsfw-mmv-preblur';
  var WRAP_BLUR = 'nsfw-mmv-blur';

  var MMV_WRAPPER_SEL = '.mw-mmv-wrapper';

  // Broad selector set: different MMV versions/skins put the file link in different places.
  var MMV_FILELINK_SEL = [
    'a.mw-mmv-description-page-button',
    'a.mw-mmv-repo',
    'a.mw-mmv-filepage',
    '.mw-mmv-title a',
    '.mw-mmv-title-contain a',
    'a[href*="title=File:"]',
    'a[href*="/wiki/File:"]',
    'a[href*="File:"]'
  ].join(', ');

  function setPreblur(on) {
    var el = document.body || document.documentElement;
    el.classList.toggle(BODY_PREBLUR, !!on);
  }

  function setWrapperBlur(wrapper, on) {
    if (!wrapper) return;
    wrapper.classList.toggle(WRAP_BLUR, !!on);
  }

  function normalizeTitleText(t) {
    if (!t) return null;
    try {
      var obj = mw.Title.newFromText(String(t));
      return obj ? obj.getPrefixedText() : null;
    } catch (e) {
      return null;
    }
  }

  function titleFromHref(href) {
    if (!href) return null;
    try {
      var uri = new mw.Uri(href);

      // /w/index.php?title=File:Foo.jpg
      if (uri.query && uri.query.title) return normalizeTitleText(uri.query.title);

      // /wiki/File:Foo.jpg
      var m = uri.path && uri.path.match(/\/wiki\/(.+)$/);
      if (m && m[1]) return normalizeTitleText(decodeURIComponent(m[1]));
    } catch (e) {}
    return null;
  }

  function titleFromHash() {
    // MMV often drives URL with a hash router. We only need to detect File:...
    var h = String(location.hash || '');
    // Examples can look like "#/media/File:Foo.jpg" or contain encoded bits.
    try {
      h = decodeURIComponent(h);
    } catch (e) {}
    var m = h.match(/File:[^#?&]+/);
    return m ? normalizeTitleText(m[0]) : null;
  }

  function buildNSFWSet() {
    var set = new Set();

    // 1) From config (if you have it)
    var list = mw.config.get('wgNSFWFilesOnPage');
    if (Array.isArray(list)) {
      list.forEach(function (t) {
        t = normalizeTitleText(t);
        if (t) set.add(t);
      });
    }

    // 2) From DOM markers you already apply in PHP (most reliable on your setup)
    document.querySelectorAll(
      '.nsfw-blur a[href], a.nsfw-blur[href], img.nsfw-blur'
    ).forEach(function (el) {
      var href = el.href || (el.closest && el.closest('a[href]') && el.closest('a[href]').href);
      var t = titleFromHref(href);
      if (t) set.add(t);
    });

    // 3) If this is an NSFW File: page, include the current page title too
    if (document.body && document.body.classList.contains('nsfw-filepage-blur')) {
      // wgPageName is like "File:Foo.jpg" but URL-ish (underscores)
      var pageName = mw.config.get('wgPageName'); // e.g. "File:Foo.jpg"
      if (pageName) {
        set.add(normalizeTitleText(pageName.replace(/_/g, ' ')));
      }
    }

    return set;
  }

  var nsfwSet = buildNSFWSet();

  function getWrapper() {
    return document.querySelector(MMV_WRAPPER_SEL);
  }

  function currentMediaViewerFileTitle(wrapper) {
    if (!wrapper) return null;

    var link = wrapper.querySelector(MMV_FILELINK_SEL);
    var t = link && link.href ? titleFromHref(link.href) : null;

    // Fallback: hash router
    return t || titleFromHash();
  }

  // Throttle updates to avoid mutation storms
  var scheduled = false;
  function scheduleUpdate() {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(function () {
      scheduled = false;
      update();
    });
  }

  // Blur-by-default while MMV is open. Only unblur when proven safe.
  function update() {
    var wrapper = getWrapper();

    if (!wrapper) {
      setPreblur(false);
      return;
    }

    // Default: blur while MMV is open (prevents slips during rerender)
    setPreblur(true);

    var title = currentMediaViewerFileTitle(wrapper);
    if (!title) {
      // unknown => keep blur
      setWrapperBlur(wrapper, true);
      return;
    }

    var isNSFW = nsfwSet.has(title);

    setWrapperBlur(wrapper, isNSFW);
    setPreblur(isNSFW);
  }

  // Preblur *before* MMV handlers: capture pointerdown and also check DOM-marked NSFW wrappers.
  function preblurIfNSFW(ev) {
    var target = ev.target;

    // Fast path: anything inside an element already marked .nsfw-blur
    if (target && target.closest && target.closest('.nsfw-blur, img.nsfw-blur, a.nsfw-blur')) {
      setPreblur(true);
      return;
    }

    // Fallback: check by title match
    var a = target && target.closest ? target.closest('a[href]') : null;
    if (!a) return;

    var t = titleFromHref(a.href);
    if (t && nsfwSet.has(t)) setPreblur(true);
  }

  document.addEventListener('pointerdown', preblurIfNSFW, true);
  document.addEventListener('mousedown', preblurIfNSFW, true);
  document.addEventListener('touchstart', preblurIfNSFW, true);

  // Observe MMV DOM churn
  new MutationObserver(scheduleUpdate).observe(document.documentElement, {
    childList: true,
    subtree: true
  });

  window.addEventListener('hashchange', scheduleUpdate);

  mw.hook('wikipage.content').add(function () {
    nsfwSet = buildNSFWSet();
    scheduleUpdate();
  });

  scheduleUpdate();
})();
