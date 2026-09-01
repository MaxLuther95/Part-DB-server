<?php

declare(strict_types=1);

namespace App\Form\Production;

use App\Entity\Parts\Part;
use App\Entity\Production\OrderImportMapping;
use App\Entity\Production\SystemTemplate;
use App\Entity\ProjectSystem\Project;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class OrderImportMappingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('sourceDescription', TextType::class, ['label' => 'Beschreibung aus der PDF'])
            ->add('systemTemplate', EntityType::class, [
                'class' => SystemTemplate::class,
                'choice_label' => 'name',
                'placeholder' => 'Keine Systemvorlage',
                'required' => false,
                'label' => 'Systemvorlage',
            ])
            ->add('templateProject', EntityType::class, [
                'class' => Project::class,
                'choice_label' => static fn(Project $project): string => $project->getFullPath(),
                'placeholder' => 'Kein Bauprojekt',
                'required' => false,
                'label' => 'Part-DB-Bauprojekt',
            ])
            ->add('part', EntityType::class, [
                'class' => Part::class,
                'choice_label' => 'name',
                'placeholder' => 'Kein Lagerteil',
                'required' => false,
                'label' => 'Part-DB-Lagerteil',
            ])
            ->add('active', CheckboxType::class, ['required' => false, 'label' => 'Aktiv']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => OrderImportMapping::class]);
    }
}
