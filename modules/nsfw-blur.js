(function () {
  'use strict';

  console.log('[NSFW] nsfw-blur.js loaded');

  /* ==============================
   * Utilities
   * ============================== */

  const isTruthy = v => v === true || v === 1 || v === '1' || v === 'true';

  const normalizeFileTitle = t => {
    if (!t) return null;
    try {
      const title = mw.Title.newFromText(String(t));
      return title ? title.getPrefixedText() : null;
    } catch {
      return null;
    }
  };

  /* ==============================
   * Config
   * ============================== */

  const userWantsUnblur = isTruthy(mw.config.get('wgNSFWUnblur'));
  const nsfwList = mw.config.get('wgNSFWFilesOnPage') || [];

  const nsfwSet = new Set(
    nsfwList.map(normalizeFileTitle).filter(Boolean)
  );

  if (userWantsUnblur) {
    document.documentElement.classList.add('nsfw-unblur');
    return; // CSS handles everything
  }

  /* ==============================
   * File title resolver (single source of truth)
   * ============================== */

  function resolveFileTitleFromImg(img) {
    if (!img) return null;

    // 1. data-file-name (galleries, widgets)
    const df = img.getAttribute('data-file-name');
    if (df) return normalizeFileTitle('File:' + df);

    // 2. data-title
    const dt = img.getAttribute('data-title');
    if (dt?.startsWith('File:')) return normalizeFileTitle(dt);

    // 3. RDFa / Parsoid
    const resource = img.getAttribute('resource');
    if (resource?.startsWith('File:')) {
      return normalizeFileTitle(resource);
    }

    // 4. Parent anchor URL
    const a = img.closest('a[href]');
    if (a?.href) {
      try {
        const uri = new mw.Uri(a.href);

        // /w/index.php?title=File:Foo.png
        if (uri.query?.title) {
          return normalizeFileTitle(uri.query.title);
        }

        // /wiki/File:Foo.png
        if (uri.path) {
          const m = uri.path.match(/\/wiki\/(File:[^?#]+)/);
          if (m) {
            return normalizeFileTitle(decodeURIComponent(m[1]));
          }
        }
      } catch {}
    }

    return null;
  }

  // Debug helper (intentionally global)
  window.__resolveNSFWFileTitle = resolveFileTitleFromImg;

  /* ==============================
   * Marking logic
   * ============================== */

  function markImageIfNSFW(img) {
    if (!img || img.classList.contains('nsfw-blur')) return;

    const title = resolveFileTitleFromImg(img);
    if (!title || !nsfwSet.has(title)) return;

    // Always mark the image itself
    img.classList.add('nsfw-blur');

    // Mark useful ancestors so CSS works in tables & wrappers
    img.closest('a')?.classList.add('nsfw-blur');
    img.closest('td')?.classList.add('nsfw-blur');
    img.closest('.mw-file-element')?.classList.add('nsfw-blur');
  }

  function scan(root = document) {
    root.querySelectorAll('img').forEach(markImageIfNSFW);
  }

  /* ==============================
   * Observers & hooks
   * ============================== */

  function observeLateImages() {
    const observer = new MutationObserver(mutations => {
      for (const m of mutations) {
        for (const node of m.addedNodes) {
          if (!(node instanceof HTMLElement)) continue;

          if (node.tagName === 'IMG') {
            markImageIfNSFW(node);
          } else if (node.querySelector) {
            scan(node);
          }
        }
      }
    });

    observer.observe(document.body, {
      childList: true,
      subtree: true
    });
  }

  $(function () {
    scan();
    observeLateImages();
  });

  mw.hook('wikipage.content').add($content => {
    scan($content?.[0]);
  });

})();
