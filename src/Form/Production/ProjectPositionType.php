<?php

declare(strict_types=1);

namespace App\Form\Production;

use App\Entity\Production\ProjectPosition;
use App\Entity\Production\SystemTemplate;
use App\Entity\ProjectSystem\Project;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProjectPositionType extends AbstractType
{
    public function __construct(private readonly ManagerRegistry $registry)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $position = $options['data'] instanceof ProjectPosition ? $options['data'] : new ProjectPosition();
        $templates = $this->registry->getRepository(SystemTemplate::class)->findBy([], ['name' => 'ASC']);
        $projects = $this->registry->getRepository(Project::class)->findBy([], ['name' => 'ASC']);

        $builder
            ->add('position', IntegerType::class, [
                'label' => 'production.project_position.position',
                'attr' => ['min' => 0],
            ])
            ->add('name', TextType::class, [
                'label' => 'production.common.name',
                'required' => false,
                'empty_data' => '',
                'help' => 'production.project_position.name.help',
            ])
            ->add('content', ChoiceType::class, [
                'label' => 'production.project_position.selection',
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
                'data' => $position->getSystemTemplate() ?? $position->getTemplateProject(),
                'mapped' => false,
                'required' => null === $position->getContentName(),
                'placeholder' => 'production.project_position.selection_placeholder',
                'help' => 'production.project_position.selection_help',
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'production.common.notes',
                'required' => false,
                'attr' => ['rows' => 4],
            ]);

        // The selected content is intentionally represented by one unmapped field. Transfer it to
        // the mutually exclusive entity associations before Symfony validates ProjectPosition.
        // Doing this later in the controller leaves templateProject null during validation.
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $position = $event->getData();
            $form = $event->getForm();
            if (!$position instanceof ProjectPosition) {
                return;
            }

            $selection = $form->get('content')->getData();
            if ($selection instanceof SystemTemplate) {
                $position->setSystemTemplate($selection);
                if ('' === $position->getName()) {
                    $position->setName($selection->getName());
                }

                return;
            }

            if ($selection instanceof Project) {
                $position
                    ->setSystemTemplate(null)
                    ->setTemplateProject($selection);
                if ('' === $position->getName()) {
                    $position->setName($selection->getName());
                }

                return;
            }

            if (null === $position->getContentName()) {
                $form->get('content')->addError(new FormError('production.project_position.template_required'));
            }
        }, 100);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProjectPosition::class,
            'translation_domain' => 'production',
        ]);
    }
}
