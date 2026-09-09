<?php

declare(strict_types=1);

namespace App\Form\Production;

use App\Entity\Production\ProtocolFieldType;
use App\Entity\Production\ProtocolTemplateField;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProtocolTemplateFieldType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'production.protocol.field.label',
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'production.protocol.field.type',
                'choices' => array_combine(array_map(static fn (ProtocolFieldType $type): string => $type->getLabel(), ProtocolFieldType::cases()), ProtocolFieldType::cases()),
            ])
            ->add('unit', TextType::class, [
                'label' => 'production.protocol.field.unit',
                'required' => false,
                'help' => 'production.protocol.field.unit_help',
            ])
            ->add('helpText', TextareaType::class, [
                'label' => 'production.protocol.field.help_text',
                'required' => false,
                'help' => 'production.protocol.field.help_text_help',
                'attr' => [
                    'rows' => 4,
                ],
            ])
            ->add('layoutColumns', ChoiceType::class, [
                'label' => 'production.protocol.field.layout_width',
                'help' => 'production.protocol.field.layout_width_help',
                'attr' => [
                    'data-controller' => 'production--protocol-layout-width',
                ],
                'choices' => [
                    'production.protocol.field.layout_width_quarter' => 3,
                    'production.protocol.field.layout_width_half' => 6,
                    'production.protocol.field.layout_width_three_quarters' => 9,
                    'production.protocol.field.layout_width_full' => 12,
                ],
            ])
            ->add('startNewRow', CheckboxType::class, [
                'label' => 'production.protocol.field.start_new_row',
                'help' => 'production.protocol.field.start_new_row_help',
                'required' => false,
            ])
            ->add('required', CheckboxType::class, [
                'label' => 'production.protocol.field.required',
                'required' => false,
            ])
            ->add('options', TextareaType::class, [
                'label' => 'production.protocol.field.options',
                'required' => false,
                'help' => 'production.protocol.field.options_help',
                'attr' => [
                    'rows' => 4,
                ],
            ]);

        $builder->get('options')
            ->addModelTransformer(new CallbackTransformer(
                static fn (?array $options): string => implode("\n", $options ?? []),
                static fn (?string $options): ?array => null === $options || '' === trim($options) ? null : (preg_split('/\R/u', trim($options)) ?: null),
            ));

        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $field = $event->getData();
            if ($field instanceof ProtocolTemplateField && ProtocolFieldType::StaticNote === $field->getType()) {
                $field->setUnit(null)
                    ->setRequired(false)
                    ->setOptions(null);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProtocolTemplateField::class,
            'translation_domain' => 'production',
        ]);
    }
}
