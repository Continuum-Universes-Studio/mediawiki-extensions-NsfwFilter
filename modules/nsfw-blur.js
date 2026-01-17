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
  console.log('[NSFW] files on page:', mw.config.get('wgNSFWFilesOnPage'));
  console.log('[NSFW] nsfwSet size:', nsfwSet.size);

  if (userWantsUnblur) {
    document.documentElement.classList.add('nsfw-unblur');
    return; // CSS handles everything
  }

  /* ==============================
   * File title resolver (single source of truth)
   * ============================== */

  function resolveFileTitleFromImg(img) {
    if (!img) return null;

    // -----------------------------
    // Helpers (local by design)
    // -----------------------------
    const norm = (t) => (t ? normalizeFileTitle(t) : null);
    const asFile = (name) => norm('File:' + name);

    const looksLikeFilename = (s) => /\.[a-z0-9]{2,5}$/i.test(String(s || ''));
    const stripThumbSizePrefix = (s) => String(s || '').replace(/^\d+px-/, '');
    const decode = (s) => decodeSafe(String(s || ''));

    const fromSpecialFilePathTitle = (title) => {
      const t = String(title || '');
      const m = t.match(/^Special:FilePath\/(.+)$/i);
      return m ? asFile(decode(m[1])) : null;
    };

    const fromWikiFilePath = (path) => {
      const p = String(path || '');

      // /wiki/File:Foo.png
      let m = p.match(/\/wiki\/(File:[^?#]+)/i);
      if (m) return norm(decode(m[1]));

      // /w/index.php?title=File:Foo.png handled elsewhere (query)
      // /wiki/Special:FilePath/Foo.png, /w/Special:FilePath/Foo.png, /Special:FilePath/Foo.png
      m = p.match(/\/(?:wiki\/)?Special:FilePath\/([^?#]+)/i);
      if (m) return asFile(decode(m[1]));

      return null;
    };

    const fromDirectImagesPath = (path) => {
      // Handles BOTH of these:
      // /w/images/6/60/Aneta01.png
      // /w/images/thumb/6/60/Aneta01.png/320px-Aneta01.png
      const p = String(path || '');

      // Non-thumb: /w/images/<hash>/<hash>/Foo.png
      let m = p.match(/\/w\/images\/[^/]+\/[^/]+\/([^/?#]+)$/i);
      if (m) return asFile(decode(m[1]));

      // Thumb: /w/images/thumb/<hash>/<hash>/Foo.png/320px-Foo.png
      m = p.match(/\/w\/images\/thumb\/[^/]+\/[^/]+\/([^/?#]+)\/[^/?#]+$/i);
      if (m) return asFile(decode(m[1]));

      return null;
    };

    const fromHrefOrSrc = (url) => {
      if (!url) return null;

      try {
        const uri = new mw.Uri(url);

        // Query titles: ...?title=File:Foo.png or ...?title=Special:FilePath/Foo.png
        const qTitle = uri?.query?.title;
        if (qTitle) return fromSpecialFilePathTitle(qTitle) || norm(String(qTitle));

        // /wiki/File:Foo.png or Special:FilePath/...
        if (uri?.path) {
          return (
            fromWikiFilePath(uri.path) ||
            fromDirectImagesPath(uri.path)
          );
        }
      } catch (e) {
        // ignore
      }

      return null;
    };

    const fromAltOrTitle = (el) => {
      if (!el) return null;

      // PortableInfobox often has alt="Foo.png" and anchor title="Foo.png"
      const alt = el.getAttribute('alt');
      if (alt && looksLikeFilename(alt)) return asFile(decode(alt));

      const title = el.getAttribute('title');
      if (title && looksLikeFilename(title)) return asFile(decode(title));

      return null;
    };

    // -----------------------------
    // Resolution chain (ordered)
    // -----------------------------

    // 1) data-file-name (some widgets/galleries)
    {
      const df = img.getAttribute('data-file-name');
      if (df) return asFile(df);
    }

    // 2) data-title (if it already includes File:)
    {
      const dt = img.getAttribute('data-title');
      if (dt && /^File:/i.test(dt)) return norm(dt);
    }

    // 3) RDFa / Parsoid (resource="File:...")
    {
      const resource = img.getAttribute('resource');
      if (resource && /^File:/i.test(resource)) return norm(resource);
    }

    // 4) PortableInfobox & misc: alt/title often contains filename
    {
      const byAlt = fromAltOrTitle(img);
      if (byAlt) return byAlt;
    }

    // 5) IMG src/currentSrc: parse /w/images/... and thumb urls
    {
      const src = img.currentSrc || img.src;
      const bySrc = fromHrefOrSrc(src);
      if (bySrc) return bySrc;

      // extra fallback: last segment may be "320px-Foo.png"
      if (src) {
        const noQ = String(src).split('?')[0].split('#')[0];
        const last = decode(noQ.split('/').pop() || '');
        const cleaned = stripThumbSizePrefix(last);
        if (looksLikeFilename(cleaned)) return asFile(cleaned);
      }
    }

    // 6) Parent anchor URL (gallery case usually hits here)
    {
      const a = img.closest?.('a[href]');
      const byHref = fromHrefOrSrc(a?.href);
      if (byHref) return byHref;

      // PortableInfobox anchor title="Foo.png"
      const byAnchorTitle = fromAltOrTitle(a);
      if (byAnchorTitle) return byAnchorTitle;
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
