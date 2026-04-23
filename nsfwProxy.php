<?php

use ContinuumNsfwFilter\Hooks;

define( 'MW_NO_OUTPUT_COMPRESSION', 1 );
define( 'MW_ENTRY_POINT', 'nsfwProxy' );

require dirname( __DIR__, 2 ) . '/includes/WebStart.php';

Hooks::handleProxyRequest();
