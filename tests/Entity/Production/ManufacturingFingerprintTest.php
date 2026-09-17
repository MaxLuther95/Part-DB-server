<?php

declare(strict_types=1);

namespace App\Tests\Entity\Production;

use App\Entity\Production\ManufacturingSnapshot;
use PHPUnit\Framework\TestCase;

final class ManufacturingFingerprintTest extends TestCase
{
    private function definition(array $data): \App\Helpers\Production\ManufacturingDefinition
    {
        return (new ManufacturingSnapshot(['project_1' => $data]))->getDefinition('project_1');
    }

    public function testJsonObjectKeyOrderDoesNotChangeTheReviewedManufacturingState(): void
    {
        $before = ['slots' => [], 'bom' => [
            ['part_id' => 1, 'name' => 'Synthetic part', 'quantity' => 2.0],
            ['part_id' => null, 'name' => 'Synthetic service', 'quantity' => 1.5],
        ], 'name' => 'Synthetic definition'];
        $stored = ['name' => $before['name'], 'bom' => array_map(static fn(array $row): array => array_reverse($row, true), $before['bom']), 'slots' => []];
        self::assertSame($this->definition($before)->getFingerprint(), $this->definition($stored)->getFingerprint());
        self::assertSame($this->definition($before)->getBom(), $this->definition($stored)->getBom());

        $stored['bom'][0]['quantity'] = 3;
        self::assertNotSame($this->definition($before)->getFingerprint(), $this->definition($stored)->getFingerprint());
        $stored = $before;
        $stored['bom'] = array_reverse($stored['bom']);
        self::assertNotSame($this->definition($before)->getFingerprint(), $this->definition($stored)->getFingerprint());
        $stored = $before;
        $stored['bom'][0]['part_id'] = null;
        self::assertNotSame($this->definition($before)->getFingerprint(), $this->definition($stored)->getFingerprint());
    }
}
