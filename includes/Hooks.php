<?php
namespace NSFWBlur;

if ( !defined( 'MEDIAWIKI' ) ) {
    die();
}

use MediaWiki\Context\RequestContext;
use MediaWiki\Html\FormOptions;
use MediaWiki\MediaWikiServices;
use MediaWiki\Pager\ImageListPager;
use MediaWiki\Pager\NewFilesPager;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserRigorOptions;
use OutputPage;
use Parser;
use Skin;
use MediaWiki\Title\Title;

use User;

class Hooks {
    private const NSFW_MARKER = '__NSFW__';
    private const NSFW_CATEGORY_DBKEY = 'NSFW'; // Category:NSFW

    private const OPT_UNBLUR           = 'nsfwblurred';
    private const OPT_BIRTHDATE        = 'nsfw_birthdate';
    private const OPT_BIRTHDATE_LEGACY = 'nsfw_birthyear';

    private const MIN_AGE = 18;

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
        ] );

        // Make your CSS's body override actually work (your CSS checks body.nsfw-unblur)
        if ( $userWantsUnblur ) {
            $out->addBodyClasses( 'nsfw-unblur' );
        }

        // Pull any existing list (set earlier by ParserOutput hooks)
        $existing = [];
        if ( method_exists( $out, 'getJsConfigVars' ) ) {
            $vars = $out->getJsConfigVars();
            if ( isset( $vars['wgNSFWFilesOnPage'] ) && is_array( $vars['wgNSFWFilesOnPage'] ) ) {
                $existing = $vars['wgNSFWFilesOnPage'];
            }
        }

        $fromHtml = [];

        // Keep your HTML scrape behavior (works for some infobox HTML cases),
        // but DO NOT allow it to wipe out the parser-derived list.
        if ( !$userWantsUnblur && $isContentPage ) {
            $html = self::getOutputHtml( $out );
            $dbKeys = self::extractImageDbKeysFromHtml( $html );

            foreach ( $dbKeys as $dbKey ) {
                $fileTitle = Title::makeTitleSafe( NS_FILE, $dbKey );
                if ( !$fileTitle ) {
                    continue;
                }
                if ( self::isFileTitleMarkedNSFW( $fileTitle ) ) {
                    $fromHtml[] = $fileTitle->getPrefixedText();
                }
            }

            $fromHtml = array_values( array_unique( $fromHtml ) );
        }

        if ( $isContentPage ) {
            $merged = array_values( array_unique( array_merge( $existing, $fromHtml ) ) );
            sort( $merged );

            $out->addJsConfigVars( [
                'wgNSFWFilesOnPage' => $merged,
            ] );
        }

        $out->addInlineStyle( self::getEarlyInlineCss() );
        $out->addModules( [ 'ext.nsfwblur.top', 'ext.nsfwblur' ] );
        $out->addModuleStyles( [ 'ext.nsfwblur.styles' ] );

        self::applyFilePageBlurClass( $out, $userWantsUnblur );

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
        $services = MediaWikiServices::getInstance();
        $user     = $out->getUser();
        $title    = $out->getTitle();

        // Run everywhere except Special: pages
        if ( !$title || $title->isSpecialPage() ) {
            return;
        }

        // Respect user unblur preference
        if ( self::userWantsUnblur( $services, $user ) ) {
            if ( method_exists( $parserOutput, 'setJsConfigVar' ) ) {
                $parserOutput->setJsConfigVar( 'wgNSFWFilesOnPage', [] );
            } else {
                $parserOutput->addJsConfigVars( 'wgNSFWFilesOnPage', [] );
            }
            return;
        }

        $dbKeys = [];

        // 1) MW 1.43+ preferred API: ParserOutputLinkTypes::MEDIA
        if ( class_exists( 'MediaWiki\\Parser\\ParserOutputLinkTypes' ) && method_exists( $parserOutput, 'getLinkList' ) ) {
            $media = $parserOutput->getLinkList( \MediaWiki\Parser\ParserOutputLinkTypes::MEDIA );
            if ( is_array( $media ) ) {
                // getLinkList typically returns an associative array keyed by DB key
                $dbKeys = array_merge( $dbKeys, array_keys( $media ) );
            }
        } else {
            // 2) Older API (deprecated in 1.43, but still works)
            if ( method_exists( $parserOutput, 'getImages' ) ) {
                $images = $parserOutput->getImages();
                if ( is_array( $images ) ) {
                    $dbKeys = array_merge( $dbKeys, array_keys( $images ) );
                }
            }
        }

        // 3) Scrape parser HTML as a secondary net (PortableInfobox / odd output)
        $html = '';
        if ( method_exists( $parserOutput, 'getRawText' ) ) {
            $html = (string)$parserOutput->getRawText();
        } elseif ( method_exists( $parserOutput, 'getText' ) ) {
            // older MW fallback
            $html = (string)$parserOutput->getText();
        }

        if ( is_string( $html ) && $html !== '' ) {
            $dbKeys = array_merge( $dbKeys, self::extractImageDbKeysFromHtml( $html ) );
        }

        // 4) Hard reliability net: imagelinks table (usually fixes galleries)
        $pageId = $title->getArticleID();
        if ( $pageId ) {
            try {
                $dbr = $services->getConnectionProvider()->getReplicaDatabase();
                $res = $dbr->newSelectQueryBuilder()
                    ->select( [ 'il_to' ] )
                    ->from( 'imagelinks' )
                    ->where( [ 'il_from' => $pageId ] )
                    ->caller( __METHOD__ )
                    ->fetchResultSet();

                foreach ( $res as $row ) {
                    if ( !empty( $row->il_to ) ) {
                        $dbKeys[] = (string)$row->il_to; // DB key like "Anita3.png"
                    }
                }
            } catch ( \Throwable $e ) {
                // ignore DB failures; other sources may still work
            }
        }

        // Normalize + unique
        $dbKeys = array_values( array_unique( array_filter( array_map(
            static function ( $v ) {
                return ( is_string( $v ) && $v !== '' ) ? $v : null;
            },
            $dbKeys
        ) ) ) );

        // Resolve which of those are NSFW
        $nsfw = [];
        foreach ( $dbKeys as $dbKey ) {
            $fileTitle = Title::makeTitleSafe( NS_FILE, $dbKey );
            if ( !$fileTitle ) {
                continue;
            }
            if ( self::isFileTitleMarkedNSFW( $fileTitle ) ) {
                $nsfw[] = $fileTitle->getPrefixedText();
            }
        }

        $nsfw = array_values( array_unique( $nsfw ) );
        sort( $nsfw );

        // Prefer non-deprecated setter
        if ( method_exists( $parserOutput, 'setJsConfigVar' ) ) {
            $parserOutput->setJsConfigVar( 'wgNSFWFilesOnPage', $nsfw );
        } else {
            $parserOutput->addJsConfigVars( 'wgNSFWFilesOnPage', $nsfw );
        }
    }



    /* ============================================================
     *  PARSER OUTPUT (OPTIONAL CACHE/DEBUG) — SAFE SIGNATURE
     *  Hook: ParserOutput
     * ========================================================== */
    public static function onThumbnailBeforeProduceHTML( $thumbnail, array &$attribs, &$linkAttribs, ...$more ): void {
        if ( !is_array( $linkAttribs ) ) {
            $linkAttribs = [];
        }
        // Honor the user's preference, even though this hook doesn't receive $user.
        $services = MediaWikiServices::getInstance();
        $user = RequestContext::getMain()->getUser();
        if ( $user instanceof User && self::userWantsUnblur( $services, $user ) ) {
            return;
        }

        if ( !is_object( $thumbnail ) || !method_exists( $thumbnail, 'getFile' ) ) {
            return;
        }

        $file = $thumbnail->getFile();
        if ( !$file || !method_exists( $file, 'getTitle' ) ) {
            return;
        }

        $fileTitle = $file->getTitle();
        if ( !$fileTitle instanceof Title ) {
            return;
        }

        if ( !self::isFileTitleMarkedNSFW( $fileTitle ) ) {
            return;
        }

        $attribs['class'] = trim( ( $attribs['class'] ?? '' ) . ' nsfw-blur' );
        $linkAttribs['class'] = trim( ( $linkAttribs['class'] ?? '' ) . ' nsfw-blur' );
    }
    public static function onParserOutput( Parser $parser, ParserOutput $po, ...$args ): bool {
        // Debug/inspection only; not relied on for the final blur list.
        $images = $po->getImages();
        $dbKeys = is_array( $images ) ? array_keys( $images ) : [];

        $html = $po->getText();
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

        // 0) data-file-name="Foo.png" (some gallery/skins/widgets)
        if ( preg_match_all( '/\bdata-file-name="([^"]+\.[a-z0-9]{2,5})"/i', $html, $m ) ) {
            foreach ( $m[1] as $name ) {
                $keys[] = self::normalizeDbKeyFromMaybeUrlOrName( $name );
            }
        }

        // 0.5) data-title="File:Foo.png" (common in some output)
        if ( preg_match_all( '/\bdata-title="([^"]+)"/i', $html, $m ) ) {
            foreach ( $m[1] as $val ) {
                $keys[] = self::normalizeDbKeyFromMaybeUrlOrName( $val );
            }
        }

        // 1) <img ... alt="Foo.png">
        if ( preg_match_all( '/\balt="([^"]+\.[a-z0-9]{2,5})"/i', $html, $m ) ) {
            foreach ( $m[1] as $name ) {
                $keys[] = self::normalizeDbKeyFromMaybeUrlOrName( $name );
            }
        }

        // 2) <a ... title="File:Foo.png"> or title="Foo.png"
        if ( preg_match_all( '/\btitle="([^"]+\.[a-z0-9]{2,5})"/i', $html, $m ) ) {
            foreach ( $m[1] as $name ) {
                $keys[] = self::normalizeDbKeyFromMaybeUrlOrName( $name );
            }
        }

        // 3) Direct file URL: /w/images/6/60/Foo.png
        if ( preg_match_all( '#/w/images/[^/]+/[^/]+/([^/"\'\?#]+\.[a-z0-9]{2,5})#i', $html, $m ) ) {
            foreach ( $m[1] as $name ) {
                $keys[] = self::normalizeDbKeyFromMaybeUrlOrName( $name );
            }
        }

        // 4) Thumb URL: /w/images/thumb/.../Foo.png/320px-Foo.png
        if ( preg_match_all( '#/w/images/thumb/[^/]+/[^/]+/([^/"\'\?#]+\.[a-z0-9]{2,5})/#i', $html, $m ) ) {
            foreach ( $m[1] as $name ) {
                $keys[] = self::normalizeDbKeyFromMaybeUrlOrName( $name );
            }
        }

        // 5) File page links: /wiki/File:Foo.png
        if ( preg_match_all( '#/wiki/File:([^/"\'\?#]+?\.[a-z0-9]{2,5})#i', $html, $m ) ) {
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

        $keys = array_values( array_unique( array_filter( $keys ) ) );
        return $keys;
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


    /* ============================================================
     *  SPECIAL PAGES (LISTFILES / NEWFILES)
     * ========================================================== */

    public static function onSpecialPageBeforeExecute( SpecialPage $special, ?string $subPage ): void {
        $name = strtolower( $special->getName() );
        if ( !in_array( $name, [ 'listfiles', 'newfiles', 'newimages' ], true ) ) {
            return;
        }

        $out      = $special->getOutput();
        $services = MediaWikiServices::getInstance();
        $user     = $out->getUser();

        $out->addInlineStyle( self::getEarlyInlineCss() );
        $out->addModules( [ 'ext.nsfwblur', 'ext.nsfwblur.top' ] );
        $out->addModuleStyles( [ 'ext.nsfwblur.styles' ] );

        if ( self::userWantsUnblur( $services, $user ) ) {
            $out->addBodyClasses( 'nsfw-unblur' );
            $out->addJsConfigVars( 'wgNSFWFilesOnPage', [] );
            return;
        }

        $nsfw = [];

        if ( $name === 'listfiles' ) {
            $request   = $special->getRequest();
            $including = method_exists( $special, 'including' ) ? (bool)$special->including() : false;

            if ( $including ) {
                $userName = (string)$subPage;
                $search   = '';
                $showAll  = false;
            } else {
                $userName = $request->getText( 'user', $subPage ?? '' );
                $search   = $request->getText( 'ilsearch', '' );
                $showAll  = $request->getBool( 'ilshowall', false );
            }

            $canonical = $services->getUserNameUtils()->getCanonical( $userName, UserRigorOptions::RIGOR_NONE );
            if ( $canonical !== false ) {
                $userName = $canonical;
            }

            $opts = new FormOptions();
            $opts->add( 'limit', 50 );
            $opts->add( 'user', $userName );
            $opts->add( 'ilsearch', $search );
            $opts->add( 'ilshowall', $showAll );

            $pager = new ImageListPager(
                $special->getContext(),
                $services->getCommentStore(),
                $special->getLinkRenderer(),
                $services->getConnectionProvider(),
                $services->getRepoGroup(),
                $services->getUserNameUtils(),
                $services->getRowCommentFormatter(),
                $services->getLinkBatchFactory(),
                $userName,
                $search,
                $including,
                $showAll
            );

            $pager->doQuery();
            $res = $pager->getResult();

            foreach ( $res as $row ) {
                if ( empty( $row->img_name ) ) {
                    continue;
                }
                $fileTitle = Title::makeTitleSafe( NS_FILE, $row->img_name );
                if ( $fileTitle && self::isFileTitleMarkedNSFW( $fileTitle ) ) {
                    $nsfw[] = $fileTitle->getPrefixedText();
                }
            }
        }

        if ( $name === 'newfiles' || $name === 'newimages' ) {
            $request = $special->getRequest();

            if ( class_exists( NewFilesPager::class ) ) {
                try {
                    $pager = new NewFilesPager(
                        $special->getContext(),
                        $services->getGroupPermissionsLookup(),
                        $services->getLinkBatchFactory(),
                        $services->getLinkRenderer(),
                        $services->getConnectionProvider(),
                        $opts
                    );
                    $pager->doQuery();
                    $res = $pager->getResult();

                    foreach ( $res as $row ) {
                        $nameField = $row->img_name ?? null;
                        if ( !$nameField ) {
                            continue;
                        }
                        $fileTitle = Title::makeTitleSafe( NS_FILE, $nameField );
                        if ( $fileTitle && self::isFileTitleMarkedNSFW( $fileTitle ) ) {
                            $nsfw[] = $fileTitle->getPrefixedText();
                        }
                    }
                } catch ( \Throwable $e ) {
                    // fall back below
                }
            }

            if ( !$nsfw ) {
                $limit = $request->getInt( 'limit', 50 );
                $limit = max( 1, min( 500, $limit ) );

                $dbr = $services->getConnectionProvider()->getReplicaDatabase();
                $rows = $dbr->newSelectQueryBuilder()
                    ->select( [ 'img_name' ] )
                    ->from( 'image' )
                    ->orderBy( 'img_timestamp', 'DESC' )
                    ->limit( $limit )
                    ->caller( __METHOD__ )
                    ->fetchResultSet();

                foreach ( $rows as $row ) {
                    if ( empty( $row->img_name ) ) {
                        continue;
                    }
                    $fileTitle = Title::makeTitleSafe( NS_FILE, $row->img_name );
                    if ( $fileTitle && self::isFileTitleMarkedNSFW( $fileTitle ) ) {
                        $nsfw[] = $fileTitle->getPrefixedText();
                    }
                }
            }
        }

        $nsfw = array_values( array_unique( $nsfw ) );
        sort( $nsfw );

        $out->addJsConfigVars( 'wgNSFWFilesOnPage', $nsfw );
    }

    /* ============================================================
     *  PREFERENCES
     * ========================================================== */

    public static function onGetPreferences( $user, &$preferences ): bool {
        $services = MediaWikiServices::getInstance();

        $canSeeNSFW        = self::isUserOldEnoughForNSFW( $services, $user );
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

        $preferences[self::OPT_UNBLUR] = [
            'type'          => 'toggle',
            'label-message' => 'tog-nsfwblurred',
            'section'       => 'rendering/files',
            'disabled'      => !$canSeeNSFW,
            'help-message'  => !$canSeeNSFW ? 'nsfwblur-pref-nsfw-age' : null,
            'validation-callback' => [ self::class, 'validateNsfwUnblurPreference' ],
        ];

        return true;
    }

    /* ============================================================
     *  IMAGE BLUR (SERVER-SIDE CLASSES FOR THUMBS)
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
        // Bail if $file isn't a File-like object
        if ( !is_object( $file ) || !method_exists( $file, 'getTitle' ) ) {
            return true;
        }

        $fileTitle = $file->getTitle();
        if ( !$fileTitle || !is_object( $fileTitle ) || !method_exists( $fileTitle, 'inNamespace' ) ) {
            return true;
        }

        // If this isn't NSFW, do nothing
        try {
            if ( !self::isFileTitleMarkedNSFW( $fileTitle ) ) {
                return true;
            }
        } catch ( \Throwable $e ) {
            // Never let this hook kill rendering/indexing
            return true;
        }

        $frameParams['class']     = trim( ( $frameParams['class'] ?? '' ) . ' nsfw-blur' );
        $frameParams['img-class'] = trim( ( $frameParams['img-class'] ?? '' ) . ' nsfw-blur' );
        $handlerParams['class']   = trim( ( $handlerParams['class'] ?? '' ) . ' nsfw-blur' );

        return true;
    }


    /* ============================================================
     *  CACHE / DEFAULT OPTIONS
     * ========================================================== */

    public static function onPageRenderingHash( &$confstr, $user, $optionsUsed = [] ): void {
        try {
            $services = MediaWikiServices::getInstance();
            if ( $user instanceof User ) {
                $unblur = self::userWantsUnblur( $services, $user ) ? '1' : '0';
                $confstr .= "!nsfw:$unblur";
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
            if ( !self::isUserOldEnoughForNSFW( $services, $user ) ) {
                $options[self::OPT_UNBLUR] = 0;
            }
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

    private static function normalizeBirthDateValue( string $value ): ?string {
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
            return $value;
        }
        if ( preg_match( '/^\d{4}$/', $value ) ) {
            return $value . '-01-01';
        }
        return null;
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
        $birth = strtotime( $date );
        return ( time() - $birth ) >= ( self::MIN_AGE * 31557600 );
    }

    private static function userWantsUnblur( MediaWikiServices $services, User $user ): bool {
        return $user->isRegistered()
            && (bool)$services->getUserOptionsLookup()->getOption( $user, self::OPT_UNBLUR );
    }

    /* ============================================================
     *  NSFW DETECTION (MARKER OR CATEGORY)
     * ========================================================== */

    private static function isFileTitleMarkedNSFW( Title $fileTitle ): bool {
        static $memo = [];

        if ( !$fileTitle->inNamespace( NS_FILE ) ) {
            return false;
        }

        $cacheKey = $fileTitle->getPrefixedDBkey();
        if ( array_key_exists( $cacheKey, $memo ) ) {
            return $memo[$cacheKey];
        }

        $services = MediaWikiServices::getInstance();

        // 1) Marker in wikitext (still fine, but make it model-safe)
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

        // 2) Category:NSFW membership (MW 1.45 schema: categorylinks + linktarget)
        $pageId = $fileTitle->getArticleID();
        if ( !$pageId ) {
            return $memo[$cacheKey] = false;
        }

        try {
            $dbr = $services->getConnectionProvider()->getReplicaDatabase();

            $row = $dbr->newSelectQueryBuilder()
                ->select( [ 'cl_from' ] )
                ->from( 'categorylinks' )
                ->join( 'linktarget', 'lt', 'lt.lt_id = cl_target_id' )
                ->where( [
                    'cl_from'      => (int)$pageId,
                    'lt.lt_namespace' => NS_CATEGORY,
                    'lt.lt_title'     => self::NSFW_CATEGORY_DBKEY, // DB key: "NSFW"
                ] )
                ->limit( 1 )
                ->caller( __METHOD__ )
                ->fetchRow();

            return $memo[$cacheKey] = (bool)$row;
        } catch ( \Throwable $e ) {
            return $memo[$cacheKey] = false;
        }
    }



    private static function applyFilePageBlurClass( OutputPage $out, bool $userWantsUnblur ): void {
        $title = $out->getTitle();
        if ( !$title || !$title->inNamespace( NS_FILE ) ) {
            return;
        }

        if ( $userWantsUnblur ) {
            return;
        }

        if ( self::isFileTitleMarkedNSFW( $title ) ) {
            $out->addBodyClasses( 'nsfw-filepage-blur' );
            $out->addJsConfigVars( 'wgNSFWFilePage', true );
        }
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

    public static function validateNsfwUnblurPreference( $value, $alldata = null, $user = null ): bool|string {
        if ( !$value ) {
            return true;
        }

        if ( $user instanceof User ) {
            $services = MediaWikiServices::getInstance();
            if ( self::isUserOldEnoughForNSFW( $services, $user ) ) {
                return true;
            }
        }

        return wfMessage( 'nsfwblur-pref-nsfw-age' )->text();
    }

    /* ============================================================
     *  CSS
     * ========================================================== */

    private static function getEarlyInlineCss(): string {
        return <<<'CSS'
.nsfw-blur img,
.nsfw-blur .mw-file-element,
img.nsfw-blur {
    filter: blur(24px) !important;
}

body.nsfw-filepage-blur #file img,
body.nsfw-filepage-blur #file .mw-file-element,
body.nsfw-filepage-blur .fullImageLink img,
body.nsfw-filepage-blur .mw-filepage-other-resolutions img {
    filter: blur(24px) !important;
}

body.nsfw-mmv-preblur .mw-mmv-image img,
body.nsfw-mmv-preblur img.mw-mmv-final-image {
    filter: blur(24px) !important;
}

.nsfw-blur img {
    transition: none !important;
}
CSS;
    }
}
