<?php

declare(strict_types=1);

namespace App\Tests\Doctrine\Types;

use App\Doctrine\Types\TinyIntType;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\TestCase;

final class TinyIntTypeTest extends TestCase
{
    public function testMySqlDeclarationMatchesDbalIntrospection(): void
    {
        $type = new TinyIntType();
        foreach ([new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            self::assertSame('TINYINT', $type->getSQLDeclaration([], $platform));
            $introspected = new Column('level', Type::getType($platform->getDoctrineTypeMapping('tinyint')));
            $expected = new Column('level', $type);
            self::assertTrue($platform->columnsEqual($introspected, $expected));
            self::assertSame(7, $type->convertToPHPValue('7', $platform));
            self::assertSame(0, $type->convertToPHPValue('0', $platform));
            self::assertNull($type->convertToPHPValue(null, $platform));
        }
    }

    public function testOtherPlatformsKeepSmallIntegerStorage(): void
    {
        $type = new TinyIntType();
        foreach ([new SQLitePlatform(), new PostgreSQLPlatform()] as $platform) {
            self::assertSame(
                Type::getType(Types::SMALLINT)->getSQLDeclaration([], $platform),
                $type->getSQLDeclaration([], $platform)
            );
        }
    }
}
