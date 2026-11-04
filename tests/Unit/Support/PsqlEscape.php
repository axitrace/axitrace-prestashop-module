<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

/**
 * What PrestaShop's Db::escape() does to a value, for the database doubles: the driver escapes
 * quotes, backslashes and newlines, then pSQL($value) (html_ok false) runs strip_tags() on the
 * result, so "Ring <5mm>" becomes "Ring " and a JSON document holding it is cut short.
 * pSQL($value, true) only escapes. Checked against classes/db/Db.php of 8.1.7 and 9.2.0.
 */
final class PsqlEscape
{
    public static function text(string $value): string
    {
        return strip_tags(addslashes($value));
    }

    public static function raw(string $value): string
    {
        return addslashes($value);
    }

    public static function like(string $value): string
    {
        return addcslashes(self::text($value), '%_');
    }
}
