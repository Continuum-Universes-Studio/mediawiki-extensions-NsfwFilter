<?php
namespace NSFWBlur;

if ( !defined( 'MEDIAWIKI' ) ) {
    die();
}

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Revision\RevisionRecord;
use OutputPage;
use Skin;
use RequestContext;
use Parser;
use Title;
use MediaWiki\User\UserIdentity;
use User;

class Hooks {

    private const NSFW_MARKER = '__NSFW__';
    private const OPT_UNBLUR  = 'nsfwblurred';     // toggle: show NSFW unblurred
    private const OPT_BIRTHYR = 'nsfw_birthyear';  // private int field
    private const MIN_AGE     = 18;

    /** Adds JS config vars for age/gating + loads modules/styles early */
    public static function onBeforePageDisplay( OutputPage $out, Skin $skin ): bool {
        $services = MediaWikiServices::getInstance();
        $user = $out->getUser();

        $userOptionsLookup = $services->getUserOptionsLookup();
        $userWantsUnblur = $user->isRegistered()
            ? (bool)$userOptionsLookup->getOption( $user, self::OPT_UNBLUR )
            : false;

        // Birth year is private; send only to logged-in users (null otherwise)
        $birthYear = $user->isRegistered()
            ? self::getUserPrivateBirthYear( $services, $user )
            : null;

        // JS config for gating / UI
        $out->addJsConfigVars( [
            'wgPrivateBirthYear' => $birthYear,
            'wgNSFWUnblur'       => $userWantsUnblur,
        ] );

        // Early “airbag” CSS to prevent flash of unblurred content
        self::addEarlyInlineCss( $out );
        $out->addInlineStyle(<<<'CSS'
        /* PREVENT FLASH:
        * In MW's non-legacy media DOM, the <img> often stays .mw-file-element and your class
        * is applied to the wrapper <span>/<figure>. So we must target BOTH.
        */
        .nsfw-blur img,
        .nsfw-blur .mw-file-element,
        img.nsfw-blur {
            filter: blur(24px) !important;
        }

        /* File: pages (body class applied by PHP) */
        body.nsfw-filepage-blur #file img,
        body.nsfw-filepage-blur #file .mw-file-element,
        body.nsfw-filepage-blur .fullImageLink img,
        body.nsfw-filepage-blur .mw-filepage-other-resolutions img {
            filter: blur(24px) !important;
        }

        /* MediaViewer "preblur" (JS toggles this on very early) */
        body.nsfw-mmv-preblur .mw-mmv-image img {
            filter: blur(24px) !important;
        }

        /* Optional: kill transition during initial paint so it doesn't "animate in" like a flash */
        .nsfw-blur img { transition: none !important; }
        CSS);

        // Modules / styles
        // Keep your module names, but don’t load the same key twice
        $out->addModules( [
            'ext.nsfwblur.top', // position=top module in extension.json
            'ext.nsfwblur',     // your main logic/UI module (if you still need it)
        ] );

        $out->addModuleStyles( [
            'ext.nsfwblur.styles', // your normal stylesheet module
        ] );

        // File: page blur class if needed
        self::applyFilePageBlurClass( $out, $services, $userWantsUnblur );

        return true;
    }

    /** Registers the user preference */
    public static function onGetPreferences( $user, &$preferences ) {
        $canSeeNSFW = self::isUserOldEnoughForNSFW( $user, 18 );
        if ( !$canSeeNSFW ) {
            self::resetNSFWBlurredOptionForUser( $user );
        }
        $preferences['nsfwblurred'] = [
            'type' => 'toggle',
            'label-message' => 'tog-nsfwblurred',
            'section' => 'rendering/files',
            'default' => false,
            'disabled' => !$canSeeNSFW,
            'help-message' => !$canSeeNSFW ? 'nsfwblur-pref-nsfw-age' : null,
        ];
        return true;
    }


