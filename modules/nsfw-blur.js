(function () {
  'use strict';

  console.log('[NSFW] nsfw-blur.js loaded');

  /* ==============================
   * Utilities
   * ============================== */

  const isTruthy = (v) => v === true || v === 1 || v === '1' || v === 'true';

  const normalizeFileTitle = (t) => {
    if (!t) return null;
    try {
      const title = mw.Title.newFromText(String(t));
      return title ? title.getPrefixedText() : null;
    } catch (e) {
      return null;
    }
  };

  const decodeSafe = (s) => {
    try {
      return decodeURIComponent(String(s));
    } catch (e) {
      return String(s);
    }
  };

  /* ==============================
   * Config
   * ============================== */

  const userWantsUnblur = isTruthy(mw.config.get('wgNSFWUnblur'));
  const nsfwList = mw.config.get('wgNSFWFilesOnPage') || [];

  const nsfwSet = new Set(nsfwList.map(normalizeFileTitle).filter(Boolean));

  if (userWantsUnblur) {
    document.documentElement.classList.add('nsfw-unblur');
    return; // CSS handles everything
  }

  /* ==============================
   * File title resolver (single source of truth)
   * ============================== */

  function resolveFileTitleFromImg(img) {
    if (!img) return null;

    // 1) data-file-name (galleries, widgets)
    const df = img.getAttribute('data-file-name');
    if (df) return normalizeFileTitle('File:' + df);

    // 2) data-title
    const dt = img.getAttribute('data-title');
    if (dt && /^File:/i.test(dt)) return normalizeFileTitle(dt);

    // 3) RDFa / Parsoid
    const resource = img.getAttribute('resource');
    if (resource && /^File:/i.test(resource)) return normalizeFileTitle(resource);

    // 4) Parent anchor URL
    const a = img.closest('a[href]');
    if (!a || !a.href) return null;

    try {
      const uri = new mw.Uri(a.href);

      // /w/index.php?title=File:Foo.png
      // /w/index.php?title=Special:FilePath/Foo.png
      if (uri.query && uri.query.title) {
        const t = String(uri.query.title);

        // Special:FilePath/Foo.png => File:Foo.png
        const mFilePath = t.match(/^Special:FilePath\/(.+)$/i);
        if (mFilePath) return normalizeFileTitle('File:' + decodeSafe(mFilePath[1]));

        return normalizeFileTitle(t);
      }

      if (uri.path) {
        const path = String(uri.path);

        // /wiki/File:Foo.png
        const mWikiFile = path.match(/\/wiki\/(File:[^?#]+)/i);
        if (mWikiFile) return normalizeFileTitle(decodeSafe(mWikiFile[1]));

        // /wiki/Special:FilePath/Foo.png
        const mWikiFilePath = path.match(/\/wiki\/Special:FilePath\/([^?#]+)/i);
        if (mWikiFilePath) return normalizeFileTitle('File:' + decodeSafe(mWikiFilePath[1]));

        // /w/Special:FilePath/Foo.png (some path setups)
        const mWSpecialFilePath = path.match(/\/w\/Special:FilePath\/([^?#]+)/i);
        if (mWSpecialFilePath) return normalizeFileTitle('File:' + decodeSafe(mWSpecialFilePath[1]));
      }
    } catch (e) {
      // ignore
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
    const a = img.closest('a');
    if (a) a.classList.add('nsfw-blur');

    const td = img.closest('td');
    if (td) td.classList.add('nsfw-blur');

    const fileEl = img.closest('.mw-file-element');
    if (fileEl) fileEl.classList.add('nsfw-blur');
  }

  function propagateBlurToAnchorImages(root = document) {
    root.querySelectorAll('a.nsfw-blur > img').forEach((img) => {
      img.classList.add('nsfw-blur');
    });
  }

  function scan(root = document) {
    root.querySelectorAll('img').forEach(markImageIfNSFW);
    propagateBlurToAnchorImages(root);
  }

  /* ==============================
   * Observers & hooks
   * ============================== */

  function observeLateImages() {
    const observer = new MutationObserver((mutations) => {
      for (const m of mutations) {
        for (const node of m.addedNodes) {
          if (!(node instanceof Element)) continue;

          if (node.tagName === 'IMG') {
            markImageIfNSFW(node);
          } else {
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

  function init() {
    scan(document);
    observeLateImages();
  }

  // Initial load
  $(init);

  // MediaWiki hook for content refreshes
  mw.hook('wikipage.content').add(($content) => {
    const root = ($content && $content[0]) ? $content[0] : document;
    scan(root);
  });

})();
