<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2014-2023, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace Vpn\Portal\OAuth;

use fkooman\OAuth\Server\ClientInfo;
use fkooman\OAuth\Server\Scope;
use fkooman\OAuth\Server\SimpleClientDb;
use Vpn\Portal\FileIO;
use Vpn\Portal\Json;

class VpnClientDb extends SimpleClientDb
{
    public function __construct(string $jsonClientDbFile)
    {
        $this->defaultClientRegistration();
        $this->fromJsonFile($jsonClientDbFile);
    }

    private function fromJsonFile(string $jsonClientDbFile): void
    {
        if (!FileIO::exists($jsonClientDbFile)) {
            return;
        }
        $clientDbData = Json::decode(FileIO::read($jsonClientDbFile));
        foreach ($clientDbData as $clientInfoData) {
            $this->add(ClientInfo::fromData($clientInfoData));
        }
    }

    private function defaultClientRegistration(): void
    {
        //
        // eduVPN
        //
        $this->add(
            new ClientInfo(
                'org.eduvpn.app.windows',
                ['http://127.0.0.1:{PORT}/callback', 'http://[::1]:{PORT}/callback'],
                null,
                'eduVPN for Windows',
                true,
                new Scope('config')
            )
        );
        $this->add(
            new ClientInfo(
                'org.eduvpn.app.android',
                // should have been org.eduvpn.app.android:/api/callback
                ['org.eduvpn.app:/api/callback'],
                null,
                'eduVPN for Android',
                true,
                new Scope('config')
            )
        );
        $this->add(
            new ClientInfo(
                'org.eduvpn.app.ios',
                ['org.eduvpn.app.ios:/api/callback'],
                null,
                'eduVPN for iOS',
                true,
                new Scope('config')
            )
        );
        $this->add(
            new ClientInfo(
                'org.eduvpn.app.macos',
                ['http://127.0.0.1:{PORT}/callback', 'http://[::1]:{PORT}/callback'],
                null,
                'eduVPN for macOS',
                true,
                new Scope('config')
            )
        );
        $this->add(
            new ClientInfo(
                'org.eduvpn.app.linux',
                ['http://127.0.0.1:{PORT}/callback', 'http://[::1]:{PORT}/callback'],
                null,
                'eduVPN for Linux',
                true,
                new Scope('config')
            )
        );

        //
        // Let's Connect!
        //
        $this->add(
            new ClientInfo(
                'org.letsconnect-vpn.app.windows',
                ['http://127.0.0.1:{PORT}/callback', 'http://[::1]:{PORT}/callback'],
                null,
                'Let\'s Connect! for Windows',
                true,
                new Scope('config')
            )
        );
        $this->add(
            new ClientInfo(
                'org.letsconnect-vpn.app.android',
                // should have been org.eduvpn.app.android:/api/callback
                ['org.letsconnect-vpn.app:/api/callback'],
                null,
                'Let\'s Connect! for Android',
                true,
                new Scope('config')
            )
        );
        $this->add(
            new ClientInfo(
                'org.letsconnect-vpn.app.ios',
                ['org.letsconnect-vpn.app.ios:/api/callback'],
                null,
                'Let\'s Connect! for iOS',
                true,
                new Scope('config')
            )
        );
        $this->add(
            new ClientInfo(
                'org.letsconnect-vpn.app.macos',
                ['http://127.0.0.1:{PORT}/callback', 'http://[::1]:{PORT}/callback'],
                null,
                'Let\'s Connect! for macOS',
                true,
                new Scope('config')
            )
        );
        $this->add(
            new ClientInfo(
                'org.letsconnect-vpn.app.linux',
                ['http://127.0.0.1:{PORT}/callback', 'http://[::1]:{PORT}/callback'],
                null,
                'Let\'s Connect! for Linux',
                true,
                new Scope('config')
            )
        );
    }
}
