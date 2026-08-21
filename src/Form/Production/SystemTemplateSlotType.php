<?php

declare(strict_types=1);

namespace App\Form\Production;

use App\Entity\Parts\Part;
use App\Entity\Production\SystemTemplateSlot;
use App\Entity\Production\SystemTemplate;
use App\Entity\ProjectSystem\Project;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SystemTemplateSlotType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('position', IntegerType::class, [
                'label' => 'production.system_template.slot.position',
                'attr' => ['min' => 0],
            ])
            ->add('name', TextType::class, ['label' => 'production.system_template.slot.name'])
            ->add('minQuantity', IntegerType::class, [
                'label' => 'production.system_template.slot.min_quantity',
                'attr' => ['min' => 0],
            ])
            ->add('maxQuantity', IntegerType::class, [
                'label' => 'production.system_template.slot.max_quantity',
                'attr' => ['min' => 1],
            ])
            ->add('allowedSystemTemplates', EntityType::class, [
                'class' => SystemTemplate::class,
                'label' => 'production.system_template.slot.allowed_system_templates',
                'choice_label' => static fn(SystemTemplate $template): string => $template->getName(),
                'multiple' => true,
                'required' => false,
                'attr' => ['size' => 8],
                'help' => 'production.system_template.slot.allowed_system_templates_help',
            ])
            ->add('allowedProjects', EntityType::class, [
                'class' => Project::class,
                'label' => 'production.system_template.slot.allowed_projects',
                'choice_label' => static fn(Project $project): string => $project->getFullPath(),
                'multiple' => true,
                'required' => false,
                'attr' => ['size' => 12],
                'help' => 'production.system_template.slot.allowed_projects_help',
            ])
            ->add('allowedParts', EntityType::class, [
                'class' => Part::class,
                'label' => 'production.system_template.slot.allowed_parts',
                'choice_label' => static fn(Part $part): string => sprintf('%s (#%d)', $part->getName(), $part->getId()),
                'multiple' => true,
                'required' => false,
                'attr' => ['size' => 12],
                'help' => 'production.system_template.slot.allowed_parts_help',
            ])
            ->add('serialTracking', CheckboxType::class, [
                'label' => 'production.system_template.slot.serial_tracking',
                'required' => false,
                'help' => 'production.system_template.slot.serial_tracking_help',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SystemTemplateSlot::class,
            'translation_domain' => 'production',
        ]);
    }
}
