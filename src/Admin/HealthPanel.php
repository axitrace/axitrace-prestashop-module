<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\TranslatorInterface;
use Axitrace\PrestaShop\Config\DeliveryHealth;
use Axitrace\PrestaShop\Config\FailureRecorder;
use Axitrace\PrestaShop\Config\ResolvedEndpoints;
use Axitrace\PrestaShop\Config\SecretKeyState;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Normalizer\PlatformInfo;
use Axitrace\PrestaShop\Retry\FailedEventQueue;

/**
 * "Connection health" on the configuration page, for the shop selected in the back office: where
 * events go, the last accepted delivery (AXITRACE_LAST_SUCCESS), every recorded failure, newest
 * first (AXITRACE_LAST_FAILURE, one entry per failure code), whether AxiTrace keeps the product costs (secret key state and
 * AXITRACE_COST_KEY_STATE), the retry queue and the module, PrestaShop and PHP versions (A14). The
 * error banners (public or secret key rejected, secret key to re-enter, developer override,
 * refused tracking domain) are {@see ConfigPageNotices}.
 */
final class HealthPanel
{
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    /** "Dismiss failures": a back-office POST carrying the module's action token. */
    public const SUBMIT_DISMISS = 'submitAxitraceDismissFailures';

    public function __construct(
        private readonly DeliveryHealth $health,
        private readonly FailureRecorder $failures,
        private readonly FailedEventQueue $queue,
        private readonly PlatformInfo $platform,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<array{label: string, value: string, state: string}>
     */
    public function rows(ShopScope $scope, ResolvedEndpoints $endpoints, SecretKeyState $secretKey): array
    {
        $t = $this->translator;
        $d = 'Modules.Axitrace.Admin';

        $lastSuccess = $this->health->lastSuccess($scope);
        $counts = $this->queue->counts();

        return [
            [
                'label' => $t->trans('Events are sent to', [], $d),
                'value' => $endpoints->apiBaseUrl,
                'state' => $endpoints->error !== null || $endpoints->overrideActive ? self::WARNING : self::OK,
            ],
            [
                'label' => $t->trans('Last successful delivery', [], $d),
                'value' => $lastSuccess !== '' ? $lastSuccess : $t->trans('None yet', [], $d),
                'state' => $lastSuccess !== '' ? self::OK : self::WARNING,
            ],
            ...$this->failureRows($scope),
            $this->costRow($scope, $secretKey),
            [
                'label' => $t->trans('Delivery queue', [], $d),
                'value' => $t->trans('%queued% waiting for a retry, %parked% waiting for a valid secret key, %dead% failed', [
                    '%queued%' => $counts['queued'],
                    '%parked%' => $counts['parked'],
                    '%dead%' => $counts['dead'],
                ], $d),
                'state' => $counts['dead'] > 0 || $counts['parked'] > 0 ? self::ERROR : ($counts['queued'] > 0 ? self::WARNING : self::OK),
            ],
            [
                'label' => $t->trans('Versions', [], $d),
                'value' => $t->trans('Module %module%, PrestaShop %prestashop%, PHP %php%', [
                    '%module%' => $this->platform->pluginVersion,
                    '%prestashop%' => $this->platform->psVersion,
                    '%php%' => $this->platform->phpVersion,
                ], $d),
                'state' => self::OK,
            ],
        ];
    }

    /** True when at least one failure is recorded: the panel then offers "Dismiss failures". */
    public function hasFailures(ShopScope $scope): bool
    {
        return $this->failures->allWithGlobal($scope) !== [];
    }

    /**
     * "Last failure" (the newest), then one "Earlier failure" row per other recorded failure code,
     * the selected shop's and the retry queue's (global) together.
     *
     * @return list<array{label: string, value: string, state: string}>
     */
    private function failureRows(ShopScope $scope): array
    {
        $t = $this->translator;
        $d = 'Modules.Axitrace.Admin';

        $failures = $this->failures->allWithGlobal($scope);
        if ($failures === []) {
            return [['label' => $t->trans('Last failure', [], $d), 'value' => $t->trans('None', [], $d), 'state' => self::OK]];
        }

        $rows = [];
        foreach ($failures as $i => $failure) {
            $rows[] = [
                'label' => $i === 0 ? $t->trans('Last failure', [], $d) : $t->trans('Earlier failure', [], $d),
                'value' => $failure['count'] > 1
                    ? $t->trans('%time% (%count% times since %first%): %message%', ['%time%' => $failure['last_at'], '%count%' => $failure['count'], '%first%' => $failure['first_at'], '%message%' => $failure['message']], $d)
                    : $t->trans('%time%: %message%', ['%time%' => $failure['last_at'], '%message%' => $failure['message']], $d),
                'state' => self::ERROR,
            ];
        }

        return $rows;
    }

    /**
     * @return array{label: string, value: string, state: string}
     */
    private function costRow(ShopScope $scope, SecretKeyState $secretKey): array
    {
        $t = $this->translator;
        $d = 'Modules.Axitrace.Admin';
        $label = $t->trans('Product costs and refunds', [], $d);

        if ($secretKey === SecretKeyState::Undecryptable) {
            return ['label' => $label, 'value' => $t->trans('Not sent: the secret key must be entered again.', [], $d), 'state' => self::ERROR];
        }
        if ($secretKey === SecretKeyState::Absent) {
            return ['label' => $label, 'value' => $t->trans('Not sent: no secret key is saved.', [], $d), 'state' => self::WARNING];
        }

        return match ($this->health->costKeyState($scope)) {
            DeliveryHealth::COST_KEY_ACCEPTED => ['label' => $label, 'value' => $t->trans('Accepted by AxiTrace.', [], $d), 'state' => self::OK],
            DeliveryHealth::COST_KEY_UNVERIFIED => ['label' => $label, 'value' => $t->trans('Dropped: AxiTrace does not recognise the secret key.', [], $d), 'state' => self::ERROR],
            default => ['label' => $label, 'value' => $t->trans('A secret key is saved; nothing was delivered with it yet.', [], $d), 'state' => self::WARNING],
        };
    }
}
