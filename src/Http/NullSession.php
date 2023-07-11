<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2014-2023, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace Vpn\Portal\Http;

/**
 * The do nothing session class.
 */
class NullSession implements SessionInterface
{
    public function get(string $sessionKey): ?string
    {
        return null;
    }

    public function set(string $sessionKey, string $sessionValue): void
    {
    }

    public function remove(string $sessionKey): void
    {
    }

    public function destroy(): void
    {
    }
}
