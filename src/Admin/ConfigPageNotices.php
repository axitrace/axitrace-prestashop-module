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
use Axitrace\PrestaShop\Purchase\PurchaseSender;

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
     * @param list<array{code: string, message: string, first_at: string, last_at: string, count: int}> $failures {@see \Axitrace\PrestaShop\Config\FailureRecorder::all()}
     *
     * @param int $parkedRefunds rows of the retry queue waiting for a valid secret key (counts()['parked'])
     *
     * @return list<string> translated plain-text messages, rendered as error banners
     */
    public function errors(ResolvedEndpoints $endpoints, SecretKeyResult $secretKey, array $failures = [], int $parkedRefunds = 0): array
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

        $codes = array_column($failures, 'code');
        if (in_array(PurchaseSender::FAILURE_PUBLIC_KEY_REJECTED, $codes, true)) {
            $errors[] = $t->trans('Public key rejected: AxiTrace does not recognise the public key, so purchases are not tracked. Copy the public key of your workspace again.', [], 'Modules.Axitrace.Admin');
        }
        if (in_array(PurchaseSender::FAILURE_SECRET_KEY_REJECTED, $codes, true)) {
            $errors[] = $t->trans('Secret key rejected: AxiTrace refused the secret key, so purchases are sent without product costs and refunds wait in the delivery log. Enter the secret key of the same workspace as the public key, then click Retry now.', [], 'Modules.Axitrace.Admin');
        }

        // From the queue itself, not from a recorded failure: dismissing failures never hides it.
        if ($parkedRefunds > 0) {
            $errors[] = $t->trans('%count% refunds wait for a valid secret key: AxiTrace rejected the saved one. Enter the secret key of the same workspace as the public key; they are sent as soon as you save it.', ['%count%' => $parkedRefunds], 'Modules.Axitrace.Admin');
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
