(function() {
    var userLoggedIn = mw.config.get('wgUserName') !== null;
    
    var blurPref = mw.user.options.get('nsfwblurred');
    var blurEnabled = blurPref === null ? true : !blurPref;

    var birthYear = mw.config.get('wgPrivateBirthYear');
    var isOldEnough = false;
    var now = new Date();
    var thisYear = now.getFullYear();

    if (birthYear && /^\d{4}$/.test(birthYear)) {
        var age = thisYear - parseInt(birthYear, 10);
        isOldEnough = age >= 18;
    }
    if (!userLoggedIn || !isOldEnough || isOldEnough && blurPref === 1) {
        blurEnabled = true;
    }

    function msg(name) {
        return mw.message(name).plain();
    }

    // (Optional) Add a button for toggling filter (for adults only)
    function addToggleButton() {
        if (!userLoggedIn || !isOldEnough) return; // Only allow 18+ to toggle

        var btn = document.createElement('button');
        btn.id = 'nsfw-blur-toggle-btn';
        btn.style.position = 'fixed';
        btn.style.bottom = '22px';
        btn.style.right = '22px';
        btn.style.zIndex = 9999;
        btn.style.padding = '7px 16px';
        btn.style.fontSize = '1em';
        btn.style.background = '#7a1787';
        btn.style.color = 'white';
        btn.style.border = 'none';
        btn.style.borderRadius = '6px';
        btn.style.boxShadow = '0 2px 7px rgba(0,0,0,0.15)';
        btn.style.opacity = '0.88';
        btn.style.cursor = 'pointer';
        btn.textContent = blurEnabled ? msg('nsfwblur-toggle-on') : msg('nsfwblur-toggle-off');
        btn.title = msg('nsfwblur-toggle-tip');
    }


    function updateBlurs() {
        document.querySelectorAll('img.nsfw-blur').forEach(function(img) {
            img.style.filter = blurEnabled ? '' : 'none';
        });
    }

    function scanAndBlur() {
        var images = document.querySelectorAll('img');
        images.forEach(function(img) {
            if (img.classList.contains('nsfw-blur')) return;
            var src = img.src;
            if (!src) return;
            var match = src.match(/\/([A-Za-z0-9_\-%]+\.(?:jpg|jpeg|png|gif|svg))/i);
            if (!match) return;
            var fileName = decodeURIComponent(match[1]);
            var apiUrl = mw.util.wikiScript('api') +
                '?action=query&format=json&prop=revisions&rvprop=content&titles=File:' +
                encodeURIComponent(fileName);

            fetch(apiUrl)
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    var pages = data.query.pages;
                    for (var pageId in pages) {
                        if (!pages.hasOwnProperty(pageId)) continue;
                        var page = pages[pageId];
                        var content = (
                            page.revisions && page.revisions[0] &&
                            (page.revisions[0]['*'] ||
                             (page.revisions[0]['slots'] &&
                                (page.revisions[0]['slots'].main['*'] ||
                                 page.revisions[0]['slots'].main['content'])
                             )
                            )
                        );
                        if (content && content.includes('__NSFW__')) {
                            img.classList.add('nsfw-blur');
                            if (!blurEnabled) {
                                img.style.filter = 'none';
                            }
                        }
                    }
                });
        });
    }

    $(function() {
        addToggleButton();
        scanAndBlur();
        updateBlurs();
    });

    mw.hook('wikipage.content').add(function() {
        scanAndBlur();
        updateBlurs();
    });
})();
