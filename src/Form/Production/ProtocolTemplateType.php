<?php

declare(strict_types=1);

namespace App\Form\Production;

use App\Entity\Production\ProtocolTemplate;
use App\Entity\Production\SystemTemplate;
use App\Entity\ProjectSystem\Project;
use App\Repository\Production\ProtocolTemplateRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProtocolTemplateType extends AbstractType
{
    public function __construct(private readonly ProtocolTemplateRepository $templates)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'production.protocol.template.name',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'production.common.description',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                ],
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'production.protocol.template.active',
                'required' => false,
            ]);
        if ($options['allow_system_assignment']) {
            $builder->add('systemTemplates', EntityType::class, [
                'class' => SystemTemplate::class,
                'choice_label' => 'name',
                'query_builder' => static fn (\Doctrine\ORM\EntityRepository $repository) => $repository->createQueryBuilder('system')->orderBy('system.name', 'ASC')->addOrderBy('system.id', 'ASC'),
                'label' => 'production.protocol.template.systems',
                'help' => 'production.protocol.template.systems_help',
                'multiple' => true,
                'by_reference' => false,
                'required' => false,
                'attr' => ['size' => 8],
            ]);
        }
        if ($options['allow_project_assignment']) {
            $builder->add('projects', EntityType::class, [
                'class' => Project::class,
                'choice_label' => 'fullPath',
                'label' => 'production.protocol.template.projects',
                'help' => 'production.protocol.template.projects_help',
                'multiple' => true,
                'by_reference' => false,
                'required' => false,
                'attr' => ['size' => 8],
            ]);
        }
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            $template = $event->getData();
            if (! $template instanceof ProtocolTemplate) {
                return;
            }
            foreach (['systemTemplates' => $template->getSystemTemplates(), 'projects' => $template->getProjects()] as $property => $targets) {
                if (! $form->has($property) || ! $form->get($property)->isSynchronized()) {
                    continue;
                }
                foreach ($targets as $target) {
                    $conflicts = $this->templates->createQueryBuilder('template')
                        ->innerJoin('template.'.$property, 'target')
                        ->where('target.id = :target')->setParameter('target', $target->getId())
                        ->andWhere('template.id != :current')->setParameter('current', $template->getId() ?? 0)
                        ->getQuery()->getResult();
                    if ([] !== $conflicts) {
                        $form->get($property)->addError(new FormError(sprintf('„%s“ ist bereits der Laufzettelvorlage „%s“ zugeordnet. Bitte zuerst diese Zuordnung bearbeiten.', $target, $conflicts[0])));
                    }
                }
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProtocolTemplate::class,
            'translation_domain' => 'production',
            'allow_system_assignment' => false,
            'allow_project_assignment' => false,
        ]);
        $resolver->setAllowedTypes('allow_system_assignment', 'bool');
        $resolver->setAllowedTypes('allow_project_assignment', 'bool');
    }
}
