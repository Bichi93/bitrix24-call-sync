<?php

declare(strict_types=1);

namespace CallSync\Tests;

use CallSync\Telephony\PhoneNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhoneNormalizerTest extends TestCase
{
    public static function numbers(): array
    {
        return [
            'local mobile' => ['599 12 34 56', '+995599123456'],
            'local with trunk zero' => ['0599123456', '+995599123456'],
            'international with plus' => ['+995 (599) 12-34-56', '+995599123456'],
            'double-zero prefix' => ['00995599123456', '+995599123456'],
            'country code without plus' => ['995599123456', '+995599123456'],
            'foreign number keeps its code' => ['+44 20 7946 0958', '+442079460958'],
        ];
    }

    #[DataProvider('numbers')]
    public function testNormalizesToE164(string $raw, string $expected): void
    {
        self::assertSame($expected, (new PhoneNormalizer('995'))->normalize($raw));
    }

    public function testRejectsEmptyNumber(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PhoneNormalizer())->normalize('anonymous');
    }
}
