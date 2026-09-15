<?php

declare(strict_types=1);

namespace App\Form\Production;

use App\Entity\Production\SerialNumberRange;
use App\Entity\Production\SystemTemplate;
use App\Entity\ProjectSystem\Project;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SerialNumberRangeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $range = $options['data'];
        $builder->add('name', TextType::class, [
            'label' => 'Name',
        ])
            ->add('prefix', TextType::class, [
                'label' => 'Präfix',
                'help' => '3–4 Buchstaben oder Ziffern, z. B. BID oder CID.',
                'attr' => [
                    'maxlength' => 4,
                ],
            ])
            ->add('nextNumber', IntegerType::class, [
                'empty_data' => '0',
                'label' => 'Nächste Nummer',
                'help' => 'Startpunkt für Vorschläge. Bereits vergebene Nummern werden übersprungen.',
                'attr' => [
                    'min' => 1,
                    'max' => 1000000000,
                ],
            ])
            ->add('minimumDigits', IntegerType::class, [
                'empty_data' => '0',
                'label' => 'Mindeststellenzahl',
                'help' => '4 ergibt z. B. BID-0001. Größere Nummern werden nicht abgeschnitten.',
                'attr' => [
                    'min' => 1,
                    'max' => 9,
                ],
            ])
            ->add('systems', EntityType::class, [
                'class' => SystemTemplate::class,
                'choice_label' => 'name',
                'label' => 'Zugeordnete Systemvorlagen',
                'multiple' => true,
                'required' => false,
                'by_reference' => false,
                'attr' => [
                    'size' => 6,
                ],
            ])
            ->add('projects', EntityType::class, [
                'class' => Project::class,
                'choice_label' => static fn (Project $project): string => $project->getFullPath(),
                'label' => 'Zugeordnete Bauprojekt-Vorlagen',
                'multiple' => true,
                'required' => false,
                'by_reference' => false,
                'attr' => [
                    'size' => 10,
                ],
            ])
            ->add('editVersion', HiddenType::class, [
                'mapped' => false,
                'data' => (string) $range->getVersion(),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SerialNumberRange::class,
            'translation_domain' => false,
        ]);
    }
}
