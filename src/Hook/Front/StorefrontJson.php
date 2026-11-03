<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Hook\Front;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * JSON for a `<script type="application/json">` element: `<`, `>`, `&`, `'` and `"` are escaped
 * as \u00XX, so no value (a product name, a shop setting) can close the element or break out of it.
 */
final class StorefrontJson
{
    public const FLAGS = \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT
        | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR;

    /**
     * @param array<string, mixed> $data
     */
    public static function encode(array $data): string
    {
        // Shortest round-trip float representation (19.12, not 19.120000000000001): some PHP
        // builds and PrestaShop setups run with serialize_precision=17.
        $precision = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            return json_encode($data, self::FLAGS);
        } finally {
            if ($precision !== false) {
                ini_set('serialize_precision', $precision);
            }
        }
    }
}
