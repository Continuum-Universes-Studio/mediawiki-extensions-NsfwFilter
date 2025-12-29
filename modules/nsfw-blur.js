/* modules/nsfw-blur.js */
/* Keep your “existing” non-top logic here. This file intentionally avoids touching MMV classes
 * so it doesn’t compete with nsfw-blur.top.js.
 */
(function () {
  'use strict';

  // ---- basic gating (same helper so behavior is consistent) ----
  function isTruthy(v) {
    return v === true || v === 1 || v === '1' || v === 'true';
  }

  var userWantsUnblur = isTruthy(mw.config.get('wgNSFWUnblur'));
  var nsfwList = mw.config.get('wgNSFWFilesOnPage') || [];
  var nsfwSet = new Set(nsfwList.map(String));

  // Example: blur/unblur on-page images already tagged by PHP (img.nsfw-blur OR wrapper.nsfw-blur)
  function applyOnPageBlurState(root) {
    var scope = root || document;

    // If user wants unblur, remove blur effects via body class or direct styles.
    // Prefer CSS body class in your stylesheet; this is a safety net.
    if (userWantsUnblur) {
      scope.querySelectorAll('img.nsfw-blur, .nsfw-blur img, .nsfw-blur .mw-file-element').forEach(function (el) {
        el.style.filter = 'none';
      });
      return;
    }

    // Otherwise ensure blur is applied (safety net; CSS should do most of this)
    scope.querySelectorAll('img.nsfw-blur, .nsfw-blur img, .nsfw-blur .mw-file-element').forEach(function (el) {
      el.style.filter = '';
    });
  }

  // Optional: if you want to mark additional links/images from the config list
  // (useful if class application is inconsistent across skins)
  function markAnchorsByConfig(root) {
    if (userWantsUnblur) return;

    var scope = root || document;
    scope.querySelectorAll('a[href]').forEach(function (a) {
      // Light-touch: only mark if it’s a File: link and in our list
      if (!a.href || a.classList.contains('nsfw-blur')) return;

      // Use mw.Uri to extract title param if present
      var t = null;
      try {
        var uri = new mw.Uri(a.href);
        if (uri.query && uri.query.title) {
          var titleObj = mw.Title.newFromText(String(uri.query.title));
          t = titleObj ? titleObj.getPrefixedText() : null;
        }
      } catch (e) {}

      if (t && nsfwSet.has(t)) {
        a.classList.add('nsfw-blur');
        var img = a.querySelector('img');
        if (img) img.classList.add('nsfw-blur');
      }
    });
  }

  function init(root) {
    applyOnPageBlurState(root);
    markAnchorsByConfig(root);
  }

  $(function () {
    init(document);
  });

  mw.hook('wikipage.content').add(function ($content) {
    var node = ($content && $content[0]) ? $content[0] : document;
    init(node);
  });
})();
