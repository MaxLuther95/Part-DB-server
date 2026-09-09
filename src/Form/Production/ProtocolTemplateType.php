<?php

declare(strict_types=1);

namespace App\Form\Production;

use App\Entity\Production\ProtocolTemplate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProtocolTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'production.protocol.template.name',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'production.common.description',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                ],
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'production.protocol.template.active',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProtocolTemplate::class,
            'translation_domain' => 'production',
        ]);
    }
}
