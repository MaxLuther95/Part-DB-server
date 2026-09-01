<?php

declare(strict_types=1);

namespace App\DataTables;

use App\DataTables\Column\HTMLColumn;
use App\Entity\Production\ProductionProject;
use App\Entity\Production\ProductionProjectStatus;
use App\Entity\Production\CustomerProject;
use Doctrine\ORM\QueryBuilder;
use Omines\DataTablesBundle\Adapter\Doctrine\ORM\SearchCriteriaProvider;
use Omines\DataTablesBundle\Adapter\Doctrine\ORMAdapter;
use Omines\DataTablesBundle\Column\TextColumn;
use Omines\DataTablesBundle\DataTable;
use Omines\DataTablesBundle\DataTableTypeInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ProductionProjectDataTable implements DataTableTypeInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private Security $security,
    ) {
    }

    public function configureOptions(OptionsResolver $optionsResolver): void
    {
        $optionsResolver->setDefaults(['active_only' => true, 'status' => null, 'customer_id' => null, 'year' => null, 'search_query' => null]);
        $optionsResolver->setAllowedTypes('active_only', 'bool');
        $optionsResolver->setAllowedTypes('status', ['string', 'null']);
        $optionsResolver->setAllowedTypes('customer_id', ['int', 'null']);
        $optionsResolver->setAllowedTypes('year', ['int', 'null']);
        $optionsResolver->setAllowedTypes('search_query', ['string', 'null']);
    }

    public function configure(DataTable $dataTable, array $options): void
    {
        $resolver = new OptionsResolver();
        $this->configureOptions($resolver);
        $options = $resolver->resolve($options);

        $dataTable
            ->add('projectNumber', HTMLColumn::class, [
                'label' => $this->translator->trans('production.project.number', domain: 'production'),
                'field' => 'production_project.projectNumber',
                'data' => fn(ProductionProject $project): string => sprintf('<a href="%s">%s</a>', $this->urlGenerator->generate('production_project_show', ['id' => $project->getId()]), htmlspecialchars($project->getProjectNumber())),
            ])
            ->add('name', TextColumn::class, [
                'label' => $this->translator->trans('production.common.name', domain: 'production'),
                'field' => 'production_project.name',
            ])
            ->add('status', HTMLColumn::class, [
                'label' => $this->translator->trans('production.common.status', domain: 'production'),
                'field' => 'production_project.status',
                'data' => function (ProductionProject $project): string {
                    $class = match ($project->getStatus()) {
                        ProductionProjectStatus::Planning => 'bg-secondary',
                        ProductionProjectStatus::Active => 'bg-primary',
                        ProductionProjectStatus::Paused => 'bg-warning text-dark',
                        ProductionProjectStatus::Completed => 'bg-success',
                        ProductionProjectStatus::Cancelled => 'bg-danger',
                    };

                    return sprintf('<span class="badge %s">%s</span>', $class, htmlspecialchars($this->translator->trans('production.project.status.'.$project->getStatus()->value, domain: 'production')));
                },
            ])
            ->add('addedDate', TextColumn::class, [
                'label' => $this->translator->trans('production.common.created_at', domain: 'production'),
                'field' => 'production_project.addedDate',
                'data' => static fn(ProductionProject $project): string => $project->getAddedDate()?->format('d.m.Y') ?? '–',
            ])
            ->add('orders', TextColumn::class, [
                'label' => $this->translator->trans('production.customer_project.plural', domain: 'production'),
                'orderable' => false,
                'searchable' => false,
                'className' => 'text-center',
                'data' => static fn(ProductionProject $project): string => (string) $project->getOrders()->count(),
            ])
            ->add('actions', HTMLColumn::class, [
                'label' => '',
                'orderable' => false,
                'searchable' => false,
                'className' => 'text-end no-colvis',
                'data' => fn(ProductionProject $project): string => $this->security->isGranted('@production_projects.edit')
                    ? sprintf('<a class="btn btn-sm btn-outline-primary" href="%s"><i class="fa-solid fa-pen fa-fw"></i></a>', $this->urlGenerator->generate('production_project_edit', ['id' => $project->getId()]))
                    : '',
            ]);

        $dataTable->addOrderBy('addedDate', DataTable::SORT_DESCENDING);
        $dataTable->addOrderBy('projectNumber', DataTable::SORT_DESCENDING);
        $dataTable->createAdapter(ORMAdapter::class, [
            'entity' => ProductionProject::class,
            'query' => static function (QueryBuilder $builder): void {
                $builder->select('DISTINCT production_project')->from(ProductionProject::class, 'production_project');
            },
            'criteria' => [
                function (QueryBuilder $builder) use ($options): void {
                    if (null !== $options['status']) {
                        $builder->andWhere('production_project.status = :status')->setParameter('status', $options['status']);
                    } elseif ($options['active_only']) {
                        $builder->andWhere('production_project.status IN (:activeStatuses)')->setParameter('activeStatuses', [
                            ProductionProjectStatus::Planning->value,
                            ProductionProjectStatus::Active->value,
                            ProductionProjectStatus::Paused->value,
                        ]);
                    }
                    if (null !== $options['customer_id']) {
                        $builder
                            ->andWhere($builder->expr()->exists(sprintf(
                                'SELECT 1 FROM %s customer_order WHERE customer_order.productionProject = production_project AND IDENTITY(customer_order.customer) = :customerId',
                                CustomerProject::class,
                            )))
                            ->setParameter('customerId', $options['customer_id']);
                    }
                    if (null !== $options['year']) {
                        $builder
                            ->andWhere('production_project.addedDate >= :yearStart')
                            ->andWhere('production_project.addedDate < :yearEnd')
                            ->setParameter('yearStart', new \DateTimeImmutable(sprintf('%d-01-01', $options['year'])))
                            ->setParameter('yearEnd', new \DateTimeImmutable(sprintf('%d-01-01', $options['year'] + 1)));
                    }
                    if (null !== $options['search_query']) {
                        $builder
                            ->andWhere($builder->expr()->orX(
                                'LOWER(production_project.projectNumber) LIKE :productionSearch',
                                'LOWER(production_project.name) LIKE :productionSearch',
                                'LOWER(production_project.description) LIKE :productionSearch',
                            ))
                            ->setParameter('productionSearch', '%'.mb_strtolower($options['search_query']).'%');
                    }
                },
                new SearchCriteriaProvider(),
            ],
        ]);
    }
}
