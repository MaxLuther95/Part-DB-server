<?php

declare(strict_types=1);

namespace App\Form\Production;

use App\Entity\Production\ProtocolAnswer;
use App\Entity\Production\ProtocolFieldType;
use App\Entity\Production\ProtocolRun;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProtocolRunType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var ProtocolRun $run */
        $run = $options['protocol_run'];
        foreach ($run->getRows() as $row) {
            foreach ($row->getAnswers() as $answer) {
                $field = $answer->getField();
                if (null === $field || null === $answer->getId()) {
                    continue;
                }
                [$type, $fieldOptions] = $this->getFormField($answer);
                $builder->add('answer_'.$answer->getId(), $type, array_merge([
                    'mapped' => false,
                    'required' => false,
                    'label' => false,
                    'data' => $answer->getValue(),
                ], $fieldOptions));
            }
        }
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event) use ($run): void {
            $form = $event->getForm();
            foreach ($run->getRows() as $row) {
                foreach ($row->getAnswers() as $answer) {
                    $name = 'answer_'.$answer->getId();
                    if ($form->has($name) && $form->get($name)->isSynchronized()) {
                        $answer->setValue($form->get($name)->getData());
                    }
                }
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'translation_domain' => 'production',
        ]);
        $resolver->setRequired('protocol_run');
        $resolver->setAllowedTypes('protocol_run', ProtocolRun::class);
    }

    /**
     * @return array{class-string, array<string, mixed>}
     */
    private function getFormField(ProtocolAnswer $answer): array
    {
        $field = $answer->getField() ?? throw new \LogicException('Answer has no field.');

        return match ($field->getType()) {
            ProtocolFieldType::LongText => [
                TextareaType::class, [
                    'attr' => [
                        'rows' => 3,
                        'class' => 'form-control-sm',
                    ],
                ]],
            ProtocolFieldType::Integer => [
                IntegerType::class, [
                    'attr' => [
                        'class' => 'form-control-sm',
                    ],
                ]],
            ProtocolFieldType::Decimal => [
                NumberType::class, [
                    'input' => 'string',
                    'scale' => 9,
                    'html5' => true,
                    'attr' => [
                        'class' => 'form-control-sm',
                    ],
                ]],
            ProtocolFieldType::Boolean => [
                ChoiceType::class, [
                    'placeholder' => '–',
                    'attr' => [
                        'class' => 'form-select-sm',
                    ],
                    'choices' => [
                        'production.choice.yes' => true,
                        'production.choice.no' => false,
                    ],
                ]],
            ProtocolFieldType::TestResult => [
                ChoiceType::class, [
                    'placeholder' => '–',
                    'attr' => [
                        'data-controller' => 'production--protocol-test-result',
                        'class' => 'form-select-sm',
                    ],
                    'choices' => [
                        'production.protocol.test_result.pass' => 'pass',
                        'production.protocol.test_result.not_applicable' => 'not_applicable',
                        'production.protocol.test_result.fail' => 'fail',
                    ],
                ]],
            ProtocolFieldType::Choice => [
                ChoiceType::class, [
                    'placeholder' => '–',
                    'attr' => [
                        'class' => 'form-select-sm',
                    ],
                    'choices' => array_combine($field->getOptions() ?? [], $field->getOptions() ?? []),
                ]],
            ProtocolFieldType::Date => [
                DateType::class, [
                    'widget' => 'single_text',
                    'input' => 'datetime_immutable',
                    'attr' => [
                        'class' => 'form-control-sm',
                    ],
                ]],
            ProtocolFieldType::DateTime => [
                DateTimeType::class, [
                    'widget' => 'single_text',
                    'input' => 'datetime_immutable',
                    'attr' => [
                        'class' => 'form-control-sm',
                    ],
                ]],
            default => [
                TextType::class, [
                    'attr' => [
                        'class' => 'form-control-sm',
                    ],
                ]],
        };
    }
}
