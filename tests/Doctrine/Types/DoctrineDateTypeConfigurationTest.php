<?php

declare(strict_types=1);

namespace App\Tests\Doctrine\Types;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Types\DateImmutableType;
use Doctrine\DBAL\Types\DateType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineDateTypeConfigurationTest extends KernelTestCase
{
    public function testDateOnlyTypesAreNotOverriddenByDateTimeTypes(): void
    {
        self::bootKernel();

        $dateType = Type::getType(Types::DATE_MUTABLE);
        $immutableDateType = Type::getType(Types::DATE_IMMUTABLE);

        self::assertSame(DateType::class, $dateType::class);
        self::assertSame(DateImmutableType::class, $immutableDateType::class);

        $converted = $immutableDateType->convertToPHPValue('2026-09-02', new MySQLPlatform());
        self::assertInstanceOf(\DateTimeImmutable::class, $converted);
        self::assertSame('2026-09-02', $converted->format('Y-m-d'));
    }
}
