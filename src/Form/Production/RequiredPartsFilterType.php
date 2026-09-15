<?php

declare(strict_types=1);

namespace App\Form\Production;

use App\Entity\Parts\StorageLocation;
use App\Entity\Parts\Supplier;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class RequiredPartsFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['allow_sites']) {
            $builder->add('site', EntityType::class, [
                'class' => StorageLocation::class,
                'choice_label' => 'fullPath',
                'query_builder' => static fn (EntityRepository $r) => $r->createQueryBuilder('site')->where('site.parent IS NULL')->orderBy('site.name', 'ASC'),
                'label' => 'production.required_parts.site',
                'placeholder' => 'production.required_parts.all_sites',
                'required' => false,
            ]);
        }
        if ($options['allow_suppliers']) {
            $builder->add('supplier', EntityType::class, [
                'class' => Supplier::class,
                'choice_label' => 'fullPath',
                'query_builder' => static fn (EntityRepository $r) => $r->createQueryBuilder('supplier')->orderBy('supplier.name', 'ASC'),
                'label' => 'production.required_parts.supplier',
                'placeholder' => 'production.required_parts.all_suppliers',
                'required' => false,
            ]);
        }
        $builder->add('missing', ChoiceType::class, [
            'label' => 'production.required_parts.display',
            'choices' => ['production.required_parts.only_missing' => '1', 'production.required_parts.all_required' => '0'],
            'placeholder' => false,
            'empty_data' => '1',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method' => 'GET', 'csrf_protection' => false, 'allow_extra_fields' => true,
            'translation_domain' => 'production', 'allow_sites' => false, 'allow_suppliers' => false,
        ]);
        $resolver->setAllowedTypes('allow_sites', 'bool');
        $resolver->setAllowedTypes('allow_suppliers', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
