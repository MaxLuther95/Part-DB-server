<?php

declare(strict_types=1);

namespace App\Tests\Entity\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\DatasheetTemplate;
use App\Entity\Production\SystemTemplate;
use App\Entity\ProjectSystem\Project;
use PHPUnit\Framework\TestCase;

final class DatasheetAssignmentsTest extends TestCase
{
    public function testAssignmentsAreExplicitAndNotInherited(): void
    {
        $project = (new Project())->setName('Board');
        $system = (new SystemTemplate())->setName('Complete device')->addBaseProject($project);
        $parent = (new BuildInstance())->setSystemTemplate($system);
        $child = (new BuildInstance())->setTemplateProject($project)->setParent($parent);
        $untyped = new BuildInstance();
        $template = (new DatasheetTemplate())->addProject($project)->addProject($project);
        self::assertCount(1, $template->getProjects());
        self::assertTrue($template->appliesTo($child));
        self::assertFalse($template->appliesTo($parent));
        self::assertFalse($template->appliesTo($untyped));
        $template->removeProject($project)->addSystemTemplate($system)->addSystemTemplate($system);
        self::assertCount(1, $template->getSystemTemplates());
        self::assertTrue($template->appliesTo($parent));
        self::assertFalse($template->appliesTo($child));
        $template->removeSystemTemplate($system);
        self::assertFalse($template->appliesTo($parent));
    }
}
