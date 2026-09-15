<?php

declare(strict_types=1);

namespace App\Form\Production;

use App\Helpers\Production\DecimalInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ExactDecimalType extends AbstractType
{
    public function getParent(): string
    {
        return TextType::class;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?string $value): ?string => $value,
            static function (?string $value): ?string {
                if (null === $value || '' === trim($value)) {
                    return null;
                }
                try {
                    return DecimalInput::normalize($value);
                } catch (\InvalidArgumentException $exception) {
                    throw new TransformationFailedException($exception->getMessage(), previous: $exception);
                }
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'invalid_message' => 'production.protocol.decimal.invalid',
            'translation_domain' => 'production',
            'attr' => ['inputmode' => 'decimal', 'maxlength' => 128, 'class' => 'form-control-sm'],
        ]);
    }
}
