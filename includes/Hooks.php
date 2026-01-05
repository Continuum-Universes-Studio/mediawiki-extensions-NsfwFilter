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
use MediaWiki\Pager\ImageListPager;
use MediaWiki\User\UserRigorOptions;

class Hooks {

    private const NSFW_MARKER = '__NSFW__';

    private const OPT_UNBLUR            = 'nsfwblurred';
    private const OPT_BIRTHDATE         = 'nsfw_birthdate';
    private const OPT_BIRTHDATE_LEGACY  = 'nsfw_birthyear';

    private const MIN_AGE = 18;

    /* ============================================================
     *  PAGE DISPLAY
     * ========================================================== */

    public static function onBeforePageDisplay(
        OutputPage $out,
        Skin $skin
    ): bool {
        $services = MediaWikiServices::getInstance();
        $user = $out->getUser();

        $userWantsUnblur = self::userWantsUnblur( $services, $user );
        $nsfw = [];

        if (
            !$userWantsUnblur &&
            $out->getTitle() &&
            $out->getTitle()->isContentPage()
        ) {
            $images = $out->getProperty( 'nsfw-image-dbkeys' ) ?? [];

            foreach ( $images as $dbKey ) {
                $fileTitle = Title::makeTitleSafe( NS_FILE, $dbKey );
                if ( !$fileTitle ) {
                    continue;
                }

                if ( self::isFileTitleMarkedNSFW( $fileTitle ) ) {
                    $nsfw[] = $fileTitle->getPrefixedText();
                }
            }
        }

        $out->addJsConfigVars( [
            'wgNSFWUnblur' => $userWantsUnblur,
        ] );

        if ( $out->getTitle() && $out->getTitle()->isContentPage() ) {
            $out->addJsConfigVars( [
                'wgNSFWFilesOnPage' => array_values( array_unique( $nsfw ) ),
            ] );
        }

        $out->addInlineStyle( self::getEarlyInlineCss() );
        $out->addModules( [ 'ext.nsfwblur.top', 'ext.nsfwblur' ] );
        $out->addModuleStyles( [ 'ext.nsfwblur.styles' ] );

        self::applyFilePageBlurClass( $out, $services, $userWantsUnblur );

        return true;
    }




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

    /* ============================================================
 *  PARSER OUTPUT — AUTHORITATIVE NSFW LIST
 * ========================================================== */
    public static function onSpecialPageAfterExecute( $special, $subPage ): void {
        // Robust: don’t hard-depend on class names if you don’t want to.
        if ( !method_exists( $special, 'getName' ) || $special->getName() !== 'ListFiles' ) {
            return;
        }

        $out = $special->getOutput();
        $services = MediaWikiServices::getInstance();
        $user = $out->getUser();

        // If user can/has opted out of blur, keep list empty.
        if ( self::userWantsUnblur( $services, $user ) ) {
            $out->addJsConfigVars( 'wgNSFWFilesOnPage', [] );
            return;
        }

        $request = $special->getRequest();
        $including = method_exists( $special, 'including' ) ? (bool)$special->including() : false;

        // Match core’s parameter handling for Special:ListFiles. :contentReference[oaicite:3]{index=3}
        if ( $including ) {
            $userName = (string)$subPage;
            $search = '';
            $showAll = false;
        } else {
            $userName = $request->getText( 'user', $subPage ?? '' );
            $search   = $request->getText( 'ilsearch', '' );
            $showAll  = $request->getBool( 'ilshowall', false );
        }

        // Normalize username like core does.
        $canonical = $services->getUserNameUtils()->getCanonical( $userName, UserRigorOptions::RIGOR_NONE );
        if ( $canonical !== false ) {
            $userName = $canonical;
        }

        // Build the same pager core uses (SpecialListFiles -> ImageListPager). :contentReference[oaicite:4]{index=4}
        $pager = new ImageListPager(
            $special->getContext(),
            $services->getCommentStore(),
            $special->getLinkRenderer(),
            $services->getConnectionProvider(),
            $services->getRepoGroup(),
            $services->getUserNameUtils(),
            $services->getCommentFormatter(),
            $services->getLinkBatchFactory(),
            $userName,
            $search,
            $including,
            $showAll
        );

        // Run the pager query and inspect rows.
        // IndexPager::doQuery is public. :contentReference[oaicite:5]{index=5}
        $pager->doQuery();
        $res = $pager->getResult();

        $nsfw = [];

        foreach ( $res as $row ) {
            // ImageListPager includes img_name in its query fields. :contentReference[oaicite:6]{index=6}
            if ( empty( $row->img_name ) ) {
                continue;
            }

            $fileTitle = Title::makeTitleSafe( NS_FILE, $row->img_name );
            if ( !$fileTitle ) {
                continue;
            }

            if ( self::isFileTitleMarkedNSFW( $fileTitle ) ) {
                $nsfw[] = $fileTitle->getPrefixedText();
            }
        }

        $nsfw = array_values( array_unique( $nsfw ) );
        sort( $nsfw );

        $out->addJsConfigVars( 'wgNSFWFilesOnPage', $nsfw );
    }

