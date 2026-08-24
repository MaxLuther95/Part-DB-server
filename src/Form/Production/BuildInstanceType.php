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
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class BuildInstanceType extends AbstractType
{
    public function __construct(private readonly ManagerRegistry $registry)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $buildInstance = $options['data'] instanceof BuildInstance ? $options['data'] : new BuildInstance();
        $templates = $this->registry->getRepository(SystemTemplate::class)->findBy([], ['name' => 'ASC']);
        $projects = $this->registry->getRepository(Project::class)->findBy([], ['name' => 'ASC']);

        $builder
            ->add('serialNumber', TextType::class, [
                'label' => 'production.build_instance.serial_number',
            ])
            ->add('content', ChoiceType::class, [
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
            ->add('status', ChoiceType::class, array_filter([
                'label' => 'production.common.status',
                'choices' => [
                    'production.build_instance.status.planned' => BuildStatus::Planned,
                    'production.build_instance.status.in_progress' => BuildStatus::InProgress,
                    'production.build_instance.status.paused' => BuildStatus::Paused,
                    'production.build_instance.status.completed' => BuildStatus::Completed,
                    'production.build_instance.status.installed' => BuildStatus::Installed,
                    'production.build_instance.status.scrapped' => BuildStatus::Scrapped,
                ],
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
