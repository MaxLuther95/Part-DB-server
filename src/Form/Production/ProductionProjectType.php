<?php

declare(strict_types=1);

namespace App\Form\Production;

use App\Entity\Production\ProductionProject;
use App\Entity\Production\ProductionProjectStatus;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProductionProjectType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('projectNumber', TextType::class, ['label' => 'production.project.number'])
            ->add('name', TextType::class, ['label' => 'production.common.name'])
            ->add('description', TextareaType::class, [
                'label' => 'production.common.description',
                'required' => false,
                'empty_data' => '',
                'attr' => ['rows' => 3],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'production.common.status',
                'choices' => [
                    'production.project.status.planning' => ProductionProjectStatus::Planning,
                    'production.project.status.active' => ProductionProjectStatus::Active,
                    'production.project.status.paused' => ProductionProjectStatus::Paused,
                    'production.project.status.completed' => ProductionProjectStatus::Completed,
                    'production.project.status.cancelled' => ProductionProjectStatus::Cancelled,
                ],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'production.common.notes',
                'required' => false,
                'attr' => ['rows' => 3],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProductionProject::class,
            'translation_domain' => 'production',
        ]);
    }
}
