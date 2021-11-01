<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2021, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace LC\Portal\Http;

use DateTimeImmutable;
use fkooman\OAuth\Server\AccessToken;
use LC\Portal\Config;
use LC\Portal\ConnectionManager;
use LC\Portal\ServerInfo;
use LC\Portal\Storage;
use LC\Portal\Validator;

class VpnApiThreeModule implements ServiceModuleInterface
{
    private Config $config;
    private Storage $storage;
    private ServerInfo $serverInfo;
    private ConnectionManager $connectionManager;

    public function __construct(Config $config, Storage $storage, ServerInfo $serverInfo, ConnectionManager $connectionManager)
    {
        $this->config = $config;
        $this->storage = $storage;
        $this->serverInfo = $serverInfo;
        $this->connectionManager = $connectionManager;
    }

    public function init(ServiceInterface $service): void
    {
        $service->get(
            '/v3/info',
            function (AccessToken $accessToken, Request $request): Response {
                $profileConfigList = $this->config->profileConfigList();
                // XXX really think about storing permissions in OAuth token!
                $userPermissions = $this->storage->userPermissionList($accessToken->userId());
                $userProfileList = [];
                foreach ($profileConfigList as $profileConfig) {
                    if ($profileConfig->enableAcl()) {
                        // is the user member of the aclPermissionList?
                        if (!VpnPortalModule::isMember($profileConfig->aclPermissionList(), $userPermissions)) {
                            continue;
                        }
                    }

                    $vpnProtoSupport = [];
                    if ($profileConfig->oSupport()) {
                        $vpnProtoSupport[] = 'openvpn';
                    }
                    if ($profileConfig->wSupport()) {
                        $vpnProtoSupport[] = 'wireguard';
                    }

                    $userProfileList[] = [
                        'profile_id' => $profileConfig->profileId(),
                        'display_name' => $profileConfig->displayName(),
                        'vpn_proto_support' => $vpnProtoSupport,
                        'default_gateway' => $profileConfig->defaultGateway(),
                    ];
                }

                return new JsonResponse(
                    [
                        'info' => [
                            'profile_list' => $userProfileList,
                        ],
                    ]
                );
            }
        );

        $service->post(
            '/v3/connect',
            function (AccessToken $accessToken, Request $request): Response {
                // make sure all client configurations / connections initiated
                // by this client are removed / disconnected
                $this->connectionManager->disconnectByAuthKey($accessToken->authKey());

                // XXX catch InputValidationException
                $requestedProfileId = $request->requirePostParameter('profile_id', fn (string $s) => Validator::profileId($s));
                $profileConfigList = $this->config->profileConfigList();
                $userPermissions = $this->storage->userPermissionList($accessToken->userId());
                $availableProfiles = [];
                foreach ($profileConfigList as $profileConfig) {
                    if ($profileConfig->enableAcl()) {
                        // is the user member of the userPermissions?
                        if (!VpnPortalModule::isMember($profileConfig->aclPermissionList(), $userPermissions)) {
                            continue;
                        }
                    }

                    $availableProfiles[] = $profileConfig->profileId();
                }

                if (!\in_array($requestedProfileId, $availableProfiles, true)) {
                    return new JsonResponse(['error' => 'profile not available'], [], 400);
                }

                $profileConfig = $this->config->profileConfig($requestedProfileId);

                if (null === $vpnProto = $request->optionalPostParameter('vpn_proto', fn (string $s) => Validator::vpnProto($s))) {
                    // if OpenVPN is supported, that is the default (for now)
                    // XXX make this configurable
                    if ($profileConfig->oSupport()) {
                        $vpnProto = 'openvpn';
                    } else {
                        $vpnProto = 'wireguard';
                    }
                }

                // XXX we can make this independent I think?

                if ('openvpn' === $vpnProto && $profileConfig->oSupport()) {
                    $tcpOnly = 'on' === $request->optionalPostParameter('tcp_only', fn (string $s) => Validator::onOrOff($s));
                    $clientConfig = $this->connectionManager->connect(
                        $this->serverInfo,
                        $accessToken->userId(),
                        $profileConfig->profileId(),
                        'openvpn',
                        $accessToken->clientId(),
                        $accessToken->authorizationExpiresAt(),
                        $tcpOnly,
                        null,
                        $accessToken->authKey(),
                    );

                    return new Response(
                        $clientConfig->get(),
                        [
                            'Expires' => $accessToken->authorizationExpiresAt()->format(DateTimeImmutable::RFC7231),
                            'Content-Type' => $clientConfig->contentType(),
                        ]
                    );
                }

                if ('wireguard' === $vpnProto && $profileConfig->wSupport()) {
                    $clientConfig = $this->connectionManager->connect(
                        $this->serverInfo,
                        $accessToken->userId(),
                        $profileConfig->profileId(),
                        'wireguard',
                        $accessToken->clientId(),
                        $accessToken->authorizationExpiresAt(),
                        false,
                        $request->requirePostParameter('public_key', fn (string $s) => Validator::publicKey($s)),
                        $accessToken->authKey()
                    );

                    return new Response(
                        $clientConfig->get(),
                        [
                            'Expires' => $accessToken->authorizationExpiresAt()->format(DateTimeImmutable::RFC7231),
                            'Content-Type' => $clientConfig->contentType(),
                        ]
                    );
                }

                return new JsonResponse(['error' => sprintf('profile "%s" does not support protocol "%s"', $profileConfig->profileId(), $vpnProto)], [], 400);
            }
        );

        $service->post(
            '/v3/disconnect',
            function (AccessToken $accessToken, Request $request): Response {
                $this->connectionManager->disconnectByAuthKey($accessToken->authKey());

                return new Response(null, [], 204);
            }
        );
    }
}
