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

use Vpn\Portal\Cfg\Config;
use Vpn\Portal\ConnectionHooks;
use Vpn\Portal\Http\Auth\NodeAuthModule;
use Vpn\Portal\Http\JsonResponse;
use Vpn\Portal\Http\NodeApiModule;
use Vpn\Portal\Http\NodeApiService;
use Vpn\Portal\Http\Request;
use Vpn\Portal\OpenVpn\CA\VpnCa;
use Vpn\Portal\OpenVpn\ServerConfig as OpenVpnServerConfig;
use Vpn\Portal\OpenVpn\TlsCrypt;
use Vpn\Portal\ServerConfig;
use Vpn\Portal\Storage;
use Vpn\Portal\SysLogger;
use Vpn\Portal\WireGuard\ServerConfig as WireGuardServerConfig;

// only allow owner permissions
umask(0077);

$logger = new SysLogger('vpn-user-portal');

try {
    $config = Config::fromFile($baseDir.'/config/config.php');
    $service = new NodeApiService(
        new NodeAuthModule(
            $baseDir,
            'Node API'
        )
    );

    $storage = new Storage($config->dbConfig($baseDir));
    $ca = new VpnCa($baseDir.'/config/keys/ca', $config->vpnCaPath());

    $nodeApiModule = new NodeApiModule(
        $config,
        $storage,
        new ServerConfig(
            new OpenVpnServerConfig($ca, new TlsCrypt($baseDir.'/data/keys')),
            new WireGuardServerConfig($baseDir.'/data/keys', $config->wireGuardConfig()),
        ),
        ConnectionHooks::init($config, $storage, $logger),
        $logger
    );

    $service->addModule($nodeApiModule);
    $request = Request::createFromGlobals();
    $service->run($request)->send();
} catch (Exception $e) {
    $logger->error($e->getMessage());
    $response = new JsonResponse(['error' => $e->getMessage()], [], 500);
    $response->send();
}
