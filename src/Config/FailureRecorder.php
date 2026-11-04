<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Config;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\ClockInterface;
use Axitrace\PrestaShop\Adapter\ConfigurationStoreInterface;
use Axitrace\PrestaShop\Adapter\LoggerInterface;

/**
 * Records the failures of a shop scope in AXITRACE_LAST_FAILURE and in the PrestaShop log, so the
 * back office can show them. The value is a JSON object keyed by failure code, one entry (at, code,
 * message) per code: clearing one code after a success never erases another one.
 *
 * A failure that repeats on every request is written once: an entry is only written when its code
 * is not recorded yet. Every caller clears only its own codes after a success of the same kind
 * (a delivered purchase clears `delivery_failed`, an accepted secret key `secret_key_rejected`);
 * a failure no success can disprove (a refused payload) stays until the merchant dismisses the
 * failures in the back office ({@see clearAll()}).
 *
 * The JSON is written with `<`, `>` and `&` escaped: Configuration::updateValue() strips HTML tags.
 */
final class FailureRecorder
{
    private const MAX_MESSAGE_LENGTH = 512;

    public function __construct(
        private readonly ConfigurationStoreInterface $store,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
    }

    public function record(string $code, string $message, ShopScope $scope): void
    {
        $this->logger->error($message);

        $entries = $this->entries($scope);
        if (isset($entries[$code])) {
            return;
        }

        $entries[$code] = ['at' => $this->clock->now()->format(\DATE_ATOM), 'code' => $code, 'message' => mb_substr($message, 0, self::MAX_MESSAGE_LENGTH)];
        $this->write($entries, $scope);
    }

    public function clear(string $code, ShopScope $scope): void
    {
        $entries = $this->entries($scope);
        if (!isset($entries[$code])) {
            return;
        }

        unset($entries[$code]);
        $this->write($entries, $scope);
    }

    /** "Dismiss" in the back office: forgets every recorded failure of the scope. */
    public function clearAll(ShopScope $scope): void
    {
        if ($this->entries($scope) !== []) {
            $this->store->set(ModuleConfig::LAST_FAILURE, '', $scope->idShopGroup, $scope->idShop);
        }
    }

    /**
     * Every recorded failure, newest first.
     *
     * @return list<array{at: string, code: string, message: string}>
     */
    public function all(ShopScope $scope): array
    {
        $entries = array_reverse(array_values($this->entries($scope)));
        // Stable: entries with the same time keep the newest-written first.
        usort($entries, static fn (array $a, array $b): int => self::time($b['at']) <=> self::time($a['at']));

        return $entries;
    }

    /**
     * The newest recorded failure.
     *
     * @return array{at: string, code: string, message: string}|null
     */
    public function last(ShopScope $scope): ?array
    {
        return $this->all($scope)[0] ?? null;
    }

    /**
     * @return array<string, array{at: string, code: string, message: string}>
     */
    private function entries(ShopScope $scope): array
    {
        $raw = (string) $this->store->get(ModuleConfig::LAST_FAILURE, $scope->idShopGroup, $scope->idShop);
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->logger->error(sprintf('AXITRACE_LAST_FAILURE is not valid JSON (%s); the recorded failures were reset.', json_last_error_msg()));

            return [];
        }
        // Module 0.1.0 stored a single entry.
        if (is_string($decoded['code'] ?? null)) {
            $decoded = [$decoded['code'] => $decoded];
        }

        $entries = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry) || !is_string($entry['code'] ?? null) || $entry['code'] === '') {
                continue;
            }
            $entries[$entry['code']] = [
                'at' => is_string($entry['at'] ?? null) ? $entry['at'] : '',
                'code' => $entry['code'],
                'message' => is_string($entry['message'] ?? null) ? $entry['message'] : '',
            ];
        }

        return $entries;
    }

    /**
     * @param array<string, array{at: string, code: string, message: string}> $entries
     */
    private function write(array $entries, ShopScope $scope): void
    {
        $value = $entries === []
            ? ''
            : json_encode($entries, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_THROW_ON_ERROR);

        $this->store->set(ModuleConfig::LAST_FAILURE, $value, $scope->idShopGroup, $scope->idShop);
    }

    private static function time(string $atom): int
    {
        $time = strtotime($atom);

        return $time === false ? 0 : $time;
    }
}
