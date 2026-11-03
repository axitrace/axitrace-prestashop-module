<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Config;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Why a configured tracking domain was refused (the module then uses stat.axitrace.com).
 */
enum EndpointError: string
{
    case InsecureScheme = 'insecure_scheme';
    case IpLiteral = 'ip_literal';
    case InvalidHost = 'invalid_host';
    case Unresolvable = 'unresolvable';
    case PrivateAddress = 'private_address';
}
