<?php

declare(strict_types=1);

namespace App\Form\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\BuildStatus;
use App\Entity\Production\SystemTemplate;
use App\Entity\ProjectSystem\Project;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class BuildInstanceType extends AbstractType
{
    public function __construct(private readonly ManagerRegistry $registry, private readonly UrlGeneratorInterface $urls)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $buildInstance = $options['data'] instanceof BuildInstance ? $options['data'] : new BuildInstance();
        $templates = $this->registry->getRepository(SystemTemplate::class)->findBy([], ['name' => 'ASC']);
        $projects = $this->registry->getRepository(Project::class)->findBy([], ['name' => 'ASC']);

        $builder
            ->add('serialNumber', SerialNumberType::class, [
                'label' => 'production.build_instance.serial_number',
                'translation_domain' => 'production',
                'required' => false,
                'help' => 'production.build_instance.serial_number_optional_help',
            ])
            ->add('content', ChoiceType::class, [
                'attr' => ['data-serial-content' => ''],
                'choice_attr' => fn(SystemTemplate|Project $choice): array => ['data-serial-suggestion' => $this->urls->generate('production_serial_range_suggest', ['type' => $choice instanceof SystemTemplate ? 'system' : 'project', 'id' => $choice->getId()])],
                'label' => 'production.build_instance.template',
                'choices' => [...$templates, ...$projects],
                'choice_label' => static fn(SystemTemplate|Project $choice): string => $choice instanceof SystemTemplate
                    ? $choice->getName()
                    : $choice->getFullPath(),
                'choice_value' => static fn(SystemTemplate|Project|null $choice): string => match (true) {
                    $choice instanceof SystemTemplate => 'system_'.$choice->getId(),
                    $choice instanceof Project => 'project_'.$choice->getId(),
                    default => '',
                },
                'group_by' => static fn(SystemTemplate|Project $choice): string => $choice instanceof SystemTemplate
                    ? 'production.project_position.selection_group.system'
                    : 'production.project_position.selection_group.project',
                'data' => $buildInstance->getSystemTemplate() ?? $buildInstance->getTemplateProject(),
                'mapped' => false,
                'required' => null === $buildInstance->getContentName(),
                'placeholder' => 'production.project_position.selection_placeholder',
            ])
            ->add('status', EnumType::class, array_filter([
                'label' => 'production.common.status',
                'class' => BuildStatus::class,
                'choice_label' => static fn(BuildStatus $status): string => 'production.build_instance.status.'.$status->value,
                'data' => $options['default_status'],
            ], static fn(mixed $value): bool => null !== $value))
            ->add('location', TextType::class, [
                'label' => 'production.build_instance.location',
                'required' => false,
                'empty_data' => '',
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'production.build_instance.notes',
                'required' => false,
                'empty_data' => '',
                'attr' => ['rows' => 5],
                'help' => 'production.build_instance.notes_without_serial_help',
            ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $buildInstance = $event->getData();
            $form = $event->getForm();
            if (!$buildInstance instanceof BuildInstance) {
                return;
            }

            if (null !== $buildInstance->getProjectPosition()) {
                $buildInstance->setProjectPosition($buildInstance->getProjectPosition());

                return;
            }

            $selection = $form->get('content')->getData();
            if ($selection instanceof SystemTemplate) {
                $buildInstance->setSystemTemplate($selection);
            } elseif ($selection instanceof Project) {
                $buildInstance->setTemplateProject($selection);
            }
        }, 100);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BuildInstance::class,
            'translation_domain' => 'production',
            'default_status' => null,
        ]);
        $resolver->setAllowedTypes('default_status', ['null', BuildStatus::class]);
    }
}
