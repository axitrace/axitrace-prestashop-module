<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Gdpr;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\CustomerRecordsLocatorInterface;
use Axitrace\PrestaShop\Adapter\LoggerInterface;
use Axitrace\PrestaShop\Config\FailureRecorder;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Purchase\OrderRowRepository;
use Axitrace\PrestaShop\Retry\FailedEventQueue;
use Axitrace\PrestaShop\Snapshot\CartSnapshotRepository;

/**
 * PrestaShop's official GDPR module (psgdpr), A11:
 *
 *  - `registerGDPRConsent`: registering the hook is what lists the module in psgdpr;
 *  - `actionExportGDPRData`: what the module stored for the customer's orders and carts - the
 *    `axitrace_order` rows (event id, sent flags, attribution snapshot), the cart snapshots and any
 *    queued or failed delivery of those orders - one row per record, as JSON;
 *  - `actionDeleteGDPRCustomer`: deletes the cart snapshots and the queued or failed deliveries, and
 *    blanks the attribution snapshot of the orders (IP address, user agent, cookie and click ids,
 *    landing page; only the consent decision stays) while the order id, event id and claim/sent
 *    flags stay, so a later status change never sends the order again.
 *
 * A failure is never swallowed: it is logged, recorded for the back office and, for an export,
 * returned as a visible row instead of "no data".
 */
final class PsGdprHooks
{
    public const FAILURE_CODE = 'gdpr_failed';

    /** Snapshot keys kept by an erasure: the decision itself is not personal data. */
    private const KEPT_SNAPSHOT_KEYS = ['consent'];

    public function __construct(
        private readonly CustomerRecordsLocatorInterface $locator,
        private readonly OrderRowRepository $orders,
        private readonly CartSnapshotRepository $carts,
        private readonly FailedEventQueue $queue,
        private readonly FailureRecorder $failures,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<mixed> $params
     *
     * @return string JSON list of rows {record, id, recorded_at, content}
     */
    public function export(array $params): string
    {
        $subject = GdprSubject::fromHookParams($params);
        if ($subject === null) {
            return self::json([]);
        }

        try {
            return self::json($this->exportRows($this->locator->locate($subject)));
        } catch (\Throwable $e) {
            $message = $this->fail('export', $subject, $e);

            return self::json([['record' => 'error', 'id' => '', 'recorded_at' => '', 'content' => $message]]);
        }
    }

    /**
     * @param array<mixed> $params
     *
     * @return string JSON true on success, false when the erasure failed (logged and recorded)
     */
    public function delete(array $params): string
    {
        $subject = GdprSubject::fromHookParams($params);
        if ($subject === null) {
            return self::json(true);
        }

        try {
            $records = $this->locator->locate($subject);
            $deliveries = 0;
            foreach ($records->orderIds as $idOrder) {
                $row = $this->orders->find($idOrder);
                $deliveries += $this->queue->deleteForOrder($idOrder, self::eventId($row));
                if ($row !== null) {
                    $this->orders->replaceSnapshot($idOrder, self::erasedSnapshot($row));
                }
            }
            foreach ($records->cartIds as $idCart) {
                $this->carts->delete($idCart);
            }

            $this->logger->info(sprintf(
                'GDPR erasure for %s: %d orders blanked, %d cart snapshots and %d queued deliveries deleted.',
                $subject->describe(),
                count($records->orderIds),
                count($records->cartIds),
                $deliveries,
            ));

            return self::json(true);
        } catch (\Throwable $e) {
            $this->fail('erasure', $subject, $e);

            return self::json(false);
        }
    }

    /**
     * @return list<array{record: string, id: string, recorded_at: string, content: string}>
     */
    private function exportRows(CustomerRecords $records): array
    {
        $rows = [];
        foreach ($records->orderIds as $idOrder) {
            $row = $this->orders->find($idOrder);
            if ($row !== null) {
                $rows[] = self::row('order', (string) $idOrder, self::scalar($row['created_at'] ?? null), [
                    'event_id' => self::scalar($row['event_id'] ?? null),
                    'trigger' => self::scalar($row['trigger'] ?? null),
                    'purchase_sent_at' => self::scalar($row['purchase_sent_at'] ?? null),
                    'refund_state' => self::scalar($row['refund_state'] ?? null),
                    'attribution' => self::decode(self::scalar($row['snapshot'] ?? null)),
                ]);
            }
            foreach ($this->queue->forOrder($idOrder, self::eventId($row)) as $event) {
                $rows[] = self::row('queued_delivery', $event->eventId, $event->createdAt, [
                    'type' => $event->endpoint,
                    'status' => $event->status,
                    'attempts' => $event->attempts,
                    'payload' => $event->decodedPayload(),
                ]);
            }
        }
        foreach ($records->cartIds as $idCart) {
            $cart = $this->carts->find($idCart);
            if ($cart !== null) {
                $rows[] = self::row('cart_snapshot', (string) $idCart, '', $cart['snapshot']);
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private static function eventId(?array $row): string
    {
        return self::scalar($row['event_id'] ?? null);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>|null
     */
    private static function erasedSnapshot(array $row): ?array
    {
        $snapshot = self::decode(self::scalar($row['snapshot'] ?? null));
        if (!is_array($snapshot)) {
            return null;
        }

        $kept = array_intersect_key($snapshot, array_flip(self::KEPT_SNAPSHOT_KEYS));

        return $kept === [] ? null : $kept;
    }

    private function fail(string $what, GdprSubject $subject, \Throwable $e): string
    {
        $message = sprintf('GDPR %s for %s failed (%s: %s)', $what, $subject->describe(), $e::class, $e->getMessage());
        try {
            $this->failures->record(self::FAILURE_CODE, $message, ShopScope::context());
        } catch (\Throwable $recordError) {
            $this->logger->error(sprintf('%s; recording the failure failed too (%s: %s)', $message, $recordError::class, $recordError->getMessage()));
        }

        return $message;
    }

    /**
     * @param mixed $content
     *
     * @return array{record: string, id: string, recorded_at: string, content: string}
     */
    private static function row(string $record, string $id, string $recordedAt, mixed $content): array
    {
        return ['record' => $record, 'id' => $id, 'recorded_at' => $recordedAt, 'content' => self::json($content)];
    }

    /**
     * @return array<mixed>|null
     */
    private static function decode(string $json): ?array
    {
        $decoded = $json === '' ? null : json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function json(mixed $value): string
    {
        return (string) json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
