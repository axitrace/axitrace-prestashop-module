<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

use Axitrace\PrestaShop\Adapter\HttpResponse;
use Axitrace\PrestaShop\Adapter\HttpTransportException;
use Axitrace\PrestaShop\Adapter\HttpTransportInterface;

/**
 * Records every request; answers from a queue of responses (HttpResponse, or a Throwable to throw).
 * An empty queue answers 202.
 */
final class FakeHttpTransport implements HttpTransportInterface
{
    /** @var list<array{url: string, headers: array<string, string>, body: string, payload: array<string, mixed>, connect: int, total: int}> */
    public array $requests = [];

    /** @var list<HttpResponse|\Throwable> */
    private array $answers;

    public function __construct(HttpResponse|\Throwable ...$answers)
    {
        $this->answers = array_values($answers);
    }

    public function willAnswer(HttpResponse|\Throwable ...$answers): void
    {
        array_push($this->answers, ...$answers);
    }

    public static function timeout(): HttpTransportException
    {
        return new HttpTransportException('cURL error 28: Operation timed out after 2001 milliseconds');
    }

    public function post(string $url, array $headers, string $body, int $connectTimeoutSeconds, int $totalTimeoutSeconds): HttpResponse
    {
        $payload = json_decode($body, true);
        $this->requests[] = [
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'payload' => is_array($payload) ? $payload : [],
            'connect' => $connectTimeoutSeconds,
            'total' => $totalTimeoutSeconds,
        ];

        $answer = array_shift($this->answers) ?? new HttpResponse(202, [], '{"success":true}');
        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return $answer;
    }
}