    public static function onOutputPageParserOutput(
        OutputPage $out,
        ParserOutput $parserOutput
    ): void {
        $services = MediaWikiServices::getInstance();
        $user = $out->getUser();

        if ( self::userWantsUnblur( $services, $user ) ) {
            $parserOutput->addJsConfigVars( 'wgNSFWFilesOnPage', [] );
            return;
        }

        // 🔑 THIS is the correct call
        $images = $parserOutput->getImages();
        if ( !$images ) {
            $parserOutput->addJsConfigVars( 'wgNSFWFilesOnPage', [] );
            return;
        }

        $nsfw = [];

        foreach ( array_keys( $images ) as $dbKey ) {
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

        $parserOutput->addJsConfigVars( 'wgNSFWFilesOnPage', $nsfw );
    }




    /* ============================================================
     *  PREFERENCES
     * ========================================================== */

    public static function onGetPreferences( $user, &$preferences ): bool {
        $services = MediaWikiServices::getInstance();

        $canSeeNSFW = self::isUserOldEnoughForNSFW( $services, $user );
        $defaultBirthdate = self::getUserBirthDateDefault( $services, $user );

        $preferences[self::OPT_BIRTHDATE] = [
            'type' => 'date',
            'label-message' => 'nsfwblur-birthdate-label',
            'help-message'  => 'nsfwblur-birthdate-help',
            'section' => 'personal/info',
            'default' => $defaultBirthdate,
            'min' => '1900-01-01',
            'max' => date( 'Y-m-d' ),
            'validation-callback' => [ self::class, 'validateBirthDatePreference' ],
        ];

        $preferences[self::OPT_UNBLUR] = [
            'type' => 'toggle',
            'label-message' => 'tog-nsfwblurred',
            'section' => 'rendering/files',
            'disabled' => !$canSeeNSFW,
            'help-message' => !$canSeeNSFW ? 'nsfwblur-pref-nsfw-age' : null,
            'validation-callback' => [ self::class, 'validateNsfwUnblurPreference' ],
        ];

        return true;
    }

    /* ============================================================
     *  IMAGE BLUR
     * ========================================================== */

    public static function onImageBeforeProduceHTML(
        &$skin,
        &$title,
        &$file,
        &$frameParams,
        &$handlerParams,
        &$time,
        &$res
    ): bool {
        if ( !$file ) {
            return true;
        }

        $fileTitle = $file->getTitle();
        if ( !$fileTitle ) {
            return true;
        }

        // ✅ Authoritative check
        if ( !self::isFileTitleMarkedNSFW( $fileTitle ) ) {
            return true;
        }

        // Apply blur classes
        $frameParams['class'] =
            trim( ($frameParams['class'] ?? '') . ' nsfw-blur' );
        $frameParams['img-class'] =
            trim( ($frameParams['img-class'] ?? '') . ' nsfw-blur' );
        $handlerParams['class'] =
            trim( ($handlerParams['class'] ?? '') . ' nsfw-blur' );

        return true;
    }


    /* ============================================================
     *  AGE / BIRTHDATE
     * ========================================================== */

    private static function getUserBirthDateOption(
        MediaWikiServices $services,
        User $user
    ): ?string {
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

    private static function getUserBirthDateDefault(
        MediaWikiServices $services,
        User $user
    ): string {
        $val = self::getUserBirthDateOption( $services, $user );
        if ( !$val ) {
            return '';
        }

        return self::normalizeBirthDateValue( $val ) ?? '';
    }

    private static function getUserBirthYear(
        MediaWikiServices $services,
        User $user
    ): ?int {
        $date = self::getUserBirthDateDefault( $services, $user );
        return $date ? (int)substr( $date, 0, 4 ) : null;
    }

    private static function isUserOldEnoughForNSFW(
        MediaWikiServices $services,
        User $user
    ): bool {
        $date = self::getUserBirthDateDefault( $services, $user );
        if ( !$date ) {
            return false;
        }

        $birth = strtotime( $date );
        return ( time() - $birth ) >= ( self::MIN_AGE * 31557600 );
    }

    /* ============================================================
     *  NSFW MARKER DETECTION (HARDENED)
     * ========================================================== */

    private static function isFileTitleMarkedNSFW( Title $fileTitle ): bool {
        $services = MediaWikiServices::getInstance();
        $revLookup = $services->getRevisionLookup();

        $revision = $revLookup->getRevisionByTitle( $fileTitle );
        if ( !$revision ) {
            return false;
        }

        $content = $revision->getContent(
            SlotRecord::MAIN,
            RevisionRecord::FOR_PUBLIC
        );

        if ( !$content || !method_exists( $content, 'getText' ) ) {
            return false;
        }

        return strpos( $content->getText(), self::NSFW_MARKER ) !== false;
    }



    private static function getPageWikitext(
        MediaWikiServices $services,
        Title $title
    ): ?string {
        $rev = $services->getRevisionLookup()->getRevisionByTitle( $title );
        if ( !$rev ) {
            return null;
        }

        $content = $rev->getContent(
            SlotRecord::MAIN,
            RevisionRecord::RAW
        );

        if ( !$content ) {
            return null;
        }

        return method_exists( $content, 'getText' )
            ? $content->getText()
            : null;
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

        if ( self::isFileTitleMarkedNSFW( $title ) ) {
            $out->addBodyClasses( 'nsfw-filepage-blur' );
            $out->addJsConfigVars( 'wgNSFWFilePage', true );
        }
    }


    private static function userWantsUnblur(
        MediaWikiServices $services,
        User $user
    ): bool {
        return $user->isRegistered()
            && (bool)$services->getUserOptionsLookup()
                ->getOption( $user, self::OPT_UNBLUR );
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

    public static function validateNsfwUnblurPreference(
        $value, $alldata = null, $user = null
    ): bool|string {
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
}
