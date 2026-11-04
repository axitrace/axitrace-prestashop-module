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
 * Records failures in the PrestaShop log and in the configuration, so the back office can show
 * them. Each store is a JSON object keyed by failure code, one entry per code (first_at, last_at,
 * count, latest message): clearing one code after a success never erases another one.
 *
 * - AXITRACE_LAST_FAILURE, per shop scope: failures of one shop's orders and settings.
 * - AXITRACE_QUEUE_FAILURE, global ({@see recordGlobal()}): failures of the retry queue, which all
 *   shops share; the health panel shows them in every shop context.
 *
 * A failure that repeats on every request does not write on every request: an entry already
 * recorded is updated (last_at, count, message) at most once every {@see UPDATE_INTERVAL_SECONDS},
 * so `count` is the number of such updates, not of occurrences. Every occurrence is still logged.
 *
 * Every caller clears only its own codes after a success of the same kind (a delivered purchase
 * clears `delivery_failed`, an accepted secret key `secret_key_rejected`); a failure no success can
 * disprove (a refused payload) stays until the merchant dismisses it ({@see clearAll()}).
 *
 * The JSON is written with `<`, `>` and `&` escaped: Configuration::updateValue() strips HTML tags.
 */
final class FailureRecorder
{
    public const UPDATE_INTERVAL_SECONDS = 600;

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
        $this->upsert(ModuleConfig::LAST_FAILURE, $scope, $code, $message);
    }

    /** A failure of the shared retry queue (no shop of its own). */
    public function recordGlobal(string $code, string $message): void
    {
        $this->logger->error($message);
        $this->upsert(ModuleConfig::QUEUE_FAILURE, null, $code, $message);
    }

    public function clear(string $code, ShopScope $scope): void
    {
        $this->remove(ModuleConfig::LAST_FAILURE, $scope, $code);
    }

    public function clearGlobal(string $code): void
    {
        $this->remove(ModuleConfig::QUEUE_FAILURE, null, $code);
    }

    /** "Dismiss failures" in the back office: the shop scope's failures and the queue's. */
    public function clearAll(ShopScope $scope): void
    {
        if ($this->entries(ModuleConfig::LAST_FAILURE, $scope) !== []) {
            $this->write(ModuleConfig::LAST_FAILURE, $scope, []);
        }
        if ($this->entries(ModuleConfig::QUEUE_FAILURE, null) !== []) {
            $this->write(ModuleConfig::QUEUE_FAILURE, null, []);
        }
    }

    /**
     * The failures of the shop scope, newest (last_at) first.
     *
     * @return list<array{code: string, message: string, first_at: string, last_at: string, count: int}>
     */
    public function all(ShopScope $scope): array
    {
        return self::newestFirst($this->entries(ModuleConfig::LAST_FAILURE, $scope));
    }

    /**
     * The shop scope's failures and the queue's, newest first: what the health panel shows.
     *
     * @return list<array{code: string, message: string, first_at: string, last_at: string, count: int}>
     */
    public function allWithGlobal(ShopScope $scope): array
    {
        return self::newestFirst([
            ...array_values($this->entries(ModuleConfig::QUEUE_FAILURE, null)),
            ...array_values($this->entries(ModuleConfig::LAST_FAILURE, $scope)),
        ]);
    }

    /**
     * The newest failure of the shop scope.
     *
     * @return array{code: string, message: string, first_at: string, last_at: string, count: int}|null
     */
    public function last(ShopScope $scope): ?array
    {
        return $this->all($scope)[0] ?? null;
    }

    private function upsert(string $key, ?ShopScope $scope, string $code, string $message): void
    {
        $now = $this->clock->now();
        $at = $now->format(\DATE_ATOM);
        $entries = $this->entries($key, $scope);
        $message = mb_substr($message, 0, self::MAX_MESSAGE_LENGTH);

        if (isset($entries[$code])) {
            if ($now->getTimestamp() - self::time($entries[$code]['last_at']) < self::UPDATE_INTERVAL_SECONDS) {
                return;
            }
            $entries[$code] = ['last_at' => $at, 'count' => $entries[$code]['count'] + 1, 'message' => $message] + $entries[$code];
        } else {
            $entries[$code] = ['code' => $code, 'message' => $message, 'first_at' => $at, 'last_at' => $at, 'count' => 1];
        }

        $this->write($key, $scope, $entries);
    }

    private function remove(string $key, ?ShopScope $scope, string $code): void
    {
        $entries = $this->entries($key, $scope);
        if (!isset($entries[$code])) {
            return;
        }

        unset($entries[$code]);
        $this->write($key, $scope, $entries);
    }

    /**
     * @return array<string, array{code: string, message: string, first_at: string, last_at: string, count: int}>
     */
    private function entries(string $key, ?ShopScope $scope): array
    {
        $raw = (string) ($scope === null
            ? $this->store->get($key, 0, 0)
            : $this->store->get($key, $scope->idShopGroup, $scope->idShop));
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->logger->error(sprintf('%s is not valid JSON (%s); the recorded failures were reset.', $key, json_last_error_msg()));

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
            // Entries written before first_at / last_at / count existed carry only `at`.
            $legacyAt = is_string($entry['at'] ?? null) ? $entry['at'] : '';
            $entries[$entry['code']] = [
                'code' => $entry['code'],
                'message' => is_string($entry['message'] ?? null) ? $entry['message'] : '',
                'first_at' => is_string($entry['first_at'] ?? null) ? $entry['first_at'] : $legacyAt,
                'last_at' => is_string($entry['last_at'] ?? null) ? $entry['last_at'] : $legacyAt,
                'count' => is_int($entry['count'] ?? null) && $entry['count'] > 0 ? $entry['count'] : 1,
            ];
        }

        return $entries;
    }

    /**
     * @param array<string, array{code: string, message: string, first_at: string, last_at: string, count: int}> $entries
     */
    private function write(string $key, ?ShopScope $scope, array $entries): void
    {
        $value = $entries === []
            ? ''
            : json_encode($entries, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_THROW_ON_ERROR);

        if ($scope === null) {
            $this->store->setGlobal($key, $value);

            return;
        }
        $this->store->set($key, $value, $scope->idShopGroup, $scope->idShop);
    }

    /**
     * Newest last_at first; equal times keep the newest-written first.
     *
     * @param array<array-key, array{code: string, message: string, first_at: string, last_at: string, count: int}> $entries
     *
     * @return list<array{code: string, message: string, first_at: string, last_at: string, count: int}>
     */
    private static function newestFirst(array $entries): array
    {
        $entries = array_reverse(array_values($entries));
        usort($entries, static fn (array $a, array $b): int => self::time($b['last_at']) <=> self::time($a['last_at']));

        return $entries;
    }

    private static function time(string $atom): int
    {
        $time = strtotime($atom);

        return $time === false ? 0 : $time;
    }
}
