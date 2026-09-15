<?php

declare(strict_types=1);

namespace App\Form\Production;

use App\Services\Production\SerialNumberManager;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SerialNumberType extends AbstractType
{
    public function __construct(
        private readonly SerialNumberManager $numbers,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('prefix', TextType::class, [
            'label' => 'Präfix',
            'required' => false,
            'attr' => [
                'maxlength' => 4,
                'data-serial-prefix' => '',
                'autocomplete' => 'off',
            ],
        ])
            ->add('number', TextType::class, [
                'label' => 'Nummer',
                'required' => false,
                'attr' => [
                    'maxlength' => 128,
                    'data-serial-number' => '',
                    'autocomplete' => 'off',
                ],
            ])
            ->add('confirmed', CheckboxType::class, [
                'label' => 'Seriennummer geprüft und bestätigt',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'data-serial-confirmed' => '',
                ],
            ]);
        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?string $value): array => SerialNumberManager::split($value),
            function (array $value): ?string {
                try {
                    return $this->numbers->combine($value['prefix'] ?? '', $value['number'] ?? '');
                } catch (\RuntimeException $error) {
                    throw new TransformationFailedException($error->getMessage(), invalidMessage: $error->getMessage());
                }
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => false,
            'required' => false,
        ]);
    }
}
