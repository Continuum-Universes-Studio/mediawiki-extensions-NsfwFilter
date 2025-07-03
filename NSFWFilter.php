<?php
if ( !defined( 'MEDIAWIKI' ) ) die();

class NSFWBlurHooks {
    public static function onBeforePageDisplay( $out, $skin ) {
        $out->addModules( 'ext.nsfwblur' );
        return true;
    }
}


$wgExtensionCredits['other'][] = [
    'name'        => 'NSFWFilter',
    'author'      => 'Onika, Christian Daniel Jensen',
    'description' => 'Hides images whose file description contains "__NSFW__".',
    'version'     => '2.0-modern',
    'url'         => ''
];

// Hooks registration for MW 1.25+
$wgHooks['PageRenderingHash'][] = 'NSFWFilterHash';
$wgHooks['ImageBeforeProduceHTML'][] = 'NSFWFilterProduceHTML';
$wgHooks['GetPreferences'][] = 'NSFWFilterPreferences';

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;


// Always load our CSS module (before page display)
$wgHooks['BeforePageDisplay'][] = function( $out, $skin ) {
    $out->addModuleStyles( 'ext.nsfwblur' );
    return true;
};
$wgHooks['PageRenderingHash'][] = 'NSFWFilterHash';

/**
 * Adds a user preference for showing filtered images.
 */
function NSFWFilterPreferences( $user, &$preferences ) {
    $preferences['displayfiltered'] = [
        'type' => 'toggle',
        'label-message' => 'tog-displayfiltered',
        'section' => 'rendering/files',
    ];
    $preferences['nsfw_dateofbirth'] = [
        'type' => 'date',
        'label-message' => 'nsfwblur-pref-dob',
        'section' => 'personal/info',
    ];
    return true;
}

/**
 * Adds user preference to the page rendering hash for correct cache behavior.
 */function NSFWFilterHash( $out, &$hash ) {
    $user = RequestContext::getMain()->getUser();
    $userOptionsLookup = MediaWiki\MediaWikiServices::getInstance()->getUserOptionsLookup();
    $displayFiltered = $userOptionsLookup->getOption( $user, 'displayfiltered' );
    $hash .= '!' . ( $displayFiltered ? '1' : '0' );
    return true;
}



/**
 * Hides image if __NSFW__ is found in file description, unless user has opted out.
 */
function NSFWFilterProduceHTML( &$skin, &$title, &$file, &$frameParams, &$handlerParams, &$time, &$res ) {
    $user = RequestContext::getMain()->getUser();
    $userOptionsLookup = MediaWiki\MediaWikiServices::getInstance()->getUserOptionsLookup();
    if ( $userOptionsLookup->getOption( $user, 'displayfiltered' ) ) {
        // User wants to see NSFW images, don't blur them
        return true;
    }


    if ( !$file ) return true;

    // Get latest revision of the file description
    $revisionLookup = MediaWiki\MediaWikiServices::getInstance()->getRevisionLookup();
    $filePageTitle = $file->getTitle();
    $revision = $revisionLookup->getRevisionByTitle( $filePageTitle );
    if ( !$revision ) return true;

    $content = $revision->getContent( SlotRecord::MAIN, RevisionRecord::FOR_PUBLIC );
    if ( !$content ) return true;

    $desc = $content->getText();

    if ( strpos( $desc, '__NSFW__' ) === false ) {
        return true;
    }

    // Only add blur if the user does NOT want to see NSFW images unblurred
    if ( !$userOptionsLookup->getOption( $user, 'displayfiltered' ) ) {
        if ( !isset( $frameParams['class'] ) ) {
            $frameParams['class'] = 'nsfw-blur';
        } else {
            $frameParams['class'] .= ' nsfw-blur';
        }
    }
    // Add dependency so page gets re-parsed if image description changes
    if ( isset( $frameParams['parser'] ) && $frameParams['parser'] instanceof Parser ) {
        $frameParams['parser']->getOutput()->addImage( $file->getName() );
    }

    return true; // Always return true to let image render
}

$wgResourceModules['ext.nsfwblur'] = [
    'styles' => [ 'modules/nsfw-blur.css' ],
    'scripts' => [ 'modules/nsfw-blur.js' ],
    'localBasePath' => __DIR__,
    'remoteExtPath' => 'NSFWFilter',
    'targets' => [ 'desktop', 'mobile' ]
];

$wgHooks['BeforePageDisplay'][] = function( $out, $skin ) {
    $out->addModules( 'ext.nsfwblur' );
    return true;
};


