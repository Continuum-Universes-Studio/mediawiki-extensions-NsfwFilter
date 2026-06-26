<?php

declare( strict_types = 1 );

namespace ContinuumUniverses\ContinuumNsfwFilter;

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use User;
use Wikimedia\Rdbms\IDatabase;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
    $IP = dirname( __DIR__, 3 );
}
require_once "$IP/maintenance/Maintenance.php";

class MarkExistingUsersNsfwUnblur extends Maintenance {
    private const ADULT_READER_GROUP = 'adultreader';
    private const OPT_BIRTHDATE = 'nsfw_birthdate';
    private const OPT_BIRTHDATE_LEGACY = 'nsfw_birthyear';

    private const TRUTHY_OPTIONS = [
        'nsfwblurred',
        'nsfwblurred_gore',
        'nsfwblurred_sexual',
    ];

    public function __construct() {
        parent::__construct();

        $this->addDescription(
            'Marks all existing registered users as opted in to NSFW unblur preferences.'
        );
        $this->addOption(
            'dry-run',
            'Report how many users would be updated without writing changes.'
        );
        $this->addOption(
            'skip-adultreader',
            'Only set preference values; do not grant adultreader to users with adult birthdates.'
        );
        $this->requireExtension( 'Continuum NSFW Filter' );
    }

    public function execute(): void {
        $dryRun = $this->hasOption( 'dry-run' );
        $skipAdultReader = $this->hasOption( 'skip-adultreader' );
        $dbw = $this->getDB( DB_PRIMARY );
        $services = MediaWikiServices::getInstance();
        $userGroupManager = $services->getUserGroupManager();
        $userOptionsManager = $services->getUserOptionsManager();

        $res = $dbw->select(
            'user',
            [ 'user_id' ],
            [ 'user_id > 0' ],
            __METHOD__,
            [ 'ORDER BY' => 'user_id' ]
        );

        $userCount = 0;
        $preferenceRows = 0;
        $eligibleAdultCount = 0;
        $adultReaderGranted = 0;
        $adultReaderAlreadyPresent = 0;

        foreach ( $res as $row ) {
            $userId = (int)$row->user_id;
            if ( $userId <= 0 ) {
                continue;
            }

            $user = User::newFromId( $userId );
            if ( method_exists( $user, 'isTemp' ) && $user->isTemp() ) {
                continue;
            }

            $userCount++;

            foreach ( self::TRUTHY_OPTIONS as $optionName ) {
                $preferenceRows++;
                if ( !$dryRun ) {
                    $this->setTruthyUserOption( $dbw, $userId, $optionName );
                }
            }

            if ( !$dryRun ) {
                $userOptionsManager->clearUserOptionsCache( $user );
            }

            if ( !$skipAdultReader && $this->hasAdultBirthdate( $dbw, $userId ) ) {
                $eligibleAdultCount++;
                if ( in_array( self::ADULT_READER_GROUP, $userGroupManager->getUserGroups( $user ), true ) ) {
                    $adultReaderAlreadyPresent++;
                    continue;
                }

                if ( $dryRun ) {
                    $adultReaderGranted++;
                } elseif ( $userGroupManager->addUserToGroup( $user, self::ADULT_READER_GROUP ) ) {
                    $adultReaderGranted++;
                }
            }

            if ( !$dryRun && $userCount % 100 === 0 ) {
                $this->waitForReplication();
            }
        }

        $prefix = $dryRun ? 'Would update' : 'Updated';
        $this->output( "$prefix $userCount registered users.\n" );
        $this->output( "$prefix $preferenceRows NSFW preference rows to truthy values.\n" );

        if ( !$skipAdultReader ) {
            $groupPrefix = $dryRun ? 'Would grant' : 'Granted';
            $this->output(
                "$groupPrefix " . self::ADULT_READER_GROUP . " to $adultReaderGranted users " .
                "with adult birthdates.\n"
            );
            $this->output(
                "$adultReaderAlreadyPresent adult users already had " . self::ADULT_READER_GROUP . ".\n"
            );
            $this->output( "$eligibleAdultCount users had an adult birthdate on file.\n" );
        }
    }

    private function setTruthyUserOption( IDatabase $dbw, int $userId, string $optionName ): void {
        $dbw->upsert(
            'user_properties',
            [
                [
                    'up_user' => $userId,
                    'up_property' => $optionName,
                    'up_value' => '1',
                ],
            ],
            [ [ 'up_user', 'up_property' ] ],
            [ 'up_value' => '1' ],
            __METHOD__
        );
    }

    private function hasAdultBirthdate( IDatabase $dbw, int $userId ): bool {
        $birthDate = $this->getStoredBirthdate( $dbw, $userId );
        return $birthDate !== null && Hooks::isBirthDateAtLeastMinimumAge( $birthDate );
    }

    private function getStoredBirthdate( IDatabase $dbw, int $userId ): ?string {
        foreach ( [ self::OPT_BIRTHDATE, self::OPT_BIRTHDATE_LEGACY ] as $optionName ) {
            $value = $dbw->selectField(
                'user_properties',
                'up_value',
                [
                    'up_user' => $userId,
                    'up_property' => $optionName,
                ],
                __METHOD__
            );
            if ( $value === false || $value === null || $value === '' ) {
                continue;
            }

            return Hooks::normalizeBirthDateValue( (string)$value );
        }

        return null;
    }
}

$maintClass = MarkExistingUsersNsfwUnblur::class;
require_once RUN_MAINTENANCE_IF_MAIN;
