<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Http;

if (!defined('_PS_VERSION_')) {
    exit;
}

/** What a server-to-server delivery attempt means for the event. */
enum DeliveryOutcome: string
{
    /** 2xx: AxiTrace accepted the event. */
    case Sent = 'sent';

    /** No response, 408, 429, 5xx (or an unexpected status): queue and retry later. */
    case Retry = 'retry';

    /** 400, 413, 415: the payload itself is refused; a retry would get the same answer. */
    case Rejected = 'rejected';

    /** 401 without a secret key: the public key is unknown to AxiTrace. */
    case PublicKeyRejected = 'public_key_rejected';

    public function isFinalFailure(): bool
    {
        return $this === self::Rejected || $this === self::PublicKeyRejected;
    }
}
