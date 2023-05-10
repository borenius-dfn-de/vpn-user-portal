<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2014-2023, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

require_once dirname(__DIR__).'/vendor/autoload.php';
$baseDir = dirname(__DIR__);

use fkooman\OAuth\Server\PdoStorage as OAuthStorage;
use Vpn\Portal\Cfg\Config;
use Vpn\Portal\ConnectionHooks;
use Vpn\Portal\ConnectionManager;
use Vpn\Portal\Dt;
use Vpn\Portal\Http\PasswdModule;
use Vpn\Portal\HttpClient\CurlHttpClient;
use Vpn\Portal\Storage;
use Vpn\Portal\SysLogger;
use Vpn\Portal\VpnDaemon;

const ACTION_USER_ADD = 'add';
const ACTION_USER_DISABLE = 'disable';
const ACTION_USER_ENABLE = 'enable';
const ACTION_USER_DELETE = 'delete';
const ACTION_USER_LIST = 'list';

function showHelp(): void
{
    echo '  --add USER-ID [--password PASSWORD]'.PHP_EOL;
    echo '        Add new *LOCAL* user account'.PHP_EOL;
    echo '  --enable USER-ID'.PHP_EOL;
    echo '        (Re)enable user account(*)'.PHP_EOL;
    echo '  --disable USER-ID'.PHP_EOL;
    echo '        Disable user account(*)'.PHP_EOL;
    echo '  --delete USER-ID [--force]'.PHP_EOL;
    echo '        Delete user account (data)'.PHP_EOL;
    echo '  --list'.PHP_EOL;
    echo '        List user accounts'.PHP_EOL;
    echo PHP_EOL;
    echo '(*) Only for accounts that have logged in at least once!'.PHP_EOL;
}

function requireUserId(?string $userId): string
{
    if (null === $userId || empty($userId)) {
        showHelp();

        throw new RuntimeException('USER-ID must be specified');
    }

    return $userId;
}

$logger = new SysLogger('vpn-user-portal');

try {
    /** @var ?string */
    $accountAction = null;

    $forceAction = false;
    $userId = null;
    $userPass = null;

    // parse CLI flags
    for ($i = 1; $i < $argc; ++$i) {
        if ('--add' === $argv[$i]) {
            $accountAction = ACTION_USER_ADD;
            if ($i + 1 < $argc) {
                $userId = $argv[$i + 1];
            }

            continue;
        }
        if ('--enable' === $argv[$i]) {
            $accountAction = ACTION_USER_ENABLE;
            if ($i + 1 < $argc) {
                $userId = $argv[++$i];
            }

            continue;
        }
        if ('--disable' === $argv[$i]) {
            $accountAction = ACTION_USER_DISABLE;
            if ($i + 1 < $argc) {
                $userId = $argv[++$i];
            }

            continue;
        }
        if ('--delete' === $argv[$i]) {
            $accountAction = ACTION_USER_DELETE;
            if ($i + 1 < $argc) {
                $userId = $argv[++$i];
            }

            continue;
        }
        if ('--force' === $argv[$i]) {
            $forceAction = true;
        }
        if ('--password' === $argv[$i]) {
            if ($i + 1 < $argc) {
                $userPass = $argv[++$i];
            }

            continue;
        }
        if ('--list' === $argv[$i]) {
            $accountAction = ACTION_USER_LIST;

            continue;
        }
        if ('--help' === $argv[$i] || '-h' === $argv[$i]) {
            showHelp();

            exit(0);
        }
    }

    $config = Config::fromFile($baseDir.'/config/config.php');
    $storage = new Storage($config->dbConfig($baseDir));
    $connectionHooks = ConnectionHooks::init($config, $storage, $logger);

    switch ($accountAction) {
        case ACTION_USER_LIST:
            if ('DbAuthModule' === $config->authModule()) {
                // list local user accounts
                foreach ($storage->localUserList() as $userId) {
                    echo $userId.PHP_EOL;
                }

                break;
            }
            // list users that ever authenticated
            foreach ($storage->userList() as $userInfo) {
                echo $userInfo->userId().PHP_EOL;
            }

            break;
        case ACTION_USER_ADD:
            $userId = requireUserId($userId);
            if ('DbAuthModule' !== $config->authModule()) {
                throw new RuntimeException('users can only be added when using DbAuthModule');
            }
            if (null === $userPass) {
                echo sprintf('Setting password for user "%s"', $userId).\PHP_EOL;
                // ask for password
                exec('stty -echo');
                echo 'Password: ';
                $userPass = trim(fgets(\STDIN));
                echo \PHP_EOL.'Password (repeat): ';
                $userPassRepeat = trim(fgets(\STDIN));
                exec('stty echo');
                echo \PHP_EOL;
                if ($userPass !== $userPassRepeat) {
                    throw new RuntimeException('specified passwords do not match');
                }
            }

            if (empty($userPass)) {
                throw new RuntimeException('Password cannot be empty');
            }
            $storage->localUserAdd($userId, PasswdModule::generatePasswordHash($userPass), Dt::get());

            break;
        case ACTION_USER_ENABLE:
            $userId = requireUserId($userId);
            // we only need to enable the user, no other steps required
            $storage->userEnable($userId);

            break;
        case ACTION_USER_DELETE:
            $userId = requireUserId($userId);
            $vpnDaemon = new VpnDaemon(new CurlHttpClient($baseDir.'/config/keys/vpn-daemon'), $logger);
            $connectionManager = new ConnectionManager($config, $vpnDaemon, $storage, $connectionHooks, $logger);
            if (!$forceAction) {
                echo 'Are you sure you want to DELETE user "'.$userId.'"? [y/N]: ';
                if ('y' !== trim(fgets(\STDIN))) {
                    break;
                }
            }

            // delete and disconnect all (active) VPN configurations
            // for this user
            $connectionManager->disconnectByUserId($userId);

            // delete all user data (except log)
            $storage->userDelete($userId);

            if ('DbAuthModule' === $config->authModule()) {
                // remove the user from the local database
                $storage->localUserDelete($userId);
            }

            break;
        case ACTION_USER_DISABLE:
            $userId = requireUserId($userId);
            $vpnDaemon = new VpnDaemon(new CurlHttpClient($baseDir.'/config/keys/vpn-daemon'), $logger);
            $connectionManager = new ConnectionManager($config, $vpnDaemon, $storage, $connectionHooks, $logger);
            $oauthStorage = new OAuthStorage($storage->dbPdo(), 'oauth_');
            $storage->userDisable($userId);

            // delete and disconnect all (active) VPN configurations
            // for this user
            $connectionManager->disconnectByUserId($userId);

            // revoke all OAuth authorizations
            foreach ($oauthStorage->getAuthorizations($userId) as $clientAuthorization) {
                $oauthStorage->deleteAuthorization($clientAuthorization->authKey());
            }

            break;
        default:
            showHelp();

            throw new RuntimeException('operation must be specified');
    }
} catch (Exception $e) {
    echo 'ERROR: '.$e->getMessage().\PHP_EOL;

    exit(1);
}
