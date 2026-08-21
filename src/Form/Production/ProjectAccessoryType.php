<?php

declare(strict_types=1);

namespace App\Form\Production;

use App\Entity\Parts\Part;
use App\Entity\Production\ProjectAccessory;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProjectAccessoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('part', EntityType::class, [
                'class' => Part::class,
                'label' => 'production.accessory.part',
                'choice_label' => static fn(Part $part): string => sprintf('%s (#%d)', $part->getName(), $part->getId()),
            ])
            ->add('quantity', IntegerType::class, [
                'label' => 'production.accessory.quantity',
                'attr' => ['min' => 1],
            ])
            ->add('note', TextType::class, [
                'label' => 'production.accessory.note',
                'required' => false,
                'empty_data' => '',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProjectAccessory::class,
            'translation_domain' => 'production',
        ]);
    }
}
