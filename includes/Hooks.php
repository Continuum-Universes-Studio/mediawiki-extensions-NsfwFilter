<?php
namespace NSFWBlur;
if ( !defined( 'MEDIAWIKI' ) ) die();

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use OutputPage;
use Skin;
use RequestContext;
use Parser;

class Hooks {

    /** Adds JS config vars for age/gating */
    public static function onBeforePageDisplay( OutputPage $out, Skin $skin ) {
        $user = $out->getUser();
        $birthYear = null;
        if ( $user->isRegistered() ) {
            $birthYear = self::getUserPrivateBirthYear( $user );
        }
        $out->addJsConfigVars( 'wgPrivateBirthYear', $birthYear );
        $out->addModules( 'ext.nsfwblur' );
        $out->addModuleStyles( 'ext.nsfwblur' );
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
    public static function onPageRenderingHash( $out, &$hash ) {
        $user = RequestContext::getMain()->getUser();
        $userOptionsLookup = MediaWikiServices::getInstance()->getUserOptionsLookup();
        $nsfwblurred = $userOptionsLookup->getOption( $user, 'nsfwblurred' ); // match pref name!
        $hash .= '!' . ( $nsfwblurred ? '1' : '0' );
        return true;
    }

    /** ImageBeforeProduceHTML: Blur if needed */
    public static function onImageBeforeProduceHTML(
        &$skin, &$title, &$file, &$frameParams, &$handlerParams, &$time, &$res
    ) {
        $user = RequestContext::getMain()->getUser();
        $userOptionsLookup = MediaWikiServices::getInstance()->getUserOptionsLookup();
        if ( $userOptionsLookup->getOption( $user, 'nsfwblurred' ) ) {
            // User wants to see NSFW images, don't blur
            return true;
        }
        if ( !$file ) return true;
        $revisionLookup = MediaWikiServices::getInstance()->getRevisionLookup();
        $filePageTitle = $file->getTitle();
        $revision = $revisionLookup->getRevisionByTitle( $filePageTitle );
        if ( !$revision ) return true;
        $content = $revision->getContent( SlotRecord::MAIN, RevisionRecord::FOR_PUBLIC );
        if ( !$content ) return true;
        $desc = $content->getText();
        if ( strpos( $desc, '__NSFW__' ) === false ) return true;

        if ( !isset( $frameParams['class'] ) ) {
            $frameParams['class'] = 'nsfw-blur';
        } else {
            $frameParams['class'] .= ' nsfw-blur';
        }
        if ( isset( $frameParams['parser'] ) && $frameParams['parser'] instanceof Parser ) {
            $frameParams['parser']->getOutput()->addImage( $file->getName() );
        }
        return true;
    }

    /** Helper: Get user birth year from social profile table */
    private static function getUserPrivateBirthYear( $user ) {
        $dbr = MediaWikiServices::getInstance()
            ->getDBLoadBalancer()
            ->getConnection( DB_REPLICA );
        $row = $dbr->selectRow(
            'user_profile',
            [ 'private_birthyear' ],
            [ 'up_actor' => $user->getActorId() ],
            __METHOD__
        );
        return $row && $row->private_birthyear ? intval($row->private_birthyear) : null;
    }

    /** Helper: Age check */
    private static function isUserOldEnoughForNSFW( $user, $minAge = 18 ) {
        $birthYear = self::getUserPrivateBirthYear( $user );
        if ( !$birthYear ) return false;
        $thisYear = intval( date( 'Y' ) );
        return ( $thisYear - $birthYear ) >= $minAge;
    }

    public static function resetNSFWBlurredOptionForUser( UserIdentity $user ) {
        $userOptionsManager = MediaWikiServices::getInstance()->getUserOptionsManager();
        // Force the option off for this user
        $userOptionsManager->setOption( $user, 'nsfwblurred', false );
        // Save immediately to the database
        $userOptionsManager->saveOptions( $user );
    }

}