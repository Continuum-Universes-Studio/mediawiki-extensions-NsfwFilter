(function () {
  'use strict';

  function isTruthy(v) {
    return v === true || v === 1 || v === '1' || v === 'true';
  }

  // If user opted to unblur everywhere, do nothing.
  if (isTruthy(mw.config.get('wgNSFWUnblur'))) return;

  var BODY_PREBLUR = 'nsfw-mmv-preblur';
  var WRAP_BLUR = 'nsfw-mmv-blur';

  function getNSFWSet() {
    var list = mw.config.get('wgNSFWFilesOnPage') || [];
    return new Set(list.map(String));
  }

  var nsfwSet = getNSFWSet();

  function normalizeTitleText(t) {
    if (!t) return null;
    try {
      var titleObj = mw.Title.newFromText(String(t));
      return titleObj ? titleObj.getPrefixedText() : null;
    } catch (e) {
      return null;
    }
  }

  function titleFromHref(href) {
    if (!href) return null;
    try {
      var uri = new mw.Uri(href);
      if (uri.query && uri.query.title) return normalizeTitleText(uri.query.title);

      var m = uri.path && uri.path.match(/\/wiki\/(.+)$/);
      if (m && m[1]) return normalizeTitleText(decodeURIComponent(m[1]));
    } catch (e) {}
    return null;
  }

  function getWrapper() {
    return document.querySelector('.mw-mmv-wrapper');
  }

  function currentMediaViewerFileTitle(wrapper) {
    if (!wrapper) return null;

    // Prefer the “More details / file page” button if present; keep fallbacks.
    var link = wrapper.querySelector(
      'a.mw-mmv-description-page-button, a.mw-mmv-repo, a.mw-mmv-filepage, a[href*="File:"]'
    );

    return link && link.href ? titleFromHref(link.href) : null;
  }

  function setPreblur(on) {
    document.body.classList.toggle(BODY_PREBLUR, !!on);
  }

  function setWrapperBlur(wrapper, on) {
    if (!wrapper) return;
    wrapper.classList.toggle(WRAP_BLUR, !!on);
  }

  // --- Throttled updates (prevents mutation storms + flicker) ---
  var scheduled = false;
  function scheduleUpdate() {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(function () {
      scheduled = false;
      update();
    });
  }

  // --- Bouncer mode update ---
  function update() {
    var wrapper = getWrapper();

    if (!wrapper) {
      // MMV closed: clear preblur so it doesn't stick on the normal page.
      setPreblur(false);
      return;
    }

    // Default to blurred while MMV is open.
    setPreblur(true);

    var title = currentMediaViewerFileTitle(wrapper);

    // If MMV is mid-rerender and the title link isn't available yet,
    // DO NOT unblur. Keep blur until we know it's safe.
    if (!title) {
      setWrapperBlur(wrapper, true);
      return;
    }

    var isNSFW = nsfwSet.has(title);

    // If NSFW: blur. If safe: unblur (remove both wrapper blur and preblur).
    if (isNSFW) {
      setWrapperBlur(wrapper, true);
      setPreblur(true);
    } else {
      setWrapperBlur(wrapper, false);
      setPreblur(false);
    }
  }

  // --- PRE-BLUR: capture click BEFORE MediaViewer opens ---
  document.addEventListener(
    'click',
    function (ev) {
      var a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
      if (!a) return;

      var t = titleFromHref(a.href);
      if (t && nsfwSet.has(t)) {
        // Blur immediately before MMV even draws.
        setPreblur(true);
      }
    },
    true
  );

  // Observe MMV DOM churn + navigation changes
  new MutationObserver(scheduleUpdate).observe(document.documentElement, {
    childList: true,
    subtree: true
  });

  window.addEventListener('hashchange', scheduleUpdate);

  mw.hook('wikipage.content').add(function () {
    // If config changes due to partial renders, refresh set.
    nsfwSet = getNSFWSet();
    scheduleUpdate();
  });

  // Initial pass
  scheduleUpdate();
})();
