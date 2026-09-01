<?php

declare(strict_types=1);

namespace App\DataTables;

use App\DataTables\Column\HTMLColumn;
use App\Entity\Production\BuildInstance;
use App\Entity\Production\BuildStatus;
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

final readonly class ProductionBuildInstanceDataTable implements DataTableTypeInterface
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
            ->add('serialNumber', HTMLColumn::class, [
                'label' => $this->translator->trans('production.build_instance.serial_number', domain: 'production'),
                'field' => 'build_instance.serialNumber',
                'className' => 'text-start',
                'data' => fn(BuildInstance $instance): string => sprintf('<a href="%s">%s</a>', $this->urlGenerator->generate('production_build_instance_show', ['id' => $instance->getId()]), htmlspecialchars($instance->getDisplayIdentifier())),
            ])
            ->add('contentName', TextColumn::class, [
                'label' => $this->translator->trans('production.build_instance.template', domain: 'production'),
                'field' => 'build_instance.contentName',
                'data' => static fn(BuildInstance $instance): string => $instance->getContentName() ?? '–',
            ])
            ->add('status', HTMLColumn::class, [
                'label' => $this->translator->trans('production.common.status', domain: 'production'),
                'field' => 'build_instance.status',
                'data' => function (BuildInstance $instance): string {
                    $class = match ($instance->getStatus()) {
                        BuildStatus::Planned => 'bg-secondary',
                        BuildStatus::InProgress => 'bg-primary',
                        BuildStatus::Paused => 'bg-warning text-dark',
                        BuildStatus::Completed => 'bg-success',
                        BuildStatus::Installed => 'bg-info text-dark',
                        BuildStatus::Scrapped => 'bg-danger',
                    };

                    return sprintf('<span class="badge %s">%s</span>', $class, htmlspecialchars($this->translator->trans('production.build_instance.status.'.$instance->getStatus()->value, domain: 'production')));
                },
            ])
            ->add('location', TextColumn::class, [
                'label' => $this->translator->trans('production.build_instance.location', domain: 'production'),
                'field' => 'build_instance.location',
                'data' => static fn(BuildInstance $instance): string => $instance->getLocation() ?? '–',
            ])
            ->add('customerProject', HTMLColumn::class, [
                'label' => $this->translator->trans('production.customer_project.label', domain: 'production'),
                'field' => 'customer_project.projectNumber',
                'data' => function (BuildInstance $instance): string {
                    $order = $instance->getCustomerProject();
                    if (null === $order) {
                        return '<span class="text-muted">'.htmlspecialchars($this->translator->trans('production.build_instance.unassigned', domain: 'production')).'</span>';
                    }
                    $label = htmlspecialchars((string) $order);
                    if (!$this->security->isGranted('@production_orders.read')) {
                        return $label;
                    }

                    return sprintf('<a href="%s">%s</a>', $this->urlGenerator->generate('production_customer_project_show', ['id' => $order->getId()]), $label);
                },
            ])
            ->add('projectPosition', TextColumn::class, [
                'label' => $this->translator->trans('production.project_position.label', domain: 'production'),
                'field' => 'project_position.name',
                'data' => static fn(BuildInstance $instance): string => $instance->getProjectPosition()?->getName() ?? '–',
            ])
            ->add('addedDate', TextColumn::class, [
                'label' => $this->translator->trans('production.common.created_at', domain: 'production'),
                'field' => 'build_instance.addedDate',
                'data' => static fn(BuildInstance $instance): string => $instance->getAddedDate()?->format('d.m.Y') ?? '–',
            ])
            ->add('actions', HTMLColumn::class, [
                'label' => '',
                'orderable' => false,
                'searchable' => false,
                'className' => 'text-end no-colvis',
                'data' => fn(BuildInstance $instance): string => $this->security->isGranted('@production_build_instances.edit')
                    ? sprintf('<a class="btn btn-sm btn-outline-primary" href="%s"><i class="fa-solid fa-pen fa-fw"></i></a>', $this->urlGenerator->generate('production_build_instance_edit', ['id' => $instance->getId()]))
                    : '',
            ]);

        $dataTable->addOrderBy('addedDate', DataTable::SORT_DESCENDING);
        $dataTable->addOrderBy('serialNumber', DataTable::SORT_DESCENDING);
        $dataTable->createAdapter(ORMAdapter::class, [
            'entity' => BuildInstance::class,
            'query' => static function (QueryBuilder $builder): void {
                $builder
                    ->select('build_instance')
                    ->addSelect('customer_project')
                    ->addSelect('project_position')
                    ->from(BuildInstance::class, 'build_instance')
                    ->leftJoin('build_instance.customerProject', 'customer_project')
                    ->leftJoin('build_instance.projectPosition', 'project_position')
                    ->leftJoin('build_instance.systemTemplate', 'system_template')
                    ->leftJoin('build_instance.templateProject', 'template_project');
            },
            'criteria' => [
                function (QueryBuilder $builder) use ($options): void {
                    if (null !== $options['status']) {
                        $builder->andWhere('build_instance.status = :status')->setParameter('status', $options['status']);
                    } elseif ($options['active_only']) {
                        $builder->andWhere('build_instance.status IN (:activeStatuses)')->setParameter('activeStatuses', [BuildStatus::Planned->value, BuildStatus::InProgress->value, BuildStatus::Paused->value]);
                    }
                    if (null !== $options['customer_id']) {
                        $builder
                            ->innerJoin('customer_project.customer', 'build_customer')
                            ->andWhere('build_customer.id = :customerId')
                            ->setParameter('customerId', $options['customer_id']);
                    }
                    if (null !== $options['year']) {
                        $builder
                            ->andWhere('build_instance.addedDate >= :yearStart')
                            ->andWhere('build_instance.addedDate < :yearEnd')
                            ->setParameter('yearStart', new \DateTimeImmutable(sprintf('%d-01-01', $options['year'])))
                            ->setParameter('yearEnd', new \DateTimeImmutable(sprintf('%d-01-01', $options['year'] + 1)));
                    }
                    if (null !== $options['search_query']) {
                        $builder
                            ->andWhere($builder->expr()->orX(
                                'LOWER(build_instance.serialNumber) LIKE :productionSearch',
                                'LOWER(build_instance.contentName) LIKE :productionSearch',
                                'LOWER(system_template.name) LIKE :productionSearch',
                                'LOWER(template_project.name) LIKE :productionSearch',
                                'LOWER(build_instance.location) LIKE :productionSearch',
                                'LOWER(customer_project.projectNumber) LIKE :productionSearch',
                                'LOWER(customer_project.name) LIKE :productionSearch',
                                'LOWER(project_position.name) LIKE :productionSearch',
                            ))
                            ->setParameter('productionSearch', '%'.mb_strtolower($options['search_query']).'%');
                    }
                },
                new SearchCriteriaProvider(),
            ],
        ]);
    }
}
