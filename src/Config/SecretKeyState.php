<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Config;

if (!defined('_PS_VERSION_')) {
    exit;
}

enum SecretKeyState: string
{
    /** No secret key saved for the scope: purchases are sent without costs, no refunds. */
    case Absent = 'absent';

    /** A valid secret key is available. */
    case Present = 'present';

    /** A key is saved but cannot be decrypted (shop key changed) or is malformed: re-enter it. */
    case Undecryptable = 'undecryptable';
}