    /** Adds preference to the page rendering hash */
    public static function onPageRenderingHash( $out, &$hash ): bool {
        $services = MediaWikiServices::getInstance();
        $user = RequestContext::getMain()->getUser();

        $userOptionsLookup = $services->getUserOptionsLookup();
        $nsfwblurred = (bool)$userOptionsLookup->getOption( $user, self::OPT_UNBLUR );

        $hash .= '!' . ( $nsfwblurred ? '1' : '0' );
        return true;
    }

    /** ImageBeforeProduceHTML: Blur if needed */
    public static function onImageBeforeProduceHTML(
        &$skin, &$title, &$file, &$frameParams, &$handlerParams, &$time, &$res
    ): bool {
        if ( !$file ) {
            return true;
        }

        $services = MediaWikiServices::getInstance();
        $user = RequestContext::getMain()->getUser();

        // If user wants to see NSFW images unblurred, don't blur
        if ( self::userWantsUnblur( $services, $user ) ) {
            return true;
        }

        // Check file description wikitext for marker
        $fileTitle = $file->getTitle();
        if ( !$fileTitle ) {
            return true;
        }

        $descText = self::getPageWikitext( $services, $fileTitle );
        if ( $descText === null || strpos( $descText, self::NSFW_MARKER ) === false ) {
            return true;
        }

        // Add blur class to the IMG
        $frameParams['class'] = isset( $frameParams['class'] )
            ? trim( $frameParams['class'] . ' nsfw-blur' )
            : 'nsfw-blur';

        // Ensure image gets tracked in parser output when available
        if ( isset( $frameParams['parser'] ) && $frameParams['parser'] instanceof Parser ) {
            $frameParams['parser']->getOutput()->addImage( $file->getName() );
        }

        return true;
    }

    /** Helper: user toggle */
    private static function userWantsUnblur( MediaWikiServices $services, User $user ): bool {
        if ( !$user->isRegistered() ) {
            return false;
        }
        return (bool)$services->getUserOptionsLookup()->getOption( $user, self::OPT_UNBLUR );
    }

    /** Helper: Get user birth year from user options (private "custom profile field") */
    private static function getUserPrivateBirthYear( MediaWikiServices $services, User $user ): ?int {
        $val = $services->getUserOptionsLookup()->getOption( $user, self::OPT_BIRTHYR, '' );

        if ( $val === '' || $val === null ) {
            return null;
        }

        $year = (int)$val;
        return $year > 0 ? $year : null;
    }

    /** Helper: Age check */
    private static function isUserOldEnoughForNSFW(
        MediaWikiServices $services,
        User $user,
        int $minAge = self::MIN_AGE
    ): bool {
        $birthYear = self::getUserPrivateBirthYear( $services, $user );
        if ( !$birthYear ) {
            return false;
        }
        $thisYear = (int)date( 'Y' );
        return ( $thisYear - $birthYear ) >= $minAge;
    }

    /** Optional helper: reset both options for a user */
    public static function resetNSFWOptionsForUser( UserIdentity $user ): void {
        $services = MediaWikiServices::getInstance();
        $mgr = $services->getUserOptionsManager();

        $mgr->setOption( $user, self::OPT_BIRTHYR, '' );
        $mgr->setOption( $user, self::OPT_UNBLUR, false );
        $mgr->saveOptions( $user );
    }

    /** Helper: check if File: title has marker */
    private static function isFileTitleMarkedNSFW( MediaWikiServices $services, Title $fileTitle ): bool {
        $text = self::getPageWikitext( $services, $fileTitle );
        return ( $text !== null && strpos( $text, self::NSFW_MARKER ) !== false );
    }

    /** Helper: get wikitext for a title (main slot, public) */
    private static function getPageWikitext( MediaWikiServices $services, Title $title ): ?string {
        $revision = $services->getRevisionLookup()->getRevisionByTitle( $title );
        if ( !$revision ) {
            return null;
        }

        $content = $revision->getContent( SlotRecord::MAIN, RevisionRecord::FOR_PUBLIC );
        if ( !$content ) {
            return null;
        }

        // For wikitext ContentHandler, getText() exists. If you later support JSON/etc,
        // you may want to handle Content::serialize() instead.
        return method_exists( $content, 'getText' ) ? $content->getText() : null;
    }
}
