<?php

declare(strict_types=1);

namespace App\Tests\Helpers\Projects;

use App\Entity\Parts\PartLot;
use App\Entity\Parts\StorageLocation;
use App\Helpers\Projects\ProjectBuildLocationFilter;
use PHPUnit\Framework\TestCase;

final class ProjectBuildLocationFilterTest extends TestCase
{
    public function testDefaultAndNestedOverridesAreInherited(): void
    {
        $workshop = $this->location(1, 'Workshop');
        $reserved = $this->location(2, 'Reserved', $workshop);
        $released = $this->location(3, 'Released', $reserved);
        $other = $this->location(4, 'Other');

        $filter = new ProjectBuildLocationFilter(true, [2 => false, 3 => true], false);

        self::assertTrue($filter->isLocationAllowed($workshop));
        self::assertFalse($filter->isLocationAllowed($reserved));
        self::assertTrue($filter->isLocationAllowed($released));
        self::assertTrue($filter->isLocationAllowed($other));
    }

    public function testGlobalDenyCanBeOverriddenForSubtree(): void
    {
        $allowedRoot = $this->location(1, 'Allowed root');
        $child = $this->location(2, 'Child', $allowedRoot);
        $other = $this->location(3, 'Other');

        $filter = new ProjectBuildLocationFilter(false, [1 => true]);

        self::assertTrue($filter->isLocationAllowed($allowedRoot));
        self::assertTrue($filter->isLocationAllowed($child));
        self::assertFalse($filter->isLocationAllowed($other));
    }

    public function testUnassignedLotsHaveIndependentTriState(): void
    {
        $lot = new PartLot();

        self::assertFalse((new ProjectBuildLocationFilter(true, [], false))->isLotAllowed($lot));
        self::assertTrue((new ProjectBuildLocationFilter(false, [], true))->isLotAllowed($lot));
        self::assertTrue((new ProjectBuildLocationFilter(true, [], null))->isLotAllowed($lot));
        self::assertFalse((new ProjectBuildLocationFilter(false, [], null))->isLotAllowed($lot));
    }

    public function testQueryParametersOnlyContainExplicitLocationRules(): void
    {
        $filter = new ProjectBuildLocationFilter(true, [12 => false, 13 => true], null);

        self::assertSame([
            'default' => 'true',
            'unassigned' => 'indeterminate',
            'locations' => [12 => 'false', 13 => 'true'],
        ], $filter->toQueryParameters());
    }

    private function location(int $id, string $name, ?StorageLocation $parent = null): StorageLocation
    {
        $location = new class($id) extends StorageLocation {
            public function __construct(private readonly int $testId)
            {
                parent::__construct();
            }

            public function getID(): ?int
            {
                return $this->testId;
            }
        };
        $location->setName($name);
        $location->setParent($parent);

        return $location;
    }
}
