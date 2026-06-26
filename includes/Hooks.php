<?php
namespace ContinuumUniverses\ContinuumNsfwFilter;

if ( !defined( 'MEDIAWIKI' ) ) {
    die();
}

use MediaWiki\Context\RequestContext;
use MediaWiki\FileRepo\File\File;
use MediaWiki\Html\FormOptions;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Pager\ImageListPager;
use MediaWiki\Pager\NewFilesPager;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Parser\ParserOutputLinkTypes;
use MediaWiki\Request\ContentSecurityPolicy;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserRigorOptions;
use OutputPage;
use Parser;
use Skin;
use MediaWiki\Title\Title;

use User;
use Wikimedia\FileBackend\HTTPFileStreamer;

class Hooks {
    private const NSFW_MARKER = '__NSFW__';
    private const NSFW_CATEGORY_DBKEY = 'NSFW'; // Category:NSFW
    private const NSFW_FILE_CATEGORY_DBKEY = 'NSFW_Files'; // Category:NSFW_Files
    private const NSFW_GORE_CATEGORY_DBKEY = 'NSFW_Gore'; // Category:NSFW Gore
    private const NSFW_SEXUAL_CATEGORY_DBKEY = 'NSFW_Sexual'; // Category:NSFW Sexual
    private const UNBLUR_RIGHT = 'nsfw-unblur';
    private const PLACEHOLDER_CONFIG = 'NsfwFilterPlaceholderImage';
    private const NSFW_ROBOTS_TAG = 'noindex, noimageindex, noarchive';
    private const PROXY_ENTRY_POINT = 'nsfwProxy.php';

    private const OPT_UNBLUR           = 'nsfwblurred';
    private const OPT_UNBLUR_GORE      = 'nsfwblurred_gore';
    private const OPT_UNBLUR_SEXUAL    = 'nsfwblurred_sexual';
    private const OPT_BIRTHDATE        = 'nsfw_birthdate';
    private const OPT_BIRTHDATE_LEGACY = 'nsfw_birthyear';

    private const PREF_GORE = 'gore';
    private const PREF_SEXUAL = 'sexual';

    private const MIN_AGE = 18;

    public static function getMinimumAge(): int {
        return self::MIN_AGE;
    }

    public static function normalizeBirthDateValue( string $value ): ?string {
        $value = trim( $value );

        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
            return $value;
        }
        if ( preg_match( '/^\d{4}$/', $value ) ) {
            return $value . '-01-01';
        }

        foreach ( [ 'm-d-Y', 'm/d/Y', 'n-j-Y', 'n/j/Y', 'Y/m/d' ] as $format ) {
            $date = \DateTimeImmutable::createFromFormat( '!' . $format, $value );
            if ( $date && $date->format( $format ) === $value ) {
                return $date->format( 'Y-m-d' );
            }
        }

