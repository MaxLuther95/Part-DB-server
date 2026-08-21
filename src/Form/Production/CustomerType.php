<?php

declare(strict_types=1);

namespace App\Form\Production;

use App\Entity\Production\Customer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CustomerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('customerNumber', TextType::class, [
                'label' => 'production.customer.number',
            ])
            ->add('name', TextType::class, [
                'label' => 'production.customer.name',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'production.common.description',
                'required' => false,
                'empty_data' => '',
                'attr' => ['rows' => 4],
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'production.customer.active',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Customer::class,
            'translation_domain' => 'production',
        ]);
    }
}
