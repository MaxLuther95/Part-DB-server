<?php

declare(strict_types=1);

namespace App\Form\Production;

use App\Entity\Production\OrderPositionUnit;
use App\Entity\Production\SystemTemplate;
use App\Entity\ProjectSystem\Project;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SystemTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'production.system_template.name'])
            ->add('baseProjects', EntityType::class, [
                'class' => Project::class,
                'label' => 'production.system_template.base_project',
                'choice_label' => static fn(Project $project): string => $project->getFullPath(),
                'multiple' => true,
                'required' => false,
                'attr' => ['size' => 12],
                'help' => 'production.system_template.base_project_help',
            ])
            ->add('orderUnit', ChoiceType::class, [
                'label' => 'production.system_template.order_unit',
                'help' => 'production.system_template.order_unit_help',
                'choices' => [
                    OrderPositionUnit::Piece->getLabel() => OrderPositionUnit::Piece,
                    OrderPositionUnit::Set->getLabel() => OrderPositionUnit::Set,
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'production.common.description',
                'required' => false,
                'empty_data' => '',
                'attr' => ['rows' => 4],
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'production.system_template.active',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SystemTemplate::class,
            'translation_domain' => 'production',
        ]);
    }
}
