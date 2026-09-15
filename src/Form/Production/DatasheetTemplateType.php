<?php

declare(strict_types=1);

namespace App\Form\Production;

use App\Entity\Production\DatasheetTemplate;
use App\Entity\Production\SystemTemplate;
use App\Entity\ProjectSystem\Project;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DatasheetTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Interner Vorlagenname',
            ])
            ->add('productTitle', TextType::class, [
                'label' => 'Englische Produktüberschrift im Kopf',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Interne Beschreibung',
                'required' => false,
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Vorlage aktiv',
                'required' => false,
            ]);
        foreach ([
            'systemTemplates' => ['allow_system_assignment', SystemTemplate::class, 'name', 'systems'],
            'projects' => ['allow_project_assignment', Project::class, 'fullPath', 'projects'],
        ] as $property => [$permission, $class, $label, $key]) {
            if ($options[$permission]) {
                $builder->add($property, EntityType::class, [
                    'class' => $class,
                    'choice_label' => $label,
                    'query_builder' => static fn (\Doctrine\ORM\EntityRepository $repository) => $repository->createQueryBuilder('target')->orderBy('target.name', 'ASC')->addOrderBy('target.id', 'ASC'),
                    'label' => 'production.datasheet.assignment.'.$key,
                    'help' => 'production.datasheet.assignment.'.$key.'_help',
                    'translation_domain' => 'production',
                    'choice_translation_domain' => false,
                    'multiple' => true,
                    'by_reference' => false,
                    'required' => false,
                    'attr' => ['size' => 8],
                ]);
            }
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DatasheetTemplate::class,
            'allow_system_assignment' => false,
            'allow_project_assignment' => false,
        ]);
        $resolver->setAllowedTypes('allow_system_assignment', 'bool');
        $resolver->setAllowedTypes('allow_project_assignment', 'bool');
    }
}
