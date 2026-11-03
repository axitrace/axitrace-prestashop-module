<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Routes module strings through the PrestaShop translation system.
 */
final class ModuleTranslator implements TranslatorInterface
{
    public function __construct(private readonly \Module $module)
    {
    }

    public function trans(string $id, array $parameters, string $domain): string
    {
        return $this->module->getTranslator()->trans($id, $parameters, $domain);
    }
}
