<?php
namespace NSFWBlur;

if ( !defined( 'MEDIAWIKI' ) ) {
    die();
}

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Parser\ParserOutputLinkTypes;
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
    private const OPT_BIRTHDATE = 'nsfw_birthyear';  // private date field (YYYY-MM-DD)
    private const MIN_AGE     = 18;

    /** Adds JS config vars for age/gating + loads modules/styles early */
    public static function onBeforePageDisplay( OutputPage $out, Skin $skin ): bool {
        $services = MediaWikiServices::getInstance();
        $user = $out->getUser();

        // --- prefs / gating ---
        $userWantsUnblur = false;
        $birthDate = null;

        if ( $user->isRegistered() ) {
            $opts = $services->getUserOptionsLookup();
            $userWantsUnblur = (bool)$opts->getOption( $user, self::OPT_UNBLUR );
            $birthDate = self::getUserPrivateBirthDate( $services, $user );
        }

        // --- JS config (always predictable types) ---
        $out->addJsConfigVars( [
            'wgPrivateBirthDate' => $birthDate,       // string|null
            'wgNSFWUnblur'       => $userWantsUnblur,  // bool
            'wgNSFWFilesOnPage'  => [],                // array; real list should be set in OutputPageParserOutput
        ] );

        // --- Early “airbag” CSS to prevent flash ---
        $out->addInlineStyle( self::getEarlyInlineCss() );

        // --- ResourceLoader assets ---
        $out->addModules( [ 'ext.nsfwblur.top', 'ext.nsfwblur' ] );
        $out->addModuleStyles( [ 'ext.nsfwblur.styles' ] );

        // --- File: page blur class if needed ---
        self::applyFilePageBlurClass( $out, $services, $userWantsUnblur );

        return true;
    }

    /** Keep inline CSS in one place so you don’t duplicate/indent it differently everywhere. */
    private static function getEarlyInlineCss(): string {
        return <<<'CSS'
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
    body.nsfw-mmv-preblur .mw-mmv-image img,
    body.nsfw-mmv-preblur img.mw-mmv-final-image {
        filter: blur(24px) !important;
    }

    /* Optional: kill transition during initial paint so it doesn't "animate in" like a flash */
    .nsfw-blur img { transition: none !important; }
    CSS;
    }


