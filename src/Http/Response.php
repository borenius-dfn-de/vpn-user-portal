<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2014-2022, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace Vpn\Portal\Http;

class Response
{
    private ?string $responseBody;

    /** @var array<string,string> */
    private array $responseHeaders = [];

    private int $statusCode;

    /**
     * @param array<string,string> $responseHeaders
     */
    public function __construct(?string $responseBody, array $responseHeaders = [], int $statusCode = 200)
    {
        $this->responseBody = $responseBody;
        $this->responseHeaders = $responseHeaders;
        $this->statusCode = $statusCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function responseBody(): ?string
    {
        return $this->responseBody;
    }

    /**
     * @return array<string,string>
     */
    public function responseHeaders(): array
    {
        return $this->responseHeaders;
    }

    public function send(): void
    {
        http_response_code($this->statusCode);
        foreach ($this->responseHeaders as $k => $v) {
            header($k.': '.$v);
        }
        if (null !== $this->responseBody) {
            echo $this->responseBody;
        }
    }
}
