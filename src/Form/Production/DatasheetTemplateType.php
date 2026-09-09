<?php

declare(strict_types=1);

namespace App\Form\Production;

use App\Entity\Production\DatasheetTemplate;
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
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DatasheetTemplate::class,
        ]);
    }
}
