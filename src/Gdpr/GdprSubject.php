<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Gdpr;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Whose data a psgdpr request is about. psgdpr passes different shapes depending on its version and
 * on how the merchant searched:
 *  - 1.4.x: the customer as an array (`(array) Customer`, with `id` and `email`), or `['email' => ...]`;
 *  - 2.x export: the same; 2.x erasure: `[0 => '<id_customer>']`, `[0 => ['email' => ...]]` or
 *    `[0 => ['phone' => ...]]`.
 */
final class GdprSubject
{
    private function __construct(
        public readonly ?int $customerId,
        public readonly string $email,
        public readonly string $phone,
    ) {
    }

    /**
     * @param array<mixed> $params the hook parameters
     */
    public static function fromHookParams(array $params): ?self
    {
        if (!isset($params['id']) && !isset($params['email']) && !isset($params['phone']) && array_key_exists(0, $params)) {
            $inner = $params[0];
            if (is_array($inner)) {
                return self::fromHookParams($inner);
            }
            if (is_numeric($inner) && (int) $inner > 0) {
                return new self((int) $inner, '', '');
            }

            return null;
        }

        $id = $params['id'] ?? $params['id_customer'] ?? null;
        $customerId = is_numeric($id) && (int) $id > 0 ? (int) $id : null;
        $email = is_string($params['email'] ?? null) ? trim($params['email']) : '';
        $phone = is_string($params['phone'] ?? null) ? trim($params['phone']) : '';

        if ($customerId === null && $email === '' && $phone === '') {
            return null;
        }

        return new self($customerId, $email, $phone);
    }

    public function describe(): string
    {
        return match (true) {
            $this->customerId !== null => 'customer ' . $this->customerId,
            $this->email !== '' => 'an e-mail address',
            default => 'a phone number',
        };
    }
}
