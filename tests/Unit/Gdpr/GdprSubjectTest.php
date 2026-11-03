<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Gdpr;

use Axitrace\PrestaShop\Gdpr\GdprSubject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GdprSubjectTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<mixed>, 1: int|null, 2: string, 3: string}>
     */
    public static function shapes(): iterable
    {
        yield 'psgdpr (array) Customer' => [['id' => 5, 'email' => 'ada@example.com', 'firstname' => 'Ada'], 5, 'ada@example.com', ''];
        yield 'psgdpr 8.2 strings' => [['id' => '5', 'email' => 'ada@example.com'], 5, 'ada@example.com', ''];
        yield 'psgdpr by e-mail' => [['email' => ' ada@example.com '], null, 'ada@example.com', ''];
        yield 'psgdpr by phone' => [['phone' => '+48500100200'], null, '', '+48500100200'];
        yield 'psgdpr 2.x erasure by id' => [[0 => '5'], 5, '', ''];
        yield 'psgdpr 2.x erasure by e-mail' => [[0 => ['email' => 'ada@example.com']], null, 'ada@example.com', ''];
        yield 'psgdpr 2.x erasure by phone' => [[0 => ['phone' => '+48500100200']], null, '', '+48500100200'];
    }

    /**
     * @param array<mixed> $params
     */
    #[DataProvider('shapes')]
    public function testHookShapes(array $params, ?int $id, string $email, string $phone): void
    {
        $subject = GdprSubject::fromHookParams($params);

        self::assertNotNull($subject);
        self::assertSame($id, $subject->customerId);
        self::assertSame($email, $subject->email);
        self::assertSame($phone, $subject->phone);
    }

    public function testNothingToIdentify(): void
    {
        foreach ([[], [0 => 'abc'], [0 => 0], ['id' => 0], ['email' => ''], ['firstname' => 'Ada']] as $params) {
            self::assertNull(GdprSubject::fromHookParams($params), (string) json_encode($params));
        }
    }
}
