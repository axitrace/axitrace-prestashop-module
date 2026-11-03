<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The module's own CSRF token for back-office actions (the delivery log's "Retry now"): an HMAC of
 * the action and the employee id, keyed with the shop's secret cookie key, posted as a hidden
 * field. PrestaShop's URL token cannot be relied on alone: 9.x redirects a legacy module
 * configuration URL carrying ANY `token` to the Symfony route with a fresh valid `_token` (checked
 * on 9.2.0), while 8.x rejects a wrong one itself.
 */
final class ActionToken
{
    public function __construct(private readonly string $secret)
    {
    }

    public function forEmployee(string $action, int $idEmployee): string
    {
        if ($this->secret === '' || $idEmployee <= 0) {
            return '';
        }

        return hash_hmac('sha256', $action . '|' . $idEmployee, $this->secret);
    }

    public function isValid(string $action, int $idEmployee, string $given): bool
    {
        $expected = $this->forEmployee($action, $idEmployee);

        return $expected !== '' && $given !== '' && hash_equals($expected, $given);
    }
}