        return null;
    }

    public static function isBirthDateAtLeastMinimumAge( string $value ): bool {
        $normalized = self::normalizeBirthDateValue( $value );
        if ( $normalized === null ) {
            return false;
        }

        $birth = new \DateTimeImmutable( $normalized );
        $ageThreshold = $birth->modify( '+' . self::getMinimumAge() . ' years' );
        if ( $ageThreshold === false ) {
            return false;
        }

        return $ageThreshold <= new \DateTimeImmutable( 'today' );
    }

    /* ============================================================
     *  PAGE DISPLAY
     * ========================================================== */

    public static function onBeforePageDisplay( OutputPage $out, Skin $skin ): bool {
        $services = MediaWikiServices::getInstance();
        $user = $out->getUser();

        $title = $out->getTitle();
        $isContentPage = ( $title && $title->isContentPage() );

        $userWantsUnblur = self::userWantsUnblur( $services, $user );

        // Keep your toggle
        $out->addJsConfigVars( [
            'wgNSFWUnblur' => $userWantsUnblur,
            'wgNSFWPlaceholderImageUrl' => self::resolvePlaceholderUrl( $services ),
            'wgNSFWProxyScriptUrl' => self::getProxyScriptUrl( $services ),
        ] );

        // Make your CSS's body override actually work (your CSS checks body.nsfw-unblur)
        if ( $userWantsUnblur ) {
            $out->addBodyClasses( 'nsfw-unblur' );
        } else {
            $out->addInlineScript( self::getSearchPreviewInlineScript() );
        }

        self::applyFilePagePlaceholderClass( $out, $userWantsUnblur );
        self::filterPageImagesOpenGraphMeta( $out, $services, $user );

        if ( $isContentPage ) {
            if ( self::shouldRestrictPageContent( $services, $out ) ) {
                $out->addBodyClasses( 'nsfw-page-restricted' );
                $out->addJsConfigVars( [ 'wgNSFWPage' => true ] );
            }
        }

        $out->addInlineStyle( self::getEarlyInlineCss() );

        return true;
    }


    /**
     * Returns the current HTML output buffer from OutputPage in a version-tolerant way.
     */
    private static function getOutputHtml( OutputPage $out ): string {
        // MW 1.43+ OutputPage has getHTML() in many setups
        if ( method_exists( $out, 'getHTML' ) ) {
            $html = $out->getHTML();
            return is_string( $html ) ? $html : '';
        }

        if ( method_exists( $out, 'getOutput' ) ) {
            $maybe = $out->getOutput();
            return is_string( $maybe ) ? $maybe : '';
        }
        return '';
    }

    private static function getSearchPreviewInlineScript(): string {
        return <<<'JS'
( function () {
    'use strict';

    const isTruthy = ( v ) => v === true || v === 1 || v === '1' || v === 'true';
    const userWantsUnblur = isTruthy( mw.config.get( 'wgNSFWUnblur' ) );
    if ( userWantsUnblur ) {
        return;
    }

    const proxyScriptUrl = mw.config.get( 'wgNSFWProxyScriptUrl' );
    if ( !proxyScriptUrl ) {
        return;
    }

    function extractFileDbKeyFromUrl( url ) {
        if ( !url || typeof url !== 'string' ) {
            return null;
        }

        const decoded = decodeURIComponent( url );

        let match = decoded.match( /\/(?:img_auth\.php|images)\/thumb\/[^/]+\/[^/]+\/([^/"'?#]+\.[a-z0-9]{2,5})\//i );
        if ( match && match[ 1 ] ) {
            return match[ 1 ].replace( / /g, '_' );
        }

        match = decoded.match( /\/(?:img_auth\.php\/[^/]+\/[^/]+|images\/[^/]+\/[^/]+)\/([^/"'?#]+\.[a-z0-9]{2,5})/i );
        if ( match && match[ 1 ] ) {
            return match[ 1 ].replace( / /g, '_' );
        }

        match = decoded.match( /\/wiki\/File:([^/"'?#]+\.[a-z0-9]{2,5})/i );
        if ( match && match[ 1 ] ) {
            return match[ 1 ].replace( / /g, '_' );
        }

        match = decoded.match( /(?:[?&]title=)(?:File:)?([^&]+?\.[a-z0-9]{2,5})/i );
        if ( match && match[ 1 ] ) {
            return match[ 1 ].replace( / /g, '_' );
        }

        match = decoded.match( /\/([^/"'?#]+\.[a-z0-9]{2,5})$/i );
        if ( match && match[ 1 ] ) {
            return match[ 1 ].replace( / /g, '_' );
        }

        return null;
    }

    function extractWidthFromUrl( url ) {
        if ( !url || typeof url !== 'string' ) {
            return 0;
        }

        const decoded = decodeURIComponent( url );
        const match = decoded.match( /(?:^|-)(\d+)px-/i );
        return match && match[ 1 ] ? parseInt( match[ 1 ], 10 ) : 0;
    }

    function buildProxyUrl( fileDbKey, width ) {
        if ( !fileDbKey ) {
            return null;
        }

        try {
            const url = new URL( proxyScriptUrl, window.location.href );
            url.searchParams.set( 'title', `File:${ fileDbKey }` );
            if ( width > 0 ) {
                url.searchParams.set( 'width', String( width ) );
            } else {
                url.searchParams.delete( 'width' );
            }
            return url.toString();
        } catch ( e ) {
            return null;
        }
    }

    function isProxyUrl( url ) {
        return typeof url === 'string' && url.indexOf( proxyScriptUrl ) !== -1;
    }

    function extractUrlFromCssImage( value ) {
        if ( !value || typeof value !== 'string' || value === 'none' ) {
            return null;
        }

        const match = value.match( /^url\((["']?)(.*?)\1\)$/i );
        return match && match[ 2 ] ? match[ 2 ] : null;
    }

    function cssUrl( url ) {
        return 'url("' + url.replace( /\\/g, '\\\\' ).replace( /"/g, '\\"' ) + '")';
    }

    function rewritePreviewImage( img ) {
        if ( !img ) {
            return;
        }

        const src = img.getAttribute( 'src' ) || img.getAttribute( 'data-src' );
        if ( !src ) {
            return;
        }

        if ( img.dataset.nsfwPreviewProxy === '1' && isProxyUrl( src ) ) {
            return;
        }

        const fileDbKey = extractFileDbKeyFromUrl( src );
        if ( !fileDbKey ) {
            return;
        }

        const widthAttr = img.getAttribute( 'width' );
        const width = ( widthAttr && /^\d+$/.test( widthAttr ) ) ?
            parseInt( widthAttr, 10 ) :
            extractWidthFromUrl( src );

        const proxyUrl = buildProxyUrl( fileDbKey, width );
        if ( !proxyUrl ) {
            return;
        }

        img.setAttribute( 'src', proxyUrl );
        if ( img.hasAttribute( 'srcset' ) ) {
            img.setAttribute( 'srcset', '' );
        }
        if ( img.hasAttribute( 'data-src' ) ) {
            img.setAttribute( 'data-src', proxyUrl );
        }
        if ( img.hasAttribute( 'data-srcset' ) ) {
            img.setAttribute( 'data-srcset', '' );
        }

        img.dataset.nsfwPreviewProxy = '1';
    }

    function rewritePreviewBackground( element ) {
        if ( !element || !element.style ) {
            return;
        }

        const src = extractUrlFromCssImage( element.style.backgroundImage );
        if ( !src ) {
            return;
        }

        if ( element.dataset.nsfwPreviewProxy === '1' && isProxyUrl( src ) ) {
            return;
        }

        const fileDbKey = extractFileDbKeyFromUrl( src );
        if ( !fileDbKey ) {
            return;
        }

        const width = element.clientWidth || element.offsetWidth || extractWidthFromUrl( src ) || 60;
        const proxyUrl = buildProxyUrl( fileDbKey, width );
        if ( !proxyUrl ) {
            return;
        }

        element.style.backgroundImage = cssUrl( proxyUrl );
        element.dataset.nsfwPreviewProxy = '1';
    }

    function scan( root ) {
        const scope = root || document;
        const imageSelector = '.cdx-typeahead-search img, .continuum-typeahead-search-container img';
        const backgroundSelector = '.cdx-typeahead-search .cdx-thumbnail__image, ' +
            '.continuum-typeahead-search-container .cdx-thumbnail__image';

        if ( scope instanceof Element ) {
            if ( scope.matches( imageSelector ) ) {
                rewritePreviewImage( scope );
            }
            if ( scope.matches( backgroundSelector ) ) {
                rewritePreviewBackground( scope );
            }
        }

        scope.querySelectorAll( imageSelector )
            .forEach( rewritePreviewImage );
        scope.querySelectorAll( backgroundSelector )
            .forEach( rewritePreviewBackground );
    }

    function observe() {
        const observer = new MutationObserver( ( mutations ) => {
            for ( const m of mutations ) {
                if ( m.type === 'attributes' ) {
                    if ( m.target instanceof Element ) {
                        scan( m.target );
                    }
                    continue;
                }

                for ( const node of m.addedNodes ) {
                    if ( !( node instanceof Element ) ) {
                        continue;
                    }

                    if ( node.tagName === 'IMG' ) {
                        rewritePreviewImage( node );
                    } else {
                        scan( node );
                    }
                }
            }
        } );

        observer.observe( document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: [ 'style', 'src', 'srcset', 'data-src', 'data-srcset' ]
        } );
    }

    $( () => {
        scan( document );
        observe();
    } );
}() );
JS;
    }

    private static function shouldRestrictPageContent( MediaWikiServices $services, OutputPage $out ): bool {
        $title = $out->getTitle();
        if ( !$title || !$title->isContentPage() ) {
            return false;
        }

        $action = $out->getRequest()->getVal( 'action', 'view' );
        if ( $action !== 'view' ) {
            return false;
        }

        $requirements = self::getContentTitleVisibilityRequirements( $title );
        return self::hasAnyNsfwRequirements( $requirements )
            && !self::userMeetsNsfwRequirements( $services, $out->getUser(), $requirements );
    }

    public static function onOutputPageBeforeHTML( OutputPage $out, &$text ): void {
        $services = MediaWikiServices::getInstance();
        if ( self::shouldRestrictPageContent( $services, $out ) ) {
            $text = self::buildRestrictedPageHtml( $out );
            return;
        }

        $text = self::rewriteNsfwImgAuthUrlsInHtml( $text );
        $text = self::rewriteNsfwMediaUrlsInHtml( $text );
        $text = self::rewriteNsfwDomAttributesInHtml( $text );
        
        $title = $out->getTitle();
        if (
            $title
            && $title->inNamespace( NS_FILE )
            && self::isFileTitleMarkedNSFW( $title )
            && $out->getRequest()->getVal( 'action', 'view' ) === 'view'
        ) {
            $text = self::rewriteNsfwFilePageSizeLinks( $text, $title );
        }
    }

    public static function onOutputPageAfterGetHeadLinksArray( array &$tags, OutputPage $out ): void {
        $meta = self::buildFilteredPageImagesMetaForOutput(
            $out,
            MediaWikiServices::getInstance(),
            $out->getUser()
        );
        if ( !$meta ) {
            return;
        }

        foreach ( $tags as $key => $tag ) {
            if (
                ( is_string( $key ) && str_starts_with( $key, 'meta-og:image' ) )
                || ( is_string( $tag ) && self::isOpenGraphImageMetaTagHtml( $tag ) )
            ) {
                unset( $tags[$key] );
            }
        }

        $tags['meta-og:image'] = Html::element( 'meta', [
            'property' => 'og:image',
            'content' => $meta['source'],
        ] );

        if ( $meta['width'] > 0 ) {
            $tags['meta-og:image:width'] = Html::element( 'meta', [
                'property' => 'og:image:width',
                'content' => (string)$meta['width'],
            ] );
        }

        if ( $meta['height'] > 0 ) {
            $tags['meta-og:image:height'] = Html::element( 'meta', [
                'property' => 'og:image:height',
                'content' => (string)$meta['height'],
            ] );
        }
    }

    private static function buildRestrictedPageHtml( OutputPage $out ): string {
        $content = Html::element(
            'h2',
            [ 'class' => 'nsfw-page-restriction__title' ],
            wfMessage( 'nsfwblur-page-restricted-title' )->text()
        );

        $content .= Html::element(
            'p',
            [ 'class' => 'nsfw-page-restriction__body' ],
            wfMessage( 'nsfwblur-page-restricted-body' )->text()
        );

        if ( $out->getUser()->isRegistered() ) {
            $content .= Html::rawElement(
                'p',
                [ 'class' => 'nsfw-page-restriction__actions' ],
                Html::element(
                    'a',
                    [
                        'class' => 'nsfw-page-restriction__link',
                        'href' => SpecialPage::getTitleFor( 'Preferences' )->getLocalURL( [
                            'mw-prefsection' => 'rendering/files'
                        ] )
                    ],
                    wfMessage( 'nsfwblur-page-restricted-settings' )->text()
                )
            );

            $content .= Html::element(
                'p',
                [ 'class' => 'nsfw-page-restriction__tip' ],
                wfMessage( 'nsfwblur-toggle-tip' )->text()
            );
        }

        return Html::rawElement(
            'div',
            [
                'class' => 'mw-message-box mw-message-box-warning nsfw-page-restriction',
                'role' => 'note'
            ],
            $content
        );
    }


    /* ============================================================
     *  OUTPUTPAGE PARSEROUTPUT (CONTENT PAGES) — AUTHORITATIVE LIST
     *  Hooks: OutputPageParserOutput + OutputPageParserOutputComplete
     * ========================================================== */

    public static function onOutputPageParserOutput( OutputPage $out, ParserOutput $parserOutput ): void {
        self::injectNsfwFilesOnPageFromParserOutput( $out, $parserOutput );
    }

    public static function onOutputPageParserOutputComplete( OutputPage $out, ParserOutput $parserOutput ): void {
        self::injectNsfwFilesOnPageFromParserOutput( $out, $parserOutput );
    }

    private static function injectNsfwFilesOnPageFromParserOutput( OutputPage $out, ParserOutput $parserOutput ): void {
        // Frontend file blur is disabled. Keep the legacy config key empty so any
        // leftover client code stays inert while the NSFW proxy decides the payload.
        if ( method_exists( $parserOutput, 'setJsConfigVar' ) ) {
            $parserOutput->setJsConfigVar( 'wgNSFWFilesOnPage', [] );
        } else {
            $parserOutput->addJsConfigVars( 'wgNSFWFilesOnPage', [] );
        }
    }



    /* ============================================================
     *  PARSER OUTPUT (OPTIONAL CACHE/DEBUG) — SAFE SIGNATURE
     *  Hook: ParserOutput
     * ========================================================== */
    public static function onThumbnailBeforeProduceHTML( $thumbnail, array &$attribs, &$linkAttribs, ...$more ): void {
        if ( !method_exists( $thumbnail, 'getFile' ) ) {
            return;
        }

        $file = $thumbnail->getFile();
        if ( !$file instanceof File ) {
            return;
        }

        $fileTitle = self::resolveRenderedNsfwFileTitle( $file );
        if ( !$fileTitle || !self::isFileTitleMarkedNSFW( $fileTitle ) ) {
            return;
        }

        $transformParams = self::extractTransformParamsFromRenderedAttributes( $attribs );
        $attribs['src'] = self::buildProxyUrlForFileTitle( $fileTitle, $transformParams );

        if ( isset( $attribs['srcset'] ) && is_string( $attribs['srcset'] ) ) {
            $attribs['srcset'] = self::rewriteProxySrcSet(
                $attribs['srcset'],
                $fileTitle,
                $transformParams
            );
        }

        if (
            is_array( $linkAttribs )
            && isset( $linkAttribs['href'] )
            && is_string( $linkAttribs['href'] )
            && self::shouldRewriteRenderedFileHref( $linkAttribs['href'], $file )
        ) {
            $linkParams = self::extractTransformParamsFromUrl( $linkAttribs['href'] );
            if ( $linkParams === [] ) {
                $linkParams = $transformParams;
            }
            $linkAttribs['href'] = self::buildProxyUrlForFileTitle( $fileTitle, $linkParams );
        }
    }
    public static function onParserOutput( Parser $parser, ParserOutput $po, ...$args ): bool {
        // Debug/inspection only; not relied on for the final blur list.
        $dbKeys = [];
        foreach ( $po->getLinkList( ParserOutputLinkTypes::MEDIA ) as $linkItem ) {
            $link = $linkItem['link'] ?? null;
            if ( $link instanceof \MediaWiki\Title\TitleValue ) {
                $dbKeys[] = $link->getDBkey();
            }
        }

        $html = $po->getContentHolderText();
        if ( is_string( $html ) && $html !== '' ) {
            $dbKeys = array_merge( $dbKeys, self::extractImageDbKeysFromHtml( $html ) );
        }

        $dbKeys = array_values( array_unique( array_filter( $dbKeys ) ) );
        $po->setExtensionData( 'nsfw-image-dbkeys', $dbKeys );

        return true;
    }

    /* ============================================================
     *  HTML SCRAPE HELPERS (GALLERY + PORTABLEINFOBOX)
     * ========================================================== */

    private static function extractImageDbKeysFromHtml( string $html ): array {
        if ( $html === '' ) {
            return [];
        }

        $keys = [];

        // 0) data-file-name="Foo.png"
        if ( preg_match_all( '~\bdata-file-name="([^"]+\.[a-z0-9]{2,5})"~i', $html, $m ) ) {
            foreach ( $m[1] as $name ) {
                $keys[] = self::normalizeDbKeyFromMaybeUrlOrName( $name );
            }
        }

        // 0.5) data-title="File:Foo.png"
        if ( preg_match_all( '~\bdata-title="([^"]+)"~i', $html, $m ) ) {
            foreach ( $m[1] as $val ) {
                $keys[] = self::normalizeDbKeyFromMaybeUrlOrName( $val );
            }
        }

        // 1) <img ... alt="Foo.png">
        if ( preg_match_all( '~\balt="([^"]+\.[a-z0-9]{2,5})"~i', $html, $m ) ) {
            foreach ( $m[1] as $name ) {
                $keys[] = self::normalizeDbKeyFromMaybeUrlOrName( $name );
            }
        }

        // 2) <a ... title="File:Foo.png"> or title="Foo.png"
        if ( preg_match_all( '~\btitle="([^"]+\.[a-z0-9]{2,5})"~i', $html, $m ) ) {
            foreach ( $m[1] as $name ) {
                $keys[] = self::normalizeDbKeyFromMaybeUrlOrName( $name );
            }
        }

        // 3) Direct file URL: /images/6/60/Foo.png or /img_auth.php/1/17/Foo.png
        if ( preg_match_all(
            '~/(?:img_auth\.php/[^/]+/[^/]+|images/[^/]+/[^/]+)/([^/"\'\?#]+\.[a-z0-9]{2,5})~i',
            $html,
            $m
        ) ) {
            foreach ( $m[1] as $name ) {
                $keys[] = self::normalizeDbKeyFromMaybeUrlOrName( $name );
            }
        }

        // 4) Thumb URL: /img_auth.php/thumb/.../Foo.png/320px-Foo.png or /images/thumb/.../Foo.png/320px-Foo.png
        if ( preg_match_all( '#/(?:img_auth\.php|images)/thumb/[^/]+/[^/]+/([^/"\'\?\#]+\.[a-z0-9]{2,5})/#i', $html, $m ) ) {
            foreach ( $m[1] as $name ) {
                $keys[] = self::normalizeDbKeyFromMaybeUrlOrName( $name );
            }
        }

        // 5) File page links: /wiki/File:Foo.png
        if ( preg_match_all( '#/wiki/File:([^/"\'\?\#]+?\.[a-z0-9]{2,5})#i', $html, $m ) ) {
            foreach ( $m[1] as $name ) {
                $keys[] = self::normalizeDbKeyFromMaybeUrlOrName( $name );
            }
        }

        // 6) index.php?title=File:Foo.png
        if ( preg_match_all( '#[?&]title=File:([^&"\'>]+?\.[a-z0-9]{2,5})#i', $html, $m ) ) {
            foreach ( $m[1] as $name ) {
                $keys[] = self::normalizeDbKeyFromMaybeUrlOrName( $name );
            }
        }

        return array_values( array_unique( array_filter( $keys ) ) );
    }

    private static function normalizeDbKeyFromMaybeUrlOrName( string $raw ): string {
        $s = trim( html_entity_decode( $raw, ENT_QUOTES ) );
        $s = rawurldecode( $s );

        // Strip any leading namespace noise (":File:Foo.png" or "File:Foo.png")
        $s = preg_replace( '/^:?\s*File:/i', '', $s );

        // If it contains a path, keep only the last segment
        if ( strpos( $s, '/' ) !== false ) {
            $parts = explode( '/', $s );
            $s = end( $parts );
        }

        // Strip common thumb prefixes: "320px-Foo.png" -> "Foo.png"
        $s = preg_replace( '/^\d+px-/', '', $s );

        // Normalize spaces
        $s = str_replace( ' ', '_', $s );

        return $s;
    }

    private static function resolveSearchThumbReplacementUrl(
        MediaWikiServices $services,
        Title $pageTitle,
        User $user
    ): ?string {
        $fileTitle = self::resolveSearchThumbFileTitleForPage( $services, $pageTitle );
        if ( $fileTitle ) {
            if ( self::shouldUsePlaceholderReplacement( $services, $fileTitle, $user ) ) {
                return self::buildProxyUrlForFileTitle( $fileTitle, [ 'width' => 120 ] );
            }

            $thumbUrl = self::resolveSearchThumbPageImageUrl( $services, $pageTitle, $user );
            if ( $thumbUrl ) {
                return $thumbUrl;
            }
        }

        $pageRequirements = self::getContentTitleVisibilityRequirements( $pageTitle );
        if (
            self::hasAnyNsfwRequirements( $pageRequirements )
            && !self::userMeetsNsfwRequirements( $services, $user, $pageRequirements )
        ) {
            return self::resolvePlaceholderUrl( $services );
        }

        return null;
    }

    private static function resolveSearchThumbPageImageUrl(
        MediaWikiServices $services,
        Title $pageTitle,
        User $user
    ): ?string {
        $pageId = $pageTitle->getArticleID();
        if ( !$pageId ) {
            return null;
        }

        try {
            $dbr = $services->getConnectionProvider()->getReplicaDatabase();
            $image = $dbr->newSelectQueryBuilder()
                ->select( 'pp_value' )
                ->from( 'page_props' )
                ->where( [
                    'pp_propname' => 'page_image_free',
                    'pp_page' => (int)$pageId,
                ] )
                ->caller( __METHOD__ )
                ->fetchField();
        } catch ( \Throwable $e ) {
            return null;
        }

        if ( !is_string( $image ) || $image === '' ) {
            return null;
        }

        $fileTitle = Title::newFromText( $image, NS_FILE );
        if ( !$fileTitle || !$fileTitle->inNamespace( NS_FILE ) ) {
            return null;
        }

        $file = self::resolveRepoFile( $services, $fileTitle );
        if ( !$file || !$file->exists() ) {
            return null;
        }

        if ( self::shouldUsePlaceholderReplacement( $services, $fileTitle, $user ) ) {
            return self::buildProxyUrlForFileTitle( $fileTitle, [ 'width' => 120 ] );
        }

        $thumb = $file->createThumb( 120 );
        return is_string( $thumb ) && $thumb !== '' ? $thumb : null;
    }

    private static function resolveSearchThumbFileTitleForPage(
        MediaWikiServices $services,
        Title $pageTitle
    ): ?Title {
        $pageId = $pageTitle->getArticleID();
        if ( !$pageId ) {
            return null;
        }

        try {
            $dbr = $services->getConnectionProvider()->getReplicaDatabase();
            $image = $dbr->newSelectQueryBuilder()
                ->select( 'pp_value' )
                ->from( 'page_props' )
                ->where( [
                    'pp_propname' => 'page_image_free',
                    'pp_page' => (int)$pageId,
                ] )
                ->caller( __METHOD__ )
                ->fetchField();
        } catch ( \Throwable $e ) {
            return null;
        }

        if ( !is_string( $image ) || $image === '' ) {
            return null;
        }

        $fileTitle = Title::newFromText( $image, NS_FILE );
        if ( !$fileTitle || !$fileTitle->inNamespace( NS_FILE ) ) {
            return null;
        }

        $file = self::resolveRepoFile( $services, $fileTitle );
        return ( $file && $file->exists() ) ? $fileTitle : null;
    }

    public static function resolveSearchResultThumbnailFileTitle(
        \MediaWiki\Search\Entity\SearchResultThumbnail $thumbnail
    ): ?Title {
        $name = $thumbnail->getName();
        if ( is_string( $name ) && $name !== '' ) {
            $title = Title::newFromText( $name, NS_FILE );
            if ( $title && $title->inNamespace( NS_FILE ) ) {
                return $title;
            }
        }

        $url = $thumbnail->getUrl();
        if ( !is_string( $url ) || $url === '' ) {
            return null;
        }

        $dbKey = self::extractImageDbKeyFromImgAuthUrl( $url );
        if ( !$dbKey ) {
            $dbKey = self::extractImageDbKeyFromUrl( $url );
        }

        if ( !$dbKey ) {
            return null;
        }

        $title = Title::newFromText( $dbKey, NS_FILE );
        return ( $title && $title->inNamespace( NS_FILE ) ) ? $title : null;
    }

    public static function getFilteredSearchResultThumbnail(
        $pageIdentity,
        ?string $fileName,
        \MediaWiki\Search\Entity\SearchResultThumbnail $thumbnail,
        ?int $size = null
    ): ?\MediaWiki\Search\Entity\SearchResultThumbnail {
        $services = MediaWikiServices::getInstance();
        $user = RequestContext::getMain()->getUser();
        if ( !( $user instanceof User ) ) {
            return null;
        }

        $pageTitle = $pageIdentity instanceof \MediaWiki\Page\PageIdentity
            ? Title::newFromPageIdentity( $pageIdentity )
            : null;

        $fileTitle = null;
        if ( is_string( $fileName ) && $fileName !== '' ) {
            $fileTitle = Title::newFromText( $fileName, NS_FILE );
            if ( !$fileTitle || !$fileTitle->inNamespace( NS_FILE ) ) {
                $fileTitle = null;
            }
        }
        if ( !$fileTitle ) {
            $fileTitle = self::resolveSearchResultThumbnailFileTitle( $thumbnail );
        }

        if (
            $fileTitle
            && self::shouldUsePlaceholderReplacement( $services, $fileTitle, $user )
        ) {
            $transformParams = self::sanitizeTransformParams( [
                'width' => (int)( $thumbnail->getWidth() ?: $size ?: 60 ),
                'height' => (int)( $thumbnail->getHeight() ?? 0 ),
            ] );

            return self::buildSearchResultThumbnailReplacement(
                $services,
                $thumbnail,
                self::buildProxyUrlForFileTitle( $fileTitle, $transformParams ),
                $transformParams
            );
        }

        if ( $pageTitle instanceof Title ) {
            $pageRequirements = self::getContentTitleVisibilityRequirements( $pageTitle );
            if (
                self::hasAnyNsfwRequirements( $pageRequirements )
                && !self::userMeetsNsfwRequirements( $services, $user, $pageRequirements )
            ) {
                $transformParams = self::sanitizeTransformParams( [
                    'width' => (int)( $thumbnail->getWidth() ?: $size ?: 60 ),
                    'height' => (int)( $thumbnail->getHeight() ?? 0 ),
                ] );
                $placeholderUrl = self::resolvePlaceholderThumbnailUrl( $services, $transformParams );

                if ( $placeholderUrl ) {
                    return self::buildSearchResultThumbnailReplacement(
                        $services,
                        $thumbnail,
                        $placeholderUrl,
                        $transformParams
                    );
                }
            }
        }

        return null;
    }

    private static function buildSearchResultThumbnailReplacement(
        MediaWikiServices $services,
        \MediaWiki\Search\Entity\SearchResultThumbnail $thumbnail,
        string $url,
        array $transformParams
    ): ?\MediaWiki\Search\Entity\SearchResultThumbnail {
        $placeholderTitle = self::resolvePlaceholderTitle( $services );
        $placeholderFile = self::resolvePlaceholderFile( $services );
        if ( !$placeholderTitle || !$placeholderFile ) {
            return null;
        }

        [ $width, $height ] = self::resolvePlaceholderDimensions( $services, $transformParams );

        return new \MediaWiki\Search\Entity\SearchResultThumbnail(
            $placeholderFile->getMimeType(),
            $thumbnail->getSize(),
            $width ?: $thumbnail->getWidth(),
            $height ?: $thumbnail->getHeight(),
            $thumbnail->getDuration(),
            $url,
            $placeholderTitle->getDBkey()
        );
    }

    private static function resolvePlaceholderThumbnailUrl(
        MediaWikiServices $services,
        array $transformParams
    ): ?string {
        $placeholderFile = self::resolvePlaceholderFile( $services );
        if ( !$placeholderFile ) {
            return null;
        }

        if ( $transformParams !== [] ) {
            $thumb = $placeholderFile->transform( $transformParams );
            if ( $thumb && !$thumb->isError() && $thumb->getUrl() ) {
                return self::expandUrl( $services, $thumb->getUrl(), PROTO_RELATIVE );
            }
        }

        return self::resolvePlaceholderUrl( $services );
    }

    /* ============================================================
     *  PAGEIMAGES
     * ========================================================== */

    public static function getFilteredPageImagesApiValues(
        string $fileName,
        int $thumbSize,
        ?string $lang,
        bool $includeThumbnail,
        bool $includeOriginal,
        bool $includeName
    ): ?array {
        $services = MediaWikiServices::getInstance();
        $fileTitle = Title::newFromText( $fileName, NS_FILE );
        if ( !$fileTitle || !$fileTitle->inNamespace( NS_FILE ) ) {
            return null;
        }

        $user = RequestContext::getMain()->getUser();
        if (
            !( $user instanceof User )
            || !self::shouldUsePlaceholderReplacement( $services, $fileTitle, $user )
        ) {
            return null;
        }

        $placeholderTitle = self::resolvePlaceholderTitle( $services );
        $placeholderFile = self::resolvePlaceholderFile( $services );
        if ( !$placeholderTitle || !$placeholderFile ) {
            return [];
        }

        $vals = [];
        if ( $includeThumbnail ) {
            $width = max( 1, $thumbSize );
            $transformParams = [ 'width' => $width ];
            $source = self::expandUrl(
                $services,
                self::buildProxyUrlForFileTitle( $fileTitle, $transformParams ),
                PROTO_CURRENT
            );

            if ( $source ) {
                [ $reportedWidth, $reportedHeight ] = self::resolvePlaceholderDimensions(
                    $services,
                    $transformParams
                );
                $vals['thumbnail'] = [
                    'source' => $source,
                    'width' => $reportedWidth,
                    'height' => $reportedHeight,
                ];
            }
        }

        if ( $includeOriginal ) {
            $source = self::expandUrl(
                $services,
                self::buildProxyUrlForFileTitle( $fileTitle ),
                PROTO_CURRENT
            );

            if ( $source ) {
                $vals['original'] = [
                    'source' => $source,
                    'width' => (int)$placeholderFile->getWidth(),
                    'height' => (int)$placeholderFile->getHeight(),
                ];
            }
        }

        if ( $includeName ) {
            $vals['pageimage'] = $placeholderTitle->getDBkey();
        }

        return $vals;
    }

    private static function filterPageImagesOpenGraphMeta(
        OutputPage $out,
        MediaWikiServices $services,
        User $user
    ): void {
        $meta = self::buildFilteredPageImagesMetaForOutput( $out, $services, $user );
        if ( !$meta ) {
            return;
        }

        self::replaceOpenGraphImageMetaTags(
            $out,
            $meta['source'],
            $meta['width'],
            $meta['height']
        );
    }

    private static function buildFilteredPageImagesMetaForOutput(
        OutputPage $out,
        MediaWikiServices $services,
        User $user
    ): ?array {
        $pageImageTitle = self::resolvePageImagesFileTitleForPage( $services, $out->getTitle() );
        if (
            !$pageImageTitle
            || !self::shouldUsePlaceholderReplacement( $services, $pageImageTitle, $user )
        ) {
            return null;
        }

        $source = self::expandUrl(
            $services,
            self::buildProxyUrlForFileTitle( $pageImageTitle, [ 'width' => 1200, 'height' => 1200 ] ),
            PROTO_CANONICAL
        );
        if ( !$source ) {
            return null;
        }

        [ $width, $height ] = self::resolvePlaceholderDimensions(
            $services,
            [ 'width' => 1200, 'height' => 1200 ]
        );

        return [
            'source' => $source,
            'width' => $width,
            'height' => $height,
        ];
    }

    private static function resolvePageImagesFileTitleForPage(
        MediaWikiServices $services,
        ?Title $pageTitle
    ): ?Title {
        if ( !$pageTitle instanceof Title ) {
            return null;
        }

        if ( $pageTitle->inNamespace( NS_FILE ) ) {
            $file = self::resolveRepoFile( $services, $pageTitle );
            return ( $file && $file->exists() ) ? $pageTitle : null;
        }

        $pageId = $pageTitle->getArticleID();
        if ( !$pageId ) {
            return null;
        }

        try {
            $dbr = $services->getConnectionProvider()->getReplicaDatabase();
            $res = $dbr->newSelectQueryBuilder()
                ->select( [ 'pp_propname', 'pp_value' ] )
                ->from( 'page_props' )
                ->where( [
                    'pp_page' => (int)$pageId,
                    'pp_propname' => [ 'page_image', 'page_image_free' ],
                ] )
                ->caller( __METHOD__ )
                ->fetchResultSet();
        } catch ( \Throwable $e ) {
            return null;
        }

        $pageImages = [];
        foreach ( $res as $row ) {
            if ( is_string( $row->pp_value ) && $row->pp_value !== '' ) {
                $pageImages[$row->pp_propname] = $row->pp_value;
            }
        }

        $image = $pageImages['page_image'] ?? $pageImages['page_image_free'] ?? null;
        if ( !is_string( $image ) || $image === '' ) {
            return null;
        }

        $fileTitle = Title::newFromText( $image, NS_FILE );
        if ( !$fileTitle || !$fileTitle->inNamespace( NS_FILE ) ) {
            return null;
        }

        $file = self::resolveRepoFile( $services, $fileTitle );
        return ( $file && $file->exists() ) ? $fileTitle : null;
    }

    private static function replaceOpenGraphImageMetaTags(
        OutputPage $out,
        string $source,
        int $width,
        int $height
    ): void {
        $filteredMetaTags = array_values( array_filter(
            $out->getMetaTags(),
            static function ( array $tag ): bool {
                $name = $tag[0] ?? null;
                return !in_array( $name, [ 'og:image', 'og:image:width', 'og:image:height' ], true );
            }
        ) );

        $setMetaTags = \Closure::bind(
            static function ( OutputPage $outputPage, array $metaTags ): void {
                $outputPage->mMetatags = $metaTags;
            },
            null,
            OutputPage::class
        );
        $setMetaTags( $out, $filteredMetaTags );

        $out->addMeta( 'og:image', $source );
        if ( $width > 0 ) {
            $out->addMeta( 'og:image:width', (string)$width );
        }
        if ( $height > 0 ) {
            $out->addMeta( 'og:image:height', (string)$height );
        }
    }

    private static function isOpenGraphImageMetaTagHtml( string $tag ): bool {
        $tag = strtolower( $tag );
        return str_contains( $tag, '<meta' )
            && (
                str_contains( $tag, 'property="og:image' )
                || str_contains( $tag, "property='og:image" )
                || str_contains( $tag, 'name="og:image' )
                || str_contains( $tag, "name='og:image" )
            );
    }

    private static function resolvePlaceholderDimensions(
        MediaWikiServices $services,
        array $transformParams = []
    ): array {
        $placeholderFile = self::resolvePlaceholderFile( $services );
        if ( !$placeholderFile ) {
            return [ 0, 0 ];
        }

        if ( $transformParams !== [] ) {
            $thumb = $placeholderFile->transform( $transformParams );
            if ( $thumb && !$thumb->isError() ) {
                $reportedSize = $thumb->fileIsSource() ? $placeholderFile : $thumb;
                return [
                    (int)$reportedSize->getWidth(),
                    (int)$reportedSize->getHeight(),
                ];
            }
        }

        return [
            (int)$placeholderFile->getWidth(),
            (int)$placeholderFile->getHeight(),
        ];
    }

    private static function expandUrl( MediaWikiServices $services, string $url, $proto ): ?string {
        $expanded = $services->getUrlUtils()->expand( $url, $proto );
        return is_string( $expanded ) && $expanded !== '' ? $expanded : null;
    }


    /* ============================================================
     *  SPECIAL PAGES (LISTFILES / NEWFILES)
     * ========================================================== */

    public static function onSpecialPageBeforeExecute( SpecialPage $special, ?string $subPage ): void {
        $name = strtolower( $special->getName() );
        if ( !in_array( $name, [ 'listfiles', 'newfiles', 'newimages' ], true ) ) {
            return;
        }

        // Special file listings should render normal file HTML. Any NSFW
        // replacement now happens later through the NSFW proxy endpoint.
    }

    /* ============================================================
     *  PREFERENCES
     * ========================================================== */
    public static function onSpecialSearchResultsAppend( $specialSearch, OutputPage $out, $term ): void {
        $html = self::getOutputHtml( $out );
        if ( !is_string( $html ) || $html === '' ) {
            return;
        }

        $rewritten = self::rewriteNsfwImgAuthUrlsInHtml( $html );
        $rewritten = self::rewriteNsfwMediaUrlsInHtml( $rewritten );
        $rewritten = self::rewriteNsfwDomAttributesInHtml( $rewritten );

        if ( $rewritten === $html ) {
            return;
        }

        $out->clearHTML();
        $out->addHTML( $rewritten );
    }

    public static function onSearchResultProvideThumbnail(
        array $pageIdentities,
        array &$thumbnails,
        ?int $size = null
    ): void {
        if ( $thumbnails === [] ) {
            return;
        }

        foreach ( $thumbnails as $pageId => $thumbnail ) {
            if ( !$thumbnail instanceof \MediaWiki\Search\Entity\SearchResultThumbnail ) {
                continue;
            }

            $fileTitle = self::resolveSearchResultThumbnailFileTitle( $thumbnail );
            $filteredThumbnail = self::getFilteredSearchResultThumbnail(
                $pageIdentities[$pageId] ?? null,
                $fileTitle ? $fileTitle->getDBkey() : null,
                $thumbnail,
                $size
            );
            if ( $filteredThumbnail ) {
                $thumbnails[$pageId] = $filteredThumbnail;
            }
        }
    }

    public static function onShowSearchHit(
        $searchPage,
        $result,
        $terms,
        &$link,
        &$redirect,
        &$section,
        &$extract,
        $score,
        &$size,
        &$date,
        &$related,
        &$html
    ): void {
        if ( !is_string( $link ) || $link === '' || !method_exists( $result, 'getTitle' ) ) {
            return;
        }

        $pageTitle = $result->getTitle();
        if ( !$pageTitle instanceof Title ) {
            return;
        }

        $services = MediaWikiServices::getInstance();
        $user = RequestContext::getMain()->getUser();
        if ( !$user instanceof User ) {
            return;
        }

        $replacementUrl = self::resolveSearchThumbReplacementUrl( $services, $pageTitle, $user );
        if ( !$replacementUrl ) {
            return;
        }

        $quotedUrl = htmlspecialchars( $replacementUrl, ENT_QUOTES );

        if ( preg_match( '/<img\b[^>]*\bclass="[^"]*\bmw-search-result-thumb\b[^"]*"[^>]*>/i', $link, $m ) ) {
            $originalTag = $m[0];
            $replacementTag = preg_replace(
                '/\bsrc="[^"]*"/i',
                'src="' . $quotedUrl . '"',
                $originalTag,
                1
            );

            if ( is_string( $replacementTag ) ) {
                $link = preg_replace(
                    '/<img\b[^>]*\bclass="[^"]*\bmw-search-result-thumb\b[^"]*"[^>]*>/i',
                    $replacementTag,
                    $link,
                    1
                );
                return;
            }
        }

        $link = '<img class="mw-search-result-thumb" src="' . $quotedUrl . '" />' . $link;
    }

    public static function onGetPreferences( $user, &$preferences ): bool {
        $services = MediaWikiServices::getInstance();

        $canSeeNSFW        = self::isUserAllowedToUnblur( $services, $user );
        $defaultBirthdate  = self::getUserBirthDateDefault( $services, $user );

        $preferences[self::OPT_BIRTHDATE] = [
            'type' => 'date',
            'label-message' => 'nsfwblur-birthdate-label',
            'help-message'  => 'nsfwblur-birthdate-help',
            'section'       => 'personal/info',
            'default'       => $defaultBirthdate,
            'min'           => '1900-01-01',
            'max'           => date( 'Y-m-d' ),
            'validation-callback' => [ self::class, 'validateBirthDatePreference' ],
        ];

        $preferences[self::OPT_UNBLUR_GORE] = [
            'type'          => 'toggle',
            'label-message' => 'tog-nsfwblurred-gore',
            'section'       => 'rendering/files',
            'default'       => self::getEffectiveUserCategoryPreference( $services, $user, self::PREF_GORE ),
            'disabled'      => !$canSeeNSFW,
            'help-message'  => !$canSeeNSFW ? 'nsfwblur-pref-nsfw-access' : null,
            'validation-callback' => [ self::class, 'validateNsfwUnblurPreference' ],
        ];

        $preferences[self::OPT_UNBLUR_SEXUAL] = [
            'type'          => 'toggle',
            'label-message' => 'tog-nsfwblurred-sexual',
            'section'       => 'rendering/files',
            'default'       => self::getEffectiveUserCategoryPreference( $services, $user, self::PREF_SEXUAL ),
            'disabled'      => !$canSeeNSFW,
            'help-message'  => !$canSeeNSFW ? 'nsfwblur-pref-nsfw-access' : null,
            'validation-callback' => [ self::class, 'validateNsfwUnblurPreference' ],
        ];

        return true;
    }

    /* ============================================================
     *  FILE HTML OUTPUT
     *  NOTE: MW 1.43 passes MORE parameters than older MW.
     *        This signature is intentionally compatible.
     * ========================================================== */

    public static function onImageBeforeProduceHTML(
        &$linker,
        &$title,
        &$file,
        array &$frameParams,
        array &$handlerParams,
        &$time,
        &$res,
        &$parser,
        &$query,
        &$widthOption,
        ...$more
    ): bool {
        $fileTitle = self::normalizeRenderedFileTitle( $title, $file );
        if ( !$fileTitle || !self::isFileTitleMarkedNSFW( $fileTitle ) ) {
            return true;
        }

        if (
            isset( $frameParams['link-url'] )
            && is_string( $frameParams['link-url'] )
            && $file instanceof File
            && self::shouldRewriteRenderedFileHref( $frameParams['link-url'], $file )
        ) {
            $frameParams['link-url'] = self::buildProxyUrlForFileTitle(
                $fileTitle,
                self::extractTransformParamsFromHandlerParams( $handlerParams )
            );
        }

        return true;
    }

    /* ============================================================
     *  FILE PROXY (SERVER-SIDE FILE REPLACEMENT)
     * ========================================================== */

    public static function onImgAuthBeforeStream( &$title, &$path, &$name, &$result ): bool {
        // Public wikis do not reliably invoke this hook for file delivery.
        // This extension uses its own proxy endpoint instead.
        return true;
    }

    public static function handleProxyRequest(): void {
        $services = MediaWikiServices::getInstance();
        $fileTitle = self::resolveProxyRequestTitle();
        if ( !$fileTitle || !$fileTitle->inNamespace( NS_FILE ) ) {
            self::emitProxyError( 404, 'File not found.' );
        }

        $requestedFile = self::resolveRepoFile( $services, $fileTitle );
        if ( !$requestedFile || !$requestedFile->exists() ) {
            self::emitProxyError( 404, 'File not found.' );
        }

        $user = RequestContext::getMain()->getUser();
        $isNsfw = self::isFileTitleMarkedNSFW( $fileTitle );
        $streamFile = $requestedFile;

        if (
            $user instanceof User
            && self::shouldUsePlaceholderReplacement( $services, $fileTitle, $user )
        ) {
            $placeholderFile = self::resolvePlaceholderFile( $services );
            if ( !$placeholderFile ) {
                self::emitProxyError( 404, 'NSFW placeholder file is not configured or missing.', $isNsfw );
            }
            $streamFile = $placeholderFile;
        }

        self::streamProxyFile(
            $streamFile,
            self::extractTransformParamsFromRequest(),
            $isNsfw
        );
    }
    private static function rewriteNsfwDomAttributesInHtml( string $html ): string {
        if ( $html === '' ) {
            return $html;
        }

        if (
            stripos( $html, 'img_auth.php' ) === false
            && stripos( $html, '/images/' ) === false
            && stripos( $html, '/thumb/' ) === false
        ) {
            return $html;
        }

        $nsfwFileTitles = self::collectNsfwFileTitlesFromHtml( $html );

        $previousUseInternalErrors = libxml_use_internal_errors( true );
        $dom = new \DOMDocument();

        $wrapperId = 'nsfwfilter-html-wrapper';
        $encoded = mb_convert_encoding(
            '<div id="' . $wrapperId . '">' . $html . '</div>',
            'HTML-ENTITIES',
            'UTF-8'
        );

        $loaded = $dom->loadHTML(
            $encoded,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        if ( !$loaded ) {
            libxml_clear_errors();
            libxml_use_internal_errors( $previousUseInternalErrors );
            return $html;
        }

        $xpath = new \DOMXPath( $dom );

        /** @var \DOMElement $element */
        foreach ( $xpath->query( '//*[@src or @srcset or @data-src or @href or @style]' ) as $element ) {
            foreach ( [ 'src', 'data-src', 'href' ] as $attr ) {
                if ( !$element->hasAttribute( $attr ) ) {
                    continue;
                }

                $original = $element->getAttribute( $attr );
                $rewritten = self::rewriteNsfwImgAuthAttributeUrl( $attr, $original );

                if ( $rewritten === $original && $nsfwFileTitles !== [] ) {
                    $rewritten = self::rewriteNsfwMediaAttributeUrl(
                        $attr,
                        $original,
                        $nsfwFileTitles
                    );
                }

                if ( $rewritten !== $original ) {
                    $element->setAttribute( $attr, $rewritten );
                }
            }

            if ( $element->hasAttribute( 'srcset' ) ) {
                $originalSrcSet = $element->getAttribute( 'srcset' );
                $rewrittenSrcSet = self::rewriteNsfwImgAuthSrcSetAttributeValue( $originalSrcSet );

                if ( $rewrittenSrcSet === $originalSrcSet && $nsfwFileTitles !== [] ) {
                    $rewrittenSrcSet = self::rewriteNsfwSrcSetAttributeValue(
                        $originalSrcSet,
                        $nsfwFileTitles
                    );
                }

                if ( $rewrittenSrcSet !== $originalSrcSet ) {
                    $element->setAttribute( 'srcset', $rewrittenSrcSet );
                }
            }

            if ( $element->hasAttribute( 'style' ) && $nsfwFileTitles !== [] ) {
                $originalStyle = $element->getAttribute( 'style' );
                $rewrittenStyle = self::rewriteNsfwBackgroundImageStyleUrls(
                    $originalStyle,
                    $nsfwFileTitles
                );

                if ( $rewrittenStyle !== $originalStyle ) {
                    $element->setAttribute( 'style', $rewrittenStyle );
                }
            }
        }

        $wrapper = $dom->getElementById( $wrapperId );
        if ( !$wrapper ) {
            libxml_clear_errors();
            libxml_use_internal_errors( $previousUseInternalErrors );
            return $html;
        }

        $output = '';
        foreach ( $wrapper->childNodes as $child ) {
            $output .= $dom->saveHTML( $child );
        }

        libxml_clear_errors();
        libxml_use_internal_errors( $previousUseInternalErrors );

        return $output;
    }
    public static function onImagePageFindFile( $page, &$file, &$displayFile ): void {
        $title = method_exists( $page, 'getTitle' ) ? $page->getTitle() : null;
        if ( !$title instanceof Title || !$title->inNamespace( NS_FILE ) ) {
            return;
        }

        $services = MediaWikiServices::getInstance();
        $file = self::resolveRepoFile( $services, $title );
        if ( !$file instanceof File || !$file->exists() ) {
            return;
        }

        $user = RequestContext::getMain()->getUser();
        if (
            !$user instanceof User
            || !self::shouldUsePlaceholderReplacement( $services, $title, $user )
        ) {
            return;
        }

        $placeholderFile = self::resolvePlaceholderFile( $services );
        if ( $placeholderFile ) {
            $displayFile = $placeholderFile;
        }
    }

    public static function onImgAuthModifyHeaders( $title, &$headers ): void {
        $fileTitle = Title::newFromLinkTarget( $title );
        if ( !$fileTitle || !$fileTitle->inNamespace( NS_FILE ) ) {
            return;
        }

        if ( self::isFileTitleMarkedNSFW( $fileTitle ) ) {
            self::addNsfwRobotsHeader( $headers );
        }
    }


    /* ============================================================
     *  CACHE / DEFAULT OPTIONS
     * ========================================================== */

    public static function onPageRenderingHash( &$confstr, $user, $optionsUsed = [] ): void {
        try {
            $services = MediaWikiServices::getInstance();
            if ( $user instanceof User ) {
                $prefs = self::getEffectiveUserNsfwPreferences( $services, $user );
                $confstr .= '!nsfw:g' . (int)$prefs[self::PREF_GORE]
                    . 's' . (int)$prefs[self::PREF_SEXUAL];
            }
        } catch ( \Throwable $e ) {
            // ignore
        }
    }

    public static function onUserGetDefaultOptions( &$defaultOptions ): void {
        $defaultOptions[self::OPT_UNBLUR] = 0;
    }

    public static function onUserSaveOptions( $user, &$options ): void {
        if ( $user instanceof User ) {
            $services = MediaWikiServices::getInstance();
            $birthDate = array_key_exists( self::OPT_BIRTHDATE, $options )
                ? self::normalizeBirthDateValue( (string)$options[self::OPT_BIRTHDATE] )
                : self::getUserBirthDateDefault( $services, $user );
            $birthDateIsAdult = $birthDate !== '' && $birthDate !== null
                && self::isBirthDateAtLeastMinimumAge( $birthDate );

            if ( $birthDateIsAdult ) {
                $userGroupManager = $services->getUserGroupManager();
                if ( !in_array( 'adultreader', $userGroupManager->getUserGroups( $user ), true ) ) {
                    $userGroupManager->addUserToGroup( $user, 'adultreader' );
                }
            }

            if ( !$birthDateIsAdult && !self::isUserAllowedToUnblur( $services, $user ) ) {
                $options[self::OPT_UNBLUR] = 0;
                $options[self::OPT_UNBLUR_GORE] = 0;
                $options[self::OPT_UNBLUR_SEXUAL] = 0;
                return;
            }

            $effectivePreferences = self::getEffectiveUserNsfwPreferences( $services, $user );
            foreach ( self::getAllNsfwPreferenceFlags() as $flag ) {
                $optionName = self::getOptionNameForNsfwPreferenceFlag( $flag );
                if ( $optionName !== null && array_key_exists( $optionName, $options ) ) {
                    $effectivePreferences[$flag] = (bool)$options[$optionName];
                }
            }

            foreach ( self::getAllNsfwPreferenceFlags() as $flag ) {
                $optionName = self::getOptionNameForNsfwPreferenceFlag( $flag );
                if ( $optionName !== null ) {
                    $options[$optionName] = $effectivePreferences[$flag] ? 1 : 0;
                }
            }

            $options[self::OPT_UNBLUR] = self::userHasEnabledAllNsfwPreferences( $effectivePreferences ) ? 1 : 0;
        }
    }

    /* ============================================================
     *  AGE / BIRTHDATE
     * ========================================================== */

    private static function getUserBirthDateOption( MediaWikiServices $services, User $user ): ?string {
        $lookup = $services->getUserOptionsLookup();

        $val = $lookup->getOption( $user, self::OPT_BIRTHDATE, '' );
        if ( $val !== '' ) {
            return $val;
        }

        return $lookup->getOption( $user, self::OPT_BIRTHDATE_LEGACY, '' ) ?: null;
    }

    private static function getUserBirthDateDefault( MediaWikiServices $services, User $user ): string {
        $val = self::getUserBirthDateOption( $services, $user );
        if ( !$val ) {
            return '';
        }
        return self::normalizeBirthDateValue( $val ) ?? '';
    }

    private static function isUserOldEnoughForNSFW( MediaWikiServices $services, User $user ): bool {
        $date = self::getUserBirthDateDefault( $services, $user );
        if ( !$date ) {
            return false;
        }
        return self::isBirthDateAtLeastMinimumAge( $date );
    }

    private static function userHasUnblurRight( User $user ): bool {
        return $user->isAllowed( self::UNBLUR_RIGHT );
    }

    private static function isUserAllowedToUnblur( MediaWikiServices $services, User $user ): bool {
        return $user->isRegistered()
            && self::userHasUnblurRight( $user )
            && self::isUserOldEnoughForNSFW( $services, $user );
    }

    private static function userWantsUnblur( MediaWikiServices $services, User $user ): bool {
        return self::userHasEnabledAllNsfwPreferences(
            self::getEffectiveUserNsfwPreferences( $services, $user )
        );
    }

    private static function shouldUsePlaceholderReplacement(
        MediaWikiServices $services,
        Title $fileTitle,
        User $user
    ): bool {
        if ( !$fileTitle->inNamespace( NS_FILE ) ) {
            return false;
        }

        $requirements = self::getFileTitleVisibilityRequirements( $fileTitle );
        return self::hasAnyNsfwRequirements( $requirements )
            && !self::userMeetsNsfwRequirements( $services, $user, $requirements );
    }

    /* ============================================================
     *  NSFW DETECTION (MARKER OR CATEGORY)
     * ========================================================== */

    public static function isFileTitleMarkedNSFW( Title $fileTitle ): bool {
        return self::hasAnyNsfwRequirements(
            self::getFileTitleVisibilityRequirements( $fileTitle )
        );
    }



    public static function isContentTitleMarkedNSFW( Title $title ): bool {
        return self::hasAnyNsfwRequirements(
            self::getContentTitleVisibilityRequirements( $title )
        );
    }

    private static function getAllNsfwPreferenceFlags(): array {
        return [
            self::PREF_GORE,
            self::PREF_SEXUAL,
        ];
    }

    private static function buildEmptyNsfwRequirements(): array {
        return [
            self::PREF_GORE => false,
            self::PREF_SEXUAL => false,
        ];
    }

    private static function buildAllNsfwRequirements(): array {
        return [
            self::PREF_GORE => true,
            self::PREF_SEXUAL => true,
        ];
    }

    private static function hasAnyNsfwRequirements( array $requirements ): bool {
        foreach ( self::getAllNsfwPreferenceFlags() as $flag ) {
            if ( !empty( $requirements[$flag] ) ) {
                return true;
            }
        }

        return false;
    }

    private static function userHasEnabledAllNsfwPreferences( array $preferences ): bool {
        foreach ( self::getAllNsfwPreferenceFlags() as $flag ) {
            if ( empty( $preferences[$flag] ) ) {
                return false;
            }
        }

        return true;
    }

    private static function getOptionNameForNsfwPreferenceFlag( string $flag ): ?string {
        switch ( $flag ) {
            case self::PREF_GORE:
                return self::OPT_UNBLUR_GORE;
            case self::PREF_SEXUAL:
                return self::OPT_UNBLUR_SEXUAL;
            default:
                return null;
        }
    }

    private static function getStoredUserCategoryPreference(
        MediaWikiServices $services,
        User $user,
        string $flag
    ): ?bool {
        $optionName = self::getOptionNameForNsfwPreferenceFlag( $flag );
        if ( $optionName === null ) {
            return null;
        }

        $value = $services->getUserOptionsLookup()->getOption( $user, $optionName, null );
        if ( $value === null || $value === '' ) {
            return null;
        }

        return (bool)$value;
    }

    private static function getLegacyUserUnblurPreference( MediaWikiServices $services, User $user ): bool {
        return (bool)$services->getUserOptionsLookup()->getOption( $user, self::OPT_UNBLUR, 0 );
    }

    private static function getEffectiveUserCategoryPreference(
        MediaWikiServices $services,
        User $user,
        string $flag
    ): bool {
        if (
            !$user->isRegistered()
            || !self::isUserAllowedToUnblur( $services, $user )
        ) {
            return false;
        }

        $storedValue = self::getStoredUserCategoryPreference( $services, $user, $flag );
        if ( $storedValue !== null ) {
            return $storedValue;
        }

        return self::getLegacyUserUnblurPreference( $services, $user );
    }

    private static function getEffectiveUserNsfwPreferences(
        MediaWikiServices $services,
        User $user
    ): array {
        $preferences = self::buildEmptyNsfwRequirements();
        foreach ( self::getAllNsfwPreferenceFlags() as $flag ) {
            $preferences[$flag] = self::getEffectiveUserCategoryPreference( $services, $user, $flag );
        }

        return $preferences;
    }

    private static function userMeetsNsfwRequirements(
        MediaWikiServices $services,
        User $user,
        array $requirements
    ): bool {
        if ( !self::hasAnyNsfwRequirements( $requirements ) ) {
            return true;
        }

        if (
            !$user->isRegistered()
            || !self::isUserAllowedToUnblur( $services, $user )
        ) {
            return false;
        }

        $preferences = self::getEffectiveUserNsfwPreferences( $services, $user );
        foreach ( self::getAllNsfwPreferenceFlags() as $flag ) {
            if ( !empty( $requirements[$flag] ) && empty( $preferences[$flag] ) ) {
                return false;
            }
        }

        return true;
    }

    private static function getFileTitleVisibilityRequirements( Title $fileTitle ): array {
        static $memo = [];

        if ( !$fileTitle->inNamespace( NS_FILE ) ) {
            return self::buildEmptyNsfwRequirements();
        }

        $cacheKey = $fileTitle->getPrefixedDBkey();
        if ( array_key_exists( $cacheKey, $memo ) ) {
            return $memo[$cacheKey];
        }

        if (
            self::fileTitleHasNsfwMarker( $fileTitle )
            || self::titleHasCategory( $fileTitle, self::NSFW_FILE_CATEGORY_DBKEY )
            || self::titleHasCategory( $fileTitle, self::NSFW_CATEGORY_DBKEY )
        ) {
            return $memo[$cacheKey] = self::buildAllNsfwRequirements();
        }

        $requirements = self::buildEmptyNsfwRequirements();
        if ( self::titleHasCategory( $fileTitle, self::NSFW_GORE_CATEGORY_DBKEY ) ) {
            $requirements[self::PREF_GORE] = true;
        }

        if ( self::titleHasCategory( $fileTitle, self::NSFW_SEXUAL_CATEGORY_DBKEY ) ) {
            $requirements[self::PREF_SEXUAL] = true;
        }

        return $memo[$cacheKey] = $requirements;
    }

    private static function getContentTitleVisibilityRequirements( Title $title ): array {
        static $memo = [];

        if ( !$title || !$title->isContentPage() ) {
            return self::buildEmptyNsfwRequirements();
        }

        $cacheKey = $title->getPrefixedDBkey();
        if ( array_key_exists( $cacheKey, $memo ) ) {
            return $memo[$cacheKey];
        }

        if ( self::titleHasCategory( $title, self::NSFW_CATEGORY_DBKEY ) ) {
            return $memo[$cacheKey] = self::buildAllNsfwRequirements();
        }

        return $memo[$cacheKey] = self::buildEmptyNsfwRequirements();
    }

    private static function fileTitleHasNsfwMarker( Title $fileTitle ): bool {
        static $memo = [];

        if ( !$fileTitle->inNamespace( NS_FILE ) ) {
            return false;
        }

        $cacheKey = $fileTitle->getPrefixedDBkey();
        if ( array_key_exists( $cacheKey, $memo ) ) {
            return $memo[$cacheKey];
        }

        $services = MediaWikiServices::getInstance();

        try {
            $rev = $services->getRevisionLookup()->getRevisionByTitle( $fileTitle );
            if ( $rev ) {
                $content = $rev->getContent( SlotRecord::MAIN, RevisionRecord::FOR_PUBLIC );
                if ( $content ) {
                    $handler = $services->getContentHandlerFactory()
                        ->getContentHandler( $content->getModel() );

                    $text = $handler->serializeContent( $content );
                    if ( is_string( $text ) && strpos( $text, self::NSFW_MARKER ) !== false ) {
                        return $memo[$cacheKey] = true;
                    }
                }
            }
        } catch ( \Throwable $e ) {
            // Never break rendering because a parser/text read failed
        }

        return $memo[$cacheKey] = false;
    }

    private static function titleHasCategory( Title $title, string $categoryDbKey ): bool {
        static $memo = [];

        $pageId = $title->getArticleID();
        if ( !$pageId ) {
            return false;
        }

        $normalizedCategoryDbKey = self::normalizeCategoryDbKey( $categoryDbKey );
        if ( $normalizedCategoryDbKey === null ) {
            return false;
        }

        $cacheKey = $title->getPrefixedDBkey() . '|' . $normalizedCategoryDbKey;
        if ( array_key_exists( $cacheKey, $memo ) ) {
            return $memo[$cacheKey];
        }

        try {
            $services = MediaWikiServices::getInstance();
            $dbr = $services->getConnectionProvider()->getReplicaDatabase();
            $row = $dbr->newSelectQueryBuilder()
                ->select( [ 'cl_from' ] )
                ->from( 'categorylinks' )
                ->join( 'linktarget', 'lt', 'lt.lt_id = cl_target_id' )
                ->where( [
                    'cl_from' => (int)$pageId,
                    'lt.lt_namespace' => NS_CATEGORY,
                    'lt.lt_title' => $normalizedCategoryDbKey,
                ] )
                ->limit( 1 )
                ->caller( __METHOD__ )
                ->fetchRow();

            return $memo[$cacheKey] = (bool)$row;
        } catch ( \Throwable $e ) {
            return $memo[$cacheKey] = false;
        }
    }

    private static function normalizeCategoryDbKey( string $categoryName ): ?string {
        $categoryName = trim( str_replace( '_', ' ', $categoryName ) );
        if ( $categoryName === '' ) {
            return null;
        }

        $categoryTitle = Title::newFromText( ltrim( $categoryName, ':' ), NS_CATEGORY );
        if ( !$categoryTitle || !$categoryTitle->inNamespace( NS_CATEGORY ) ) {
            return null;
        }

        return $categoryTitle->getDBkey();
    }

    private static function applyFilePagePlaceholderClass( OutputPage $out, bool $userWantsUnblur ): void {
        if ( $userWantsUnblur ) {
            return;
        }

        $title = $out->getTitle();
        if ( !$title instanceof Title || !$title->inNamespace( NS_FILE ) ) {
            return;
        }

        $services = MediaWikiServices::getInstance();
        if ( !self::shouldUsePlaceholderReplacement( $services, $title, $out->getUser() ) ) {
            return;
        }

        $out->addBodyClasses( 'nsfw-filepage-placeholder' );
        $out->addJsConfigVars( [ 'wgNSFWFilePage' => true ] );
    }

    /* ============================================================
     *  VALIDATORS
     * ========================================================== */

    public static function validateBirthDatePreference( $value ): bool|string {
        if ( $value === '' || $value === null ) {
            return true;
        }

        return self::normalizeBirthDateValue( (string)$value )
            ? true
            : wfMessage( 'nsfwblur-birthdate-invalid' )->text();
    }

    public static function validateNsfwUnblurPreference( $value, $alldata = null, $form = null ): bool|string {
        if ( !$value ) {
            return true;
        }

        $user = ( is_object( $form ) && method_exists( $form, 'getUser' ) ) ? $form->getUser() : null;

        if ( $user instanceof User ) {
            $services = MediaWikiServices::getInstance();
            if ( self::isUserAllowedToUnblur( $services, $user ) ) {
                return true;
            }
        }

        return wfMessage( 'nsfwblur-pref-nsfw-access' )->text();
    }

    private static function resolvePlaceholderFile( MediaWikiServices $services ): ?File {
        $placeholderTitle = self::resolvePlaceholderTitle( $services );
        if ( !$placeholderTitle || self::isFileTitleMarkedNSFW( $placeholderTitle ) ) {
            return null;
        }

        $file = $services->getRepoGroup()->getLocalRepo()->newFile( $placeholderTitle->getDBkey() );
        if ( !$file || !$file->exists() || !$file->getPath() ) {
            return null;
        }

        return $file;
    }

    private static function resolvePlaceholderUrl( MediaWikiServices $services ): ?string {
        $placeholderFile = self::resolvePlaceholderFile( $services );
        if ( !$placeholderFile ) {
            return null;
        }

        $url = $placeholderFile->getUrl();
        return is_string( $url ) && $url !== '' ? $url : null;
    }

    private static function resolvePlaceholderTitle( MediaWikiServices $services ): ?Title {
        $configured = trim( (string)$services->getMainConfig()->get( self::PLACEHOLDER_CONFIG ) );
        if ( $configured === '' ) {
            return null;
        }

        $title = Title::newFromText( ltrim( $configured, ':' ), NS_FILE );
        if ( !$title || !$title->inNamespace( NS_FILE ) ) {
            return null;
        }

        return $title;
    }

    private static function resolveRepoFile( MediaWikiServices $services, Title $fileTitle ): ?File {
        $file = $services->getRepoGroup()->findFile( $fileTitle );
        if ( !$file ) {
            $file = $services->getRepoGroup()->getLocalRepo()->newFile( $fileTitle );
        }

        return $file instanceof File ? $file : null;
    }

    private static function streamProxyFile(
        File $streamFile,
        array $transformParams,
        bool $isNsfwResponse
    ): void {
        $headers = self::buildProxySuccessHeaders( $streamFile->getName(), $isNsfwResponse );

        if ( $transformParams !== [] ) {
            $thumb = $streamFile->transform( $transformParams, File::RENDER_NOW );
            if ( $thumb instanceof \MediaTransformOutput && !$thumb->isError() ) {
                if ( !$thumb->fileIsSource() ) {
                    $rawHeaders = self::buildRawStreamHeaders( $headers );
                    $thumb->streamFileWithStatus( $rawHeaders );
                    exit;
                }
            }
        }

        [ $rawHeaders, $options ] = HTTPFileStreamer::preprocessHeaders( $headers );
        $streamPath = $streamFile->getPath();
        if ( !$streamPath ) {
            self::emitProxyError( 404, 'File is not available for streaming.', $isNsfwResponse );
        }

        $streamFile->getRepo()->streamFileWithStatus( $streamPath, $rawHeaders, $options );
        exit;
    }

    private static function buildProxySuccessHeaders( string $filename, bool $isNsfwResponse ): array {
        $request = RequestContext::getMain()->getRequest();
        $headers = [
            'Cache-Control' => 'private',
            'Vary' => 'Cookie',
        ];

        if ( $isNsfwResponse ) {
            self::addNsfwRobotsHeader( $headers );
        }

        $range = $request->getHeader( 'Range' );
        if ( is_string( $range ) && $range !== '' ) {
            $headers['Range'] = $range;
        }

        $ims = $request->getHeader( 'If-Modified-Since' );
        if ( is_string( $ims ) && $ims !== '' ) {
            $headers['If-Modified-Since'] = $ims;
        }

        if ( $request->getCheck( 'download' ) ) {
            $headers['Content-Disposition'] = 'attachment';
        }

        $cspHeader = ContentSecurityPolicy::getMediaHeader( $filename );
        if ( $cspHeader ) {
            $headers['Content-Security-Policy'] = $cspHeader;
        }

        return $headers;
    }

    private static function buildRawStreamHeaders( array $headers ): array {
        unset( $headers['Range'], $headers['If-Modified-Since'] );

        return array_values( array_map(
            static function ( string $name, string $value ): string {
                return $name . ': ' . $value;
            },
            array_keys( $headers ),
            array_values( $headers )
        ) );
    }

    private static function resolveProxyRequestTitle(): ?Title {
        $request = RequestContext::getMain()->getRequest();

        $titleText = trim( rawurldecode( (string)$request->getVal( 'title', '' ) ) );
        if ( $titleText !== '' ) {
            $title = Title::newFromText( ltrim( $titleText, ':' ) );
            if ( !$title || !$title->inNamespace( NS_FILE ) ) {
                $title = Title::newFromText( ltrim( $titleText, ':' ), NS_FILE );
            }
            if ( $title && $title->inNamespace( NS_FILE ) ) {
                return $title;
            }
        }

        $nameText = trim( rawurldecode( (string)$request->getVal( 'name', $request->getVal( 'file', '' ) ) ) );
        if ( $nameText === '' ) {
            return null;
        }

        $nameText = preg_replace( '/^:?\s*(?:File|Image):/i', '', $nameText );
        $title = Title::newFromText( $nameText, NS_FILE );
        if ( !$title || !$title->inNamespace( NS_FILE ) ) {
            return null;
        }

        return $title;
    }

    private static function extractTransformParamsFromRequest(): array {
        $request = RequestContext::getMain()->getRequest();

        return self::sanitizeTransformParams( [
            'width' => $request->getInt( 'width', 0 ),
            'height' => $request->getInt( 'height', 0 ),
            'page' => $request->getInt( 'page', 0 ),
        ] );
    }

    private static function extractTransformParamsFromHandlerParams( array $handlerParams ): array {
        return self::sanitizeTransformParams( [
            'width' => $handlerParams['width'] ?? 0,
            'height' => $handlerParams['height'] ?? 0,
            'page' => $handlerParams['page'] ?? 0,
        ] );
    }

    private static function extractTransformParamsFromRenderedAttributes( array $attribs ): array {
        $params = [];

        if ( isset( $attribs['src'] ) && is_string( $attribs['src'] ) ) {
            $params = self::extractTransformParamsFromUrl( $attribs['src'] );
        }

        if ( isset( $attribs['width'] ) && ctype_digit( (string)$attribs['width'] ) ) {
            $params['width'] = (int)$attribs['width'];
        }

        if ( isset( $attribs['height'] ) && ctype_digit( (string)$attribs['height'] ) ) {
            $params['height'] = (int)$attribs['height'];
        }

        return self::sanitizeTransformParams( $params );
    }

    private static function extractTransformParamsFromUrl( string $url ): array {
        $decodedUrl = html_entity_decode( $url, ENT_QUOTES );
        $parts = parse_url( $decodedUrl );
        if ( !is_array( $parts ) ) {
            return [];
        }

        $params = [];

        if ( isset( $parts['query'] ) ) {
            parse_str( $parts['query'], $queryParams );
            $params['width'] = isset( $queryParams['width'] ) ? (int)$queryParams['width'] : 0;
            $params['height'] = isset( $queryParams['height'] ) ? (int)$queryParams['height'] : 0;
            $params['page'] = isset( $queryParams['page'] ) ? (int)$queryParams['page'] : 0;
        }

        $path = rawurldecode( $parts['path'] ?? '' );
        if ( $path !== '' ) {
            $basename = wfBaseName( $path );

            if ( preg_match( '/(?:^|-)page(\d+)-/i', $basename, $m ) ) {
                $params['page'] = (int)$m[1];
            }

            if ( preg_match( '/(?:^|-)(\d+)px-/i', $basename, $m ) ) {
                $params['width'] = (int)$m[1];
            }
        }

        return self::sanitizeTransformParams( $params );
    }

    private static function sanitizeTransformParams( array $params ): array {
        $sanitized = [];

        foreach ( [ 'width', 'height', 'page' ] as $key ) {
            if ( !isset( $params[$key] ) ) {
                continue;
            }

            $value = (int)$params[$key];
            if ( $value > 0 ) {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    private static function addNsfwRobotsHeader( array &$headers ): void {
        $headers['X-Robots-Tag'] = self::NSFW_ROBOTS_TAG;
    }

    private static function emitNsfwRobotsHeader(): void {
        header( 'X-Robots-Tag: ' . self::NSFW_ROBOTS_TAG );
    }

    private static function emitProxyError( int $statusCode, string $message, bool $isNsfwResponse = false ): void {
        if ( $isNsfwResponse ) {
            self::emitNsfwRobotsHeader();
        }

        http_response_code( $statusCode );
        header( 'Content-Type: text/plain; charset=UTF-8' );
        echo $message;
        exit;
    }

    private static function normalizeRenderedFileTitle( $title, $file = null ): ?Title {
        if ( $title instanceof Title && $title->inNamespace( NS_FILE ) ) {
            return $title;
        }

        if ( $file instanceof File ) {
            $fileTitle = $file->getTitle();
            if ( $fileTitle instanceof Title && $fileTitle->inNamespace( NS_FILE ) ) {
                return $fileTitle;
            }
        }

        return null;
    }

    private static function resolveRenderedNsfwFileTitle( File $file ): ?Title {
        $fileTitle = self::normalizeRenderedFileTitle( $file->getTitle(), $file );
        if ( $fileTitle && self::isFileTitleMarkedNSFW( $fileTitle ) ) {
            return $fileTitle;
        }

        $currentTitle = RequestContext::getMain()->getTitle();
        if ( !$currentTitle instanceof Title || !$currentTitle->inNamespace( NS_FILE ) ) {
            return $fileTitle;
        }

        $currentTitleIsNsfw = self::isFileTitleMarkedNSFW( $currentTitle );
        if ( !$currentTitleIsNsfw ) {
            return $fileTitle;
        }

        $placeholderTitle = self::resolvePlaceholderTitle( MediaWikiServices::getInstance() );
        if (
            $placeholderTitle
            && $fileTitle
            && $fileTitle->equals( $placeholderTitle )
            && $currentTitleIsNsfw
        ) {
            return $currentTitle;
        }

        return $fileTitle;
    }

    public static function buildProxyUrlForFileTitle( Title $fileTitle, array $transformParams = [] ): string {
        $services = MediaWikiServices::getInstance();
        $query = [ 'title' => $fileTitle->getPrefixedDBkey() ];

        foreach ( self::sanitizeTransformParams( $transformParams ) as $key => $value ) {
            $query[$key] = $value;
        }

        return wfAppendQuery( self::getProxyScriptUrl( $services ), $query );
    }

    private static function getProxyScriptUrl( MediaWikiServices $services ): string {
        return rtrim( (string)$services->getMainConfig()->get( 'ExtensionAssetsPath' ), '/' )
            . '/ContinuumNsfwFilter/'
            . self::PROXY_ENTRY_POINT;
    }

    private static function rewriteProxySrcSet(
        string $srcset,
        Title $fileTitle,
        array $baseParams
    ): string {
        $entries = array_filter( array_map( 'trim', explode( ',', $srcset ) ) );
        $rewritten = [];

        foreach ( $entries as $entry ) {
            if ( !preg_match( '/^(\S+)(?:\s+([0-9.]+)(x|w))?$/', $entry, $m ) ) {
                $rewritten[] = $entry;
                continue;
            }

            $params = $baseParams ?: self::extractTransformParamsFromUrl( $m[1] );
            if ( isset( $m[2], $m[3] ) ) {
                if ( $m[3] === 'x' && isset( $baseParams['width'] ) ) {
                    $params['width'] = max( 1, (int)round( $baseParams['width'] * (float)$m[2] ) );
                } elseif ( $m[3] === 'w' ) {
                    $params['width'] = max( 1, (int)round( (float)$m[2] ) );
                }
            }

            $descriptor = isset( $m[2], $m[3] ) ? ' ' . $m[2] . $m[3] : '';
            $rewritten[] = self::buildProxyUrlForFileTitle( $fileTitle, $params ) . $descriptor;
        }

        return implode( ', ', $rewritten );
    }

    private static function shouldRewriteRenderedFileHref( string $href, File $file ): bool {
        return self::urlPathMatches( $href, $file->getUrl() );
    }

    private static function urlPathMatches( string $left, string $right ): bool {
        if ( $left === '' || $right === '' ) {
            return false;
        }

        $leftPath = rawurldecode( (string)( parse_url( html_entity_decode( $left, ENT_QUOTES ), PHP_URL_PATH ) ?? '' ) );
        $rightPath = rawurldecode( (string)( parse_url( html_entity_decode( $right, ENT_QUOTES ), PHP_URL_PATH ) ?? '' ) );

        if ( $leftPath === '' || $rightPath === '' ) {
            return false;
        }

        return $leftPath === $rightPath;
    }

    private static function rewriteNsfwFilePageSizeLinks( string $text, Title $fileTitle ): string {
        return (string)preg_replace_callback(
            '/<a\b[^>]*>/i',
            static function ( array $matches ) use ( $fileTitle ): string {
                $tag = $matches[0];
                if ( !preg_match( '/\bclass="([^"]*\bmw-thumbnail-link\b[^"]*)"/i', $tag ) ) {
                    return $tag;
                }

                if ( !preg_match( '/\bhref="([^"]+)"/i', $tag, $hrefMatch ) ) {
                    return $tag;
                }

                $params = self::extractTransformParamsFromUrl( $hrefMatch[1] );
                $proxyUrl = htmlspecialchars(
                    self::buildProxyUrlForFileTitle( $fileTitle, $params ),
                    ENT_QUOTES
                );

                return (string)preg_replace(
                    '/\bhref="[^"]+"/i',
                    'href="' . $proxyUrl . '"',
                    $tag,
                    1
                );
            },
            $text
        );
    }

    private static function rewriteNsfwImgAuthUrlsInHtml( string $html ): string {
        if ( $html === '' || stripos( $html, 'img_auth.php' ) === false ) {
            return $html;
        }

        $html = (string)preg_replace_callback(
            '/\b(?P<name>srcset)\s*=\s*(?P<quote>["\'])(?P<value>.*?)(?P=quote)/is',
            static function ( array $matches ): string {
                $rewritten = self::rewriteNsfwImgAuthSrcSetAttributeValue( $matches['value'] );
                if ( $rewritten === $matches['value'] ) {
                    return $matches[0];
                }

                return $matches['name']
                    . '='
                    . $matches['quote']
                    . htmlspecialchars( $rewritten, ENT_QUOTES )
                    . $matches['quote'];
            },
            $html
        );

        return (string)preg_replace_callback(
            '/\b(?P<name>src|data-src|href)\s*=\s*(?P<quote>["\'])(?P<value>.*?)(?P=quote)/is',
            static function ( array $matches ): string {
                $rewritten = self::rewriteNsfwImgAuthAttributeUrl(
                    strtolower( $matches['name'] ),
                    $matches['value']
                );
                if ( $rewritten === $matches['value'] ) {
                    return $matches[0];
                }

                return $matches['name']
                    . '='
                    . $matches['quote']
                    . htmlspecialchars( $rewritten, ENT_QUOTES )
                    . $matches['quote'];
            },
            $html
        );
    }

    private static function rewriteNsfwImgAuthSrcSetAttributeValue( string $srcset ): string {
        $decodedSrcSet = html_entity_decode( $srcset, ENT_QUOTES );
        $entries = array_filter( array_map( 'trim', explode( ',', $decodedSrcSet ) ) );
        if ( $entries === [] ) {
            return $srcset;
        }

        foreach ( $entries as $entry ) {
            if ( !preg_match( '/^(\S+)/', $entry, $m ) ) {
                continue;
            }

            $fileTitle = self::resolveNsfwFileTitleFromImgAuthUrl( $m[1], 'srcset' );
            if ( !$fileTitle ) {
                continue;
            }

            return self::rewriteProxySrcSet(
                $decodedSrcSet,
                $fileTitle,
                self::extractTransformParamsFromUrl( $m[1] )
            );
        }

        return $srcset;
    }

    private static function rewriteNsfwImgAuthAttributeUrl( string $attributeName, string $url ): string {
        $decodedUrl = html_entity_decode( $url, ENT_QUOTES );
        $fileTitle = self::resolveNsfwFileTitleFromImgAuthUrl( $decodedUrl, $attributeName );
        if ( !$fileTitle ) {
            return $url;
        }

        $proxyUrl = self::buildProxyUrlForFileTitle(
            $fileTitle,
            self::extractTransformParamsFromUrl( $decodedUrl )
        );

        if ( self::urlHasDownloadQuery( $decodedUrl ) ) {
            $proxyUrl = wfAppendQuery( $proxyUrl, [ 'download' => 1 ] );
        }

        return $proxyUrl;
    }

    private static function resolveNsfwFileTitleFromImgAuthUrl( string $url, string $attributeName ): ?Title {
        if (
            $url === ''
            || self::isProxyMediaUrl( $url )
            || !self::isImgAuthUrl( $url )
        ) {
            return null;
        }

        $dbKey = self::extractImageDbKeyFromImgAuthUrl( $url );
        if ( !$dbKey ) {
            return null;
        }

        $title = Title::newFromText( $dbKey, NS_FILE );
        if ( !$title || !$title->inNamespace( NS_FILE ) ) {
            return null;
        }

        $file = self::resolveRepoFile( MediaWikiServices::getInstance(), $title );
        if ( !$file || !$file->exists() ) {
            return null;
        }

        if ( $attributeName === 'href' && !self::shouldRewriteRenderedFileHref( $url, $file ) ) {
            return null;
        }

        return self::isFileTitleMarkedNSFW( $title ) ? $title : null;
    }

    private static function isImgAuthUrl( string $url ): bool {
        $path = (string)( parse_url( html_entity_decode( $url, ENT_QUOTES ), PHP_URL_PATH ) ?? '' );
        return $path !== '' && (bool)preg_match( '#/img_auth\.php(?:/|$)#i', rawurldecode( $path ) );
    }

    private static function extractImageDbKeyFromImgAuthUrl( string $url ): ?string {
        if ( stripos( $url, 'img_auth.php' ) === false ) {
            return null;
        }

        $path = parse_url( $url, PHP_URL_PATH );
        if ( !$path ) {
            return null;
        }

        $path = rawurldecode( $path );
        $segments = array_values( array_filter( explode( '/', trim( $path, '/' ) ), 'strlen' ) );
        $count = count( $segments );
        if ( $count < 2 ) {
            return null;
        }

        $imgAuthIndex = array_search( 'img_auth.php', $segments, true );
        if ( $imgAuthIndex === false || $imgAuthIndex + 1 >= $count ) {
            return null;
        }

        $tail = array_slice( $segments, $imgAuthIndex + 1 );
        if ( $tail === [] ) {
            return null;
        }

        if ( isset( $tail[0] ) && $tail[0] === 'thumb' && count( $tail ) >= 5 ) {
            $dbKey = $tail[count( $tail ) - 2];
        } elseif ( count( $tail ) >= 3 ) {
            $dbKey = $tail[count( $tail ) - 1];
        } else {
            return null;
        }

        return str_replace( ' ', '_', $dbKey );
    }

    private static function rewriteNsfwMediaUrlsInHtml( string $html ): string {
        if ( $html === '' ) {
            return $html;
        }

        $nsfwFileTitles = self::collectNsfwFileTitlesFromHtml( $html );

        $html = (string)preg_replace_callback(
            '/\b(?P<name>srcset)\s*=\s*(?P<quote>["\'])(?P<value>.*?)(?P=quote)/is',
            static function ( array $matches ) use ( $nsfwFileTitles ): string {
                $rewritten = self::rewriteNsfwSrcSetAttributeValue( $matches['value'], $nsfwFileTitles );
                if ( $rewritten === $matches['value'] ) {
                    return $matches[0];
                }

                return $matches['name']
                    . '='
                    . $matches['quote']
                    . htmlspecialchars( $rewritten, ENT_QUOTES )
                    . $matches['quote'];
            },
            $html
        );

        $html = (string)preg_replace_callback(
            '/\b(?P<name>src|data-src|href)\s*=\s*(?P<quote>["\'])(?P<value>.*?)(?P=quote)/is',
            static function ( array $matches ) use ( $nsfwFileTitles ): string {
                $rewritten = self::rewriteNsfwMediaAttributeUrl(
                    strtolower( $matches['name'] ),
                    $matches['value'],
                    $nsfwFileTitles
                );
                if ( $rewritten === $matches['value'] ) {
                    return $matches[0];
                }

                return $matches['name']
                    . '='
                    . $matches['quote']
                    . htmlspecialchars( $rewritten, ENT_QUOTES )
                    . $matches['quote'];
            },
            $html
        );

        return (string)preg_replace_callback(
            '/\b(?P<name>style)\s*=\s*(?P<quote>["\'])(?P<value>.*?)(?P=quote)/is',
            static function ( array $matches ) use ( $nsfwFileTitles ): string {
                $rewritten = self::rewriteNsfwBackgroundImageStyleUrls( $matches['value'], $nsfwFileTitles );
                if ( $rewritten === $matches['value'] ) {
                    return $matches[0];
                }

                return $matches['name']
                    . '='
                    . $matches['quote']
                    . $rewritten
                    . $matches['quote'];
            },
            $html
        );
    }

    private static function collectNsfwFileTitlesFromHtml( string $html ): array {
        $services = MediaWikiServices::getInstance();
        $titles = [];

        foreach ( self::extractImageDbKeysFromHtml( $html ) as $dbKey ) {
            $title = Title::newFromText( $dbKey, NS_FILE );
            if ( !$title || !$title->inNamespace( NS_FILE ) ) {
                continue;
            }

            $file = self::resolveRepoFile( $services, $title );
            if ( !$file || !$file->exists() || !self::isFileTitleMarkedNSFW( $title ) ) {
                continue;
            }

            $titles[$title->getDBkey()] = $title;
        }

        return $titles;
    }

    private static function rewriteNsfwSrcSetAttributeValue( string $srcset, array $nsfwFileTitles ): string {
        $entries = array_filter( array_map( 'trim', explode( ',', html_entity_decode( $srcset, ENT_QUOTES ) ) ) );
        if ( $entries === [] ) {
            return $srcset;
        }

        foreach ( $entries as $entry ) {
            if ( !preg_match( '/^(\S+)/', $entry, $m ) ) {
                continue;
            }

            $fileTitle = self::resolveNsfwFileTitleFromRenderedUrl( $m[1], $nsfwFileTitles, 'srcset' );
            if ( !$fileTitle ) {
                continue;
            }

            return self::rewriteProxySrcSet(
                html_entity_decode( $srcset, ENT_QUOTES ),
                $fileTitle,
                self::extractTransformParamsFromUrl( $m[1] )
            );
        }

        return $srcset;
    }

    private static function rewriteNsfwMediaAttributeUrl(
        string $attributeName,
        string $url,
        array $nsfwFileTitles
    ): string {
        $decodedUrl = html_entity_decode( $url, ENT_QUOTES );
        $fileTitle = self::resolveNsfwFileTitleFromRenderedUrl( $decodedUrl, $nsfwFileTitles, $attributeName );
        if ( !$fileTitle ) {
            return $url;
        }

        $proxyUrl = self::buildProxyUrlForFileTitle(
            $fileTitle,
            self::extractTransformParamsFromUrl( $decodedUrl )
        );

        if ( self::urlHasDownloadQuery( $decodedUrl ) ) {
            $proxyUrl = wfAppendQuery( $proxyUrl, [ 'download' => 1 ] );
        }

        return $proxyUrl;
    }

    private static function rewriteNsfwBackgroundImageStyleUrls( string $style, array $nsfwFileTitles ): string {
        return (string)preg_replace_callback(
            '/background-image\s*:\s*url\(\s*(["\']?)([^)\'"]+)\1\s*\)/i',
            static function ( array $matches ) use ( $nsfwFileTitles ): string {
                $rewrittenUrl = self::rewriteNsfwMediaAttributeUrl( 'style', $matches[2], $nsfwFileTitles );
                if ( $rewrittenUrl === $matches[2] ) {
                    return $matches[0];
                }

                $quote = $matches[1];
                return 'background-image: url('
                    . $quote
                    . htmlspecialchars( $rewrittenUrl, ENT_QUOTES )
                    . $quote
                    . ')';
            },
            $style
        );
    }

    private static function resolveNsfwFileTitleFromRenderedUrl(
        string $url,
        array $nsfwFileTitles,
        string $attributeName
    ): ?Title {
        if (
            $url === ''
            || self::isProxyMediaUrl( $url )
            || !self::isRewritableRenderedMediaUrl( $url, $attributeName )
        ) {
            return null;
        }

        $dbKey = self::extractImageDbKeyFromUrl( $url );
        if ( !$dbKey || !isset( $nsfwFileTitles[$dbKey] ) ) {
            return null;
        }

        return $nsfwFileTitles[$dbKey];
    }

    private static function isProxyMediaUrl( string $url ): bool {
        return self::urlPathMatches( $url, self::getProxyScriptUrl( MediaWikiServices::getInstance() ) );
    }

    private static function isRewritableRenderedMediaUrl( string $url, string $attributeName ): bool {
        $parts = parse_url( html_entity_decode( $url, ENT_QUOTES ) );
        if ( !is_array( $parts ) ) {
            return false;
        }

        $path = rawurldecode( (string)( $parts['path'] ?? '' ) );
        $query = (string)( $parts['query'] ?? '' );

        if ( $path === '' && $query === '' ) {
            return false;
        }

        $hasMediaPath = (bool)preg_match( '#/(?:img_auth\.php|images/|thumb/|transcoded/)#i', $path );
        $isFilePageUrl = (bool)preg_match( '#/wiki/File:#i', $path )
            || ( !$hasMediaPath && preg_match( '/(?:^|[?&])title=File:/i', $query ) );

        if ( $attributeName === 'href' && $isFilePageUrl ) {
            return false;
        }

        return $hasMediaPath || $isFilePageUrl || preg_match( '/(?:^|[?&])title=(?:File:)?[^&]+\.[a-z0-9]{2,5}/i', $query );
    }

    private static function extractImageDbKeyFromUrl( string $url ): ?string {
        $decodedUrl = html_entity_decode( $url, ENT_QUOTES );
        $parts = parse_url( $decodedUrl );
        if ( !is_array( $parts ) ) {
            return null;
        }

        if ( isset( $parts['query'] ) ) {
            parse_str( $parts['query'], $queryParams );
            if ( isset( $queryParams['title'] ) ) {
                $dbKey = self::normalizeDbKeyFromMaybeUrlOrName( (string)$queryParams['title'] );
                if ( self::isPlausibleFileDbKey( $dbKey ) ) {
                    return $dbKey;
                }
            }
        }

        $path = rawurldecode( (string)( $parts['path'] ?? '' ) );
        if ( $path === '' ) {
            return null;
        }

        $segments = array_values( array_filter( explode( '/', trim( $path, '/' ) ), 'strlen' ) );
        if ( $segments === [] ) {
            return null;
        }

        if ( isset( $segments[0] ) && $segments[0] === 'wiki' && isset( $segments[1] ) ) {
            $dbKey = self::normalizeDbKeyFromMaybeUrlOrName( $segments[1] );
            return self::isPlausibleFileDbKey( $dbKey ) ? $dbKey : null;
        }

        if ( isset( $segments[0] ) && $segments[0] === 'images' ) {
            if ( isset( $segments[1] ) && $segments[1] === 'thumb' ) {
                if ( count( $segments ) < 6 ) {
                    return null;
                }

                $dbKey = self::normalizeDbKeyFromMaybeUrlOrName( $segments[count( $segments ) - 2] );
                return self::isPlausibleFileDbKey( $dbKey ) ? $dbKey : null;
            }

            if ( count( $segments ) >= 4 ) {
                $dbKey = self::normalizeDbKeyFromMaybeUrlOrName( $segments[count( $segments ) - 1] );
                return self::isPlausibleFileDbKey( $dbKey ) ? $dbKey : null;
            }
        }

        return null;
    }

    private static function isPlausibleFileDbKey( ?string $dbKey ): bool {
        return is_string( $dbKey )
            && $dbKey !== ''
            && (bool)preg_match( '/\.[a-z0-9]{2,5}$/i', $dbKey );
    }

    private static function urlHasDownloadQuery( string $url ): bool {
        $parts = parse_url( html_entity_decode( $url, ENT_QUOTES ) );
        if ( !is_array( $parts ) || !isset( $parts['query'] ) ) {
            return false;
        }

        parse_str( $parts['query'], $queryParams );
        return !empty( $queryParams['download'] );
    }

    /* ============================================================
     *  CSS
     * ========================================================== */

    private static function getEarlyInlineCss(): string {
        return <<<'CSS'
body.nsfw-page-restricted .nsfw-page-restriction {
    margin: 1.5rem auto;
    max-width: 48rem;
}

body.nsfw-page-restricted .nsfw-page-restriction__title {
    margin: 0 0 0.75rem;
}

body.nsfw-page-restricted .nsfw-page-restriction__body,
body.nsfw-page-restricted .nsfw-page-restriction__actions,
body.nsfw-page-restricted .nsfw-page-restriction__tip {
    margin: 0 0 0.75rem;
}
CSS;
    }
}
