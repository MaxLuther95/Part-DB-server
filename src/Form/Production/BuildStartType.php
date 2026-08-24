<?php

declare(strict_types=1);

namespace App\Form\Production;

use App\Entity\Production\SystemTemplate;
use App\Entity\ProjectSystem\Project;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class BuildStartType extends AbstractType
{
    public function __construct(private readonly ManagerRegistry $registry)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $templates = $this->registry->getRepository(SystemTemplate::class)->findBy(['active' => true], ['name' => 'ASC']);
        $projects = $this->registry->getRepository(Project::class)->findBy([], ['name' => 'ASC']);

        $builder->add('content', ChoiceType::class, [
            'label' => 'production.build.new_content',
            'choices' => [...$templates, ...$projects],
            'choice_label' => static fn(SystemTemplate|Project $choice): string => $choice instanceof SystemTemplate
                ? $choice->getName()
                : $choice->getFullPath(),
            'choice_value' => static fn(SystemTemplate|Project|null $choice): string => match (true) {
                $choice instanceof SystemTemplate => 'system_'.$choice->getId(),
                $choice instanceof Project => 'project_'.$choice->getId(),
                default => '',
            },
            'group_by' => static fn(SystemTemplate|Project $choice): string => $choice instanceof SystemTemplate
                ? 'production.project_position.selection_group.system'
                : 'production.project_position.selection_group.project',
            'placeholder' => 'production.build.new_content_placeholder',
            'mapped' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'production',
        ]);
    }
}
