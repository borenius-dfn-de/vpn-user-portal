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

use fkooman\OAuth\Server\Signer;
use Vpn\Portal\Cfg\Config;
use Vpn\Portal\ConnectionHooks;
use Vpn\Portal\ConnectionManager;
use Vpn\Portal\Dt;
use Vpn\Portal\Expiry;
use Vpn\Portal\FileIO;
use Vpn\Portal\Http\AdminApiModule;
use Vpn\Portal\Http\AdminApiService;
use Vpn\Portal\Http\Auth\AdminApiAuthModule;
use Vpn\Portal\Http\JsonResponse;
use Vpn\Portal\Http\Request;
use Vpn\Portal\HttpClient\CurlHttpClient;
use Vpn\Portal\OpenVpn\CA\VpnCa;
use Vpn\Portal\OpenVpn\TlsCrypt;
use Vpn\Portal\ServerInfo;
use Vpn\Portal\Storage;
use Vpn\Portal\SysLogger;
use Vpn\Portal\VpnDaemon;

// only allow owner permissions
umask(0077);

$logger = new SysLogger('vpn-user-portal');

try {
    $adminApiKeyFile = sprintf('%s/config/keys/admin-api.key', $baseDir);
    if (!FileIO::exists($adminApiKeyFile)) {
        throw new Exception('no admin API key set, admin API disabled');
    }

    $dateTime = Dt::get();
    $request = Request::createFromGlobals();
    FileIO::mkdir($baseDir.'/data');
    $config = Config::fromFile($baseDir.'/config/config.php');
    $storage = new Storage($config->dbConfig($baseDir));
    $ca = new VpnCa($baseDir.'/config/keys/ca', $config->vpnCaPath());
    $oauthKey = FileIO::read($baseDir.'/config/keys/oauth.key');
    $service = new AdminApiService(
        new AdminApiAuthModule(
            $adminApiKeyFile,
            'Admin API'
        )
    );
    $serverInfo = new ServerInfo(
        $request->getRootUri(),
        $baseDir.'/data/keys',
        $ca,
        new TlsCrypt($baseDir.'/data/keys'),
        $config->wireGuardConfig(),
        Signer::publicKeyFromSecretKey($oauthKey)
    );

    $connectionManager = new ConnectionManager($config, new VpnDaemon(new CurlHttpClient($baseDir.'/config/keys/vpn-daemon'), $logger), $storage, ConnectionHooks::init($config, $storage, $logger), $logger);

    $sessionExpiry = new Expiry(
        $config->sessionExpiry(),
        [], // Admin API always uses the default sessionExpiry (for now)
        $dateTime,
        $ca->caCert()->validTo()
    );

    $service->addModule(
        new AdminApiModule(
            $config,
            $storage,
            $serverInfo,
            $connectionManager,
            $sessionExpiry
        )
    );

    $service->run($request)->send();
} catch (Exception $e) {
    $logger->error($e->getMessage());
    $response = new JsonResponse(['error' => $e->getMessage()], [], 500);
    $response->send();
}
