<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2021, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

require_once dirname(__DIR__).'/vendor/autoload.php';
$baseDir = dirname(__DIR__);

use LC\Portal\Storage;

try {
    $dataDir = sprintf('%s/data', $baseDir);
    $db = new PDO(sprintf('sqlite://%s/db.sqlite', $dataDir));
    $storage = new Storage(
        $db,
        sprintf('%s/schema', $baseDir),
        new DateInterval('P90D')    // XXX remove 3rd parameter!
    );

    $storage->cleanConnectionLog(new DateTime('now -32 days'));
    $storage->cleanExpiredCertificates(new DateTime('now -7 days'));
    $storage->cleanOtpLog(new DateTime('now -5 minutes'));
    $storage->cleanUserMessages(new DateTime('now -32 days'));
} catch (Exception $e) {
    echo sprintf('ERROR: %s', $e->getMessage()).\PHP_EOL;
    exit(1);
}
