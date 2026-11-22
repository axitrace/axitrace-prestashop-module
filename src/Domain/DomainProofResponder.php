<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Domain;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\SecretKeyVault;

/**
 * The store domain proof AxiTrace fetches after a claim ({@see StoreDomainClaimer}):
 * `https://<host>/index.php?fc=module&module=axitrace&controller=domainproof&nonce=<nonce>`.
 *
 * The answer names the public key of the shop the URL belongs to (public by design: every
 * storefront page carries it) and echoes the nonce; with a secret key it adds
 * hex(HMAC-SHA256(secret key, nonce|host|public key)), host being the claimed host of that shop.
 * Only a shop configured with the workspace's keys can produce it, so AxiTrace verifies the domain
 * without any DNS change. The body carries exactly these keys: AxiTrace refuses any other.
 */
final class DomainProofResponder
{
    public const PLATFORM = 'prestashop';
    public const NONCE_PATTERN = '/^[A-Za-z0-9_-]{16,128}$/';

    public const HEADERS = [
        'Content-Type' => 'application/json; charset=utf-8',
        'Cache-Control' => 'no-store, no-cache, private, max-age=0',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
        'X-Robots-Tag' => 'noindex, nofollow',
    ];

    public function __construct(
        private readonly ModuleConfig $config,
        private readonly SecretKeyVault $vault,
    ) {
    }

    /**
     * @param ShopHosts $shop        the shop the request was routed to, with its claimed hosts
     * @param string    $requestHost the HTTP host of the request
     *
     * @return array{status: int, body: string}
     */
    public function answer(string $nonce, ShopHosts $shop, string $requestHost): array
    {
        if (preg_match(self::NONCE_PATTERN, $nonce) !== 1) {
            return self::json(400, ['error' => 'invalid_nonce']);
        }

        $scope = $shop->scope();
        $publicKey = $this->config->publicKey($scope);
        if ($publicKey === '') {
            return self::json(404, ['error' => 'not_configured']);
        }

        $body = [
            'axitrace_domain_proof' => 1,
            'public_key' => $publicKey,
            'nonce' => $nonce,
            'platform' => self::PLATFORM,
        ];

        $secretKey = $this->vault->read($scope)->key;
        $host = self::signedHost($shop, $requestHost);
        if ($secretKey !== null && $host !== '') {
            $body['signature'] = self::signature($secretKey, $nonce, $host, $publicKey);
        }

        return self::json(200, $body);
    }

    public static function signature(string $secretKey, string $nonce, string $host, string $publicKey): string
    {
        return hash_hmac('sha256', $nonce . '|' . $host . '|' . $publicKey, $secretKey);
    }

    /**
     * The request host when it is one the shop claims, else the shop's first host: the signature
     * never covers a host this shop did not claim (a forged Host header gets nothing to replay).
     */
    private static function signedHost(ShopHosts $shop, string $requestHost): string
    {
        $host = ShopHosts::normalizeHost($requestHost);

        return in_array($host, $shop->hosts, true) ? $host : ($shop->hosts[0] ?? '');
    }

    /**
     * @param array<string, int|string> $body
     *
     * @return array{status: int, body: string}
     */
    private static function json(int $status, array $body): array
    {
        return ['status' => $status, 'body' => json_encode($body, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)];
    }
}
