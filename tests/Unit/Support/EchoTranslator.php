<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

use Axitrace\PrestaShop\Adapter\TranslatorInterface;

/** Returns the English source with parameters substituted, like PrestaShop without a catalogue. */
final class EchoTranslator implements TranslatorInterface
{
    /** @var list<string> */
    public array $domains = [];

    public function trans(string $id, array $parameters, string $domain): string
    {
        $this->domains[] = $domain;

        return strtr($id, array_map('strval', $parameters));
    }
}
