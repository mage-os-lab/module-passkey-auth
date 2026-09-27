<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\WebAuthn;

/**
 * Derives the WebAuthn RP ID and origin from a store base URL.
 */
trait BaseUrlParserTrait
{
    private function parseRpId(string $baseUrl): string
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new \RuntimeException('Cannot determine RP ID: base URL has no host component.');
        }
        return $host;
    }

    private function parseOrigin(string $baseUrl): string
    {
        $parsed = parse_url($baseUrl);
        if (!isset($parsed['scheme'], $parsed['host'])) {
            throw new \RuntimeException('Cannot determine origin: base URL is missing scheme or host.');
        }
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        return $parsed['scheme'] . '://' . $parsed['host'] . $port;
    }
}
