<?php

declare(strict_types=1);

namespace App\Form\Production;

use App\Entity\Production\ProtocolTemplateRevision;
use App\Services\Production\ProtocolTemplateExporter;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ProtocolTemplateExportType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('revisions', EntityType::class, [
            'class' => ProtocolTemplateRevision::class,
            'label' => 'production.protocol.export.selection',
            'help' => 'production.protocol.export.selection_help',
            'multiple' => true,
            'expanded' => true,
            'choice_translation_domain' => false,
            'choice_label' => fn (ProtocolTemplateRevision $revision): string => sprintf(
                'v%d · %s',
                $revision->getRevisionNumber(),
                $this->translator->trans('production.protocol.revision_status.'.$revision->getStatus()->value, [], 'production')
            ),
            // Group by identity so identically named templates remain separate.
            'group_by' => static fn (ProtocolTemplateRevision $revision): string => (string) $revision->getTemplate()?->getId(),
            'query_builder' => static fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('revision')
                ->addSelect('template')
                ->innerJoin('revision.template', 'template')
                ->orderBy('template.name', 'ASC')
                ->addOrderBy('template.id', 'ASC')
                ->addOrderBy('revision.revisionNumber', 'DESC'),
            'constraints' => [new Count(min: 1, max: ProtocolTemplateExporter::MAX_REVISIONS)],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'production',
            'csrf_token_id' => 'protocol_template_export',
        ]);
    }
}
