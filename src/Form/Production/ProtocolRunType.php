<?php

declare(strict_types=1);

namespace App\Form\Production;

use App\Entity\Production\ProtocolAnswer;
use App\Entity\Production\ProtocolFieldType;
use App\Entity\Production\ProtocolRun;
use App\Entity\Production\ProtocolRunStatus;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormError;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

final class ProtocolRunType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var ProtocolRun $run */
        $run = $options['protocol_run'];
        $builder->add('edit_version', HiddenType::class, [
            'mapped' => false,
            'data' => (string) $run->getVersion(),
        ]);
        $builder->add('notes', TextareaType::class, [
            'mapped' => false,
            'required' => false,
            'label' => 'production.protocol.run.notes',
            'help' => 'production.protocol.run.notes_help',
            'data' => $run->getNotes(),
            'constraints' => [new Length(max: 10000)],
            'attr' => ['rows' => 4, 'maxlength' => 10000],
        ]);
        $builder->add('protocol_date', DateType::class, [
            'mapped' => false,
            'label' => 'production.protocol.run.date',
            'widget' => 'single_text',
            'input' => 'datetime_immutable',
            'required' => false,
            // A calendar date must not move when an editor uses a different timezone.
            'data' => null === $run->getProtocolDate() ? null : new \DateTimeImmutable($run->getProtocolDate()->format('Y-m-d')),
            'attr' => ['class' => 'form-control-sm'],
        ]);
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
            if (ProtocolRunStatus::Draft !== $run->getStatus()
                || $form->get('edit_version')->getData() !== (string) $run->getVersion()) {
                $form->addError(new FormError('Der Laufzettel wurde zwischenzeitlich geändert. Es wurde nichts gespeichert.'));

                return;
            }
            $notes = $form->get('notes');
            if ($notes->isSynchronized() && (null === $notes->getData() || is_string($notes->getData())) && mb_strlen($notes->getData() ?? '') <= 10000) {
                $run->setNotes($notes->getData());
            }
            $date = $form->get('protocol_date');
            if ($date->isSynchronized() && (null === $date->getData() || $date->getData() instanceof \DateTimeImmutable)) {
                $run->setProtocolDate($date->getData());
            }
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
            ProtocolFieldType::Integer => [
                IntegerType::class, [
                    'attr' => [
                        'class' => 'form-control-sm',
                    ],
                ]],
            ProtocolFieldType::Decimal => [
                ExactDecimalType::class, []],
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
            default => [
                TextType::class, [
                    'attr' => [
                        'class' => 'form-control-sm',
                    ],
                ]],
        };
    }
}