public static function onOutputPageParserOutput( OutputPage $out, ParserOutput $parserOutput ): void {
    $services = MediaWikiServices::getInstance();
    $user = $out->getUser();

    // If user opted to unblur, don't bother building the list.
    $userWantsUnblur = false;
    if ( $user->isRegistered() ) {
        $opts = $services->getUserOptionsLookup();
        $userWantsUnblur = (bool)$opts->getOption( $user, self::OPT_UNBLUR );
    }
    if ( $userWantsUnblur ) {
        $parserOutput->addJsConfigVars( 'wgNSFWFilesOnPage', [] );
        return;
    }

    // Get media links from ParserOutput (MEDIA = File: links used on the page)
    $media = $parserOutput->getLinkList( ParserOutputLinkTypes::MEDIA ); // :contentReference[oaicite:2]{index=2}

    if ( !$media ) {
        $parserOutput->addJsConfigVars( 'wgNSFWFilesOnPage', [] );
        return;
    }

    $nsfw = [];
    foreach ( $media as $item ) {
        $link = $item['link'] ?? null;
        if ( !$link || !method_exists( $link, 'getDBkey' ) ) {
            continue;
        }

        $fileTitle = \Title::makeTitleSafe( NS_FILE, $link->getDBkey() );
        if ( !$fileTitle ) {
            continue;
        }

        if ( self::isFileTitleMarkedNSFW( $services, $fileTitle ) ) {
            $nsfw[] = $fileTitle->getPrefixedText(); // "File:Example.png"
        }
    }

    $nsfw = array_values( array_unique( $nsfw ) );
    sort( $nsfw );

    $parserOutput->addJsConfigVars( 'wgNSFWFilesOnPage', $nsfw );
}
    /** Registers the user preference */
    public static function onGetPreferences( $user, &$preferences ) {
        $services = MediaWikiServices::getInstance();
        $canSeeNSFW = self::isUserOldEnoughForNSFW( $services, $user, 18 );
        $storedBirthDate = self::getUserBirthDateDefault( $services, $user );
        $preferences[self::OPT_BIRTHDATE] = [
            'type' => 'date',
            'label-message' => 'nsfwblur-birthdate-label',
            'help-message' => 'nsfwblur-birthdate-help',
            'section' => 'personal/info',
            'default' => $storedBirthDate,
            'min' => '1900-01-01',
            'max' => date( 'Y-m-d' ),
            'validation-callback' => [ self::class, 'validateBirthDatePreference' ],
        ];
        $preferences['nsfwblurred'] = [
            'type' => 'toggle',
            'label-message' => 'tog-nsfwblurred',
            'section' => 'rendering/files',
            'default' => false,
            'help-message' => !$canSeeNSFW ? 'nsfwblur-pref-nsfw-age' : null,
            'validation-callback' => [ self::class, 'validateNsfwUnblurPreference' ],
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

    /** Helper: get default birth date for preference display */
    private static function getUserBirthDateDefault( MediaWikiServices $services, User $user ): string {
        $val = $services->getUserOptionsLookup()->getOption( $user, self::OPT_BIRTHDATE, '' );
        if ( $val === '' || $val === null ) {
            return '';
        }

        $normalized = self::normalizeBirthDateValue( $val );
        return $normalized ?? '';
    }

    /** Helper: Get user birth date from user options (private "custom profile field") */
    private static function getUserPrivateBirthDate( MediaWikiServices $services, User $user ): ?string {
        $val = $services->getUserOptionsLookup()->getOption( $user, self::OPT_BIRTHDATE, '' );
        if ( $val === '' || $val === null ) {
            return null;
        }

        $normalized = self::normalizeBirthDateValue( $val );
        return $normalized ?: null;
    }

    /** Normalize stored birth date values (accepts YYYY-MM-DD, YYYY, or YYYY-MM-DD HH:MM:SS) */
    private static function normalizeBirthDateValue( $value ): ?string {
        $value = trim( (string)$value );
        if ( $value === '' ) {
            return null;
        }

        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
            return $value;
        }

        if ( preg_match( '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $value ) ) {
            return substr( $value, 0, 10 );
        }

        if ( preg_match( '/^\d{4}$/', $value ) ) {
            return $value . '-01-01';
        }

        return null;
    }

    /** Parse a normalized YYYY-MM-DD date into a DateTimeImmutable */
    private static function parseBirthDate( string $date ): ?\DateTimeImmutable {
        $birthDate = \DateTimeImmutable::createFromFormat( 'Y-m-d', $date );
        if ( !$birthDate ) {
            return null;
        }

        $errors = \DateTimeImmutable::getLastErrors();
        if ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) {
            return null;
        }

        return $birthDate;
    }

    /** Helper: Age check */
    private static function isUserOldEnoughForNSFW(
        MediaWikiServices $services,
        User $user,
        int $minAge = self::MIN_AGE
    ): bool {
        $birthDate = self::getUserPrivateBirthDate( $services, $user );
        if ( !$birthDate ) {
            return false;
        }

        $birthDateObj = self::parseBirthDate( $birthDate );
        if ( !$birthDateObj ) {
            return false;
        }

        $now = new \DateTimeImmutable( 'now' );
        $age = $birthDateObj->diff( $now )->y;
        return $age >= $minAge;
    }

    /** Validate birth date preference input */
    public static function validateBirthDatePreference( $value, $alldata = null, $user = null ): bool|string {
        if ( $value === '' || $value === null ) {
            return true;
        }

        $normalized = self::normalizeBirthDateValue( $value );
        if ( !$normalized || !self::parseBirthDate( $normalized ) ) {
            return wfMessage( 'nsfwblur-birthdate-invalid' )->text();
        }

        return true;
    }

    /** Validate the unblur toggle so underage users cannot enable it */
    public static function validateNsfwUnblurPreference( $value, $alldata = null, $user = null ): bool|string {
        if ( !$value ) {
            return true;
        }

        $services = MediaWikiServices::getInstance();
        $birthDate = null;
        if ( is_array( $alldata ) && array_key_exists( self::OPT_BIRTHDATE, $alldata ) ) {
            $birthDate = $alldata[self::OPT_BIRTHDATE];
        }

        if ( $birthDate !== null && $birthDate !== '' ) {
            $normalized = self::normalizeBirthDateValue( $birthDate );
            if ( $normalized ) {
                $birthDateObj = self::parseBirthDate( $normalized );
                if ( $birthDateObj ) {
                    $age = $birthDateObj->diff( new \DateTimeImmutable( 'now' ) )->y;
                    if ( $age >= self::MIN_AGE ) {
                        return true;
                    }
                }
            }
        } elseif ( $user instanceof User ) {
            if ( self::isUserOldEnoughForNSFW( $services, $user, self::MIN_AGE ) ) {
                return true;
            }
        }

        return wfMessage( 'nsfwblur-pref-nsfw-age' )->text();
    }

    /** Validate birth year preference input */
    public static function validateBirthYearPreference( $value, $alldata = null, $user = null ): bool|string {
        if ( $value === '' || $value === null ) {
            return true;
        }

        $year = (int)$value;
        if ( $year <= 0 ) {
            return wfMessage( 'nsfwblur-birthyear-invalid' )->text();
        }

        $thisYear = (int)date( 'Y' );
        if ( $year < 1900 || $year > $thisYear ) {
            return wfMessage( 'nsfwblur-birthyear-invalid' )->text();
        }

        return true;
    }
    /** Optional helper: reset both options for a user */
    public static function resetNSFWOptionsForUser( UserIdentity $user ): void {
        $services = MediaWikiServices::getInstance();
        $mgr = $services->getUserOptionsManager();

        $mgr->setOption( $user, self::OPT_BIRTHDATE, '' );
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
    private static function applyFilePageBlurClass(
        OutputPage $out,
        \MediaWiki\MediaWikiServices $services,
        bool $userWantsUnblur
    ): void {
        $title = $out->getTitle();
        if ( !$title || !$title->inNamespace( NS_FILE ) ) {
            return;
        }

        if ( $userWantsUnblur ) {
            return; // user opted out of blur
        }

        if ( self::isFileTitleMarkedNSFW( $services, $title ) ) {
            $out->addBodyClasses( 'nsfw-filepage-blur' );
            $out->addJsConfigVars( 'wgNSFWFilePage', true );
        }
    }


}
