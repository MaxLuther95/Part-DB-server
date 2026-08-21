<?php

declare(strict_types=1);

namespace App\Tests\Form\Production;

use App\Entity\Production\ProjectPosition;
use App\Entity\Production\SystemTemplate;
use App\Entity\ProjectSystem\Project;
use App\Form\Production\ProjectPositionType;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

final class ProjectPositionTypeTest extends TestCase
{
    public function testSystemTemplateIsAppliedBeforeValidation(): void
    {
        $baseProject = (new Project())->setName('Fixed BOM');
        $systemTemplate = (new SystemTemplate())
            ->setName('MFFT Elektronik')
            ->setBaseProject($baseProject);
        $standaloneProject = (new Project())->setName('Standalone');
        $form = $this->createForm($systemTemplate, $standaloneProject, new ProjectPosition());

        $form->submit([
            'position' => '1',
            'name' => '',
            'content' => 'system_',
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame($systemTemplate, $form->getData()->getSystemTemplate());
        self::assertNull($form->getData()->getTemplateProject());
        self::assertSame($baseProject, $form->getData()->getBuildProject());
        self::assertSame('MFFT Elektronik', $form->getData()->getName());
    }

    public function testStandaloneBuildProjectRemainsARealAlternative(): void
    {
        $systemTemplate = (new SystemTemplate())->setName('System');
        $standaloneProject = (new Project())->setName('Standalone');
        $form = $this->createForm($systemTemplate, $standaloneProject, new ProjectPosition());

        $form->submit([
            'position' => '2',
            'name' => 'Adapter',
            'content' => 'project_',
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertNull($form->getData()->getSystemTemplate());
        self::assertSame($standaloneProject, $form->getData()->getTemplateProject());
        self::assertSame('Adapter', $form->getData()->getName());
    }

    private function createForm(SystemTemplate $systemTemplate, Project $project, ProjectPosition $position): \Symfony\Component\Form\FormInterface
    {
        $systemRepository = $this->createMock(ObjectRepository::class);
        $systemRepository->method('findBy')->willReturn([$systemTemplate]);
        $projectRepository = $this->createMock(ObjectRepository::class);
        $projectRepository->method('findBy')->willReturn([$project]);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getRepository')->willReturnCallback(
            static fn(string $class): ObjectRepository => SystemTemplate::class === $class ? $systemRepository : $projectRepository,
        );

        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension($validator))
            ->addType(new ProjectPositionType($registry))
            ->getFormFactory();

        return $factory->create(ProjectPositionType::class, $position);
    }
}
