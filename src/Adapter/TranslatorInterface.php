<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

interface TranslatorInterface
{
    /**
     * Translates an English source string. Callers pass the domain as a literal
     * ('Modules.Axitrace.Admin' or 'Modules.Axitrace.Shop') so PrestaShop's catalogue
     * extractor finds every string.
     *
     * @param array<string, string|int> $parameters
     */
    public function trans(string $id, array $parameters, string $domain): string;
}
