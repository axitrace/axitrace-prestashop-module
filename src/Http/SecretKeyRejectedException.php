<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Http;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * AxiTrace answered 401 to a request that carried the configured secret key: the key is wrong,
 * revoked, or belongs to another workspace. Retrying with the same key can never succeed, so a
 * purchase is resent once without the key (and without costs) and a refund is dropped.
 */
final class SecretKeyRejectedException extends \RuntimeException
{
}
