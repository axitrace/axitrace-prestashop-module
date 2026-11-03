<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\TranslatorInterface;
use Axitrace\PrestaShop\Config\EndpointError;
use Axitrace\PrestaShop\Config\ResolvedEndpoints;
use Axitrace\PrestaShop\Config\SecretKeyResult;
use Axitrace\PrestaShop\Config\SecretKeyState;

/**
 * Error banners at the top of the configuration page. Every failure that silently changes what the
 * module sends (developer override, refused tracking domain, undecryptable secret key) is shown.
 */
final class ConfigPageNotices
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * @return list<string> translated plain-text messages, rendered as error banners
     */
    public function errors(ResolvedEndpoints $endpoints, SecretKeyResult $secretKey): array
    {
        $t = $this->translator;
        $errors = [];

        if ($endpoints->overrideActive) {
            foreach (array_unique([$endpoints->apiBaseUrl, $endpoints->sdkBaseUrl]) as $url) {
                $errors[] = $t->trans('Developer endpoint override active: %url%', ['%url%' => $url], 'Modules.Axitrace.Admin');
            }
        }

        if ($endpoints->error !== null) {
            $errors[] = $t->trans(
                'The tracking domain %domain% was refused: %reason% The module uses stat.axitrace.com until you correct it.',
                ['%domain%' => $endpoints->rejectedDomain, '%reason%' => $this->reason($endpoints->error)],
                'Modules.Axitrace.Admin',
            );
        }

        if ($secretKey->state === SecretKeyState::Undecryptable) {
            $errors[] = $t->trans('The saved secret key can no longer be decrypted, most likely because the shop encryption key changed. Re-enter the secret key; until then purchases are sent without costs.', [], 'Modules.Axitrace.Admin');
        }

        return $errors;
    }

    private function reason(EndpointError $error): string
    {
        $t = $this->translator;

        return match ($error) {
            EndpointError::InsecureScheme => $t->trans('only HTTPS is allowed.', [], 'Modules.Axitrace.Admin'),
            EndpointError::IpLiteral => $t->trans('an IP address is not allowed, enter a host name.', [], 'Modules.Axitrace.Admin'),
            EndpointError::InvalidHost => $t->trans('it is not a valid host name.', [], 'Modules.Axitrace.Admin'),
            EndpointError::Unresolvable => $t->trans('the host name does not resolve in DNS.', [], 'Modules.Axitrace.Admin'),
            EndpointError::PrivateAddress => $t->trans('the host name points to a private or local network address.', [], 'Modules.Axitrace.Admin'),
        };
    }
}
