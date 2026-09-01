<?php

declare(strict_types=1);

namespace App\DataTables;

use App\DataTables\Column\HTMLColumn;
use App\Entity\Production\CustomerProject;
use App\Entity\Production\CustomerProjectStatus;
use App\Entity\UserSystem\User;
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

final readonly class ProductionOrderDataTable implements DataTableTypeInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private Security $security,
    ) {
    }

    public function configureOptions(OptionsResolver $optionsResolver): void
    {
        $optionsResolver->setDefaults([
            'active_only' => true,
            'status' => null,
            'customer_id' => null,
            'year' => null,
            'assigned_user' => null,
            'search_query' => null,
        ]);
        $optionsResolver->setAllowedTypes('active_only', 'bool');
        $optionsResolver->setAllowedTypes('status', ['string', 'null']);
        $optionsResolver->setAllowedTypes('customer_id', ['int', 'null']);
        $optionsResolver->setAllowedTypes('year', ['int', 'null']);
        $optionsResolver->setAllowedTypes('assigned_user', [User::class, 'null']);
        $optionsResolver->setAllowedTypes('search_query', ['string', 'null']);
    }

    public function configure(DataTable $dataTable, array $options): void
    {
        $resolver = new OptionsResolver();
        $this->configureOptions($resolver);
        $options = $resolver->resolve($options);

        $dataTable
            ->add('projectNumber', HTMLColumn::class, [
                'label' => $this->translator->trans('production.customer_project.number', domain: 'production'),
                'field' => 'customer_project.projectNumber',
                'data' => fn(CustomerProject $order): string => sprintf(
                    '<a href="%s">%s</a>',
                    $this->urlGenerator->generate('production_customer_project_show', ['id' => $order->getId()]),
                    htmlspecialchars($order->getProjectNumber()),
                ),
            ])
            ->add('name', TextColumn::class, [
                'label' => $this->translator->trans('production.common.name', domain: 'production'),
                'field' => 'customer_project.name',
            ])
            ->add('productionProject', HTMLColumn::class, [
                'label' => $this->translator->trans('production.project.label', domain: 'production'),
                'field' => 'production_project.name',
                'data' => function (CustomerProject $order): string {
                    $project = $order->getProductionProject();
                    $label = htmlspecialchars((string) $project);
                    if (null === $project || !$this->security->isGranted('@production_projects.read')) {
                        return $label;
                    }

                    return sprintf('<a href="%s">%s</a>', $this->urlGenerator->generate('production_project_show', ['id' => $project->getId()]), $label);
                },
            ])
            ->add('customer', HTMLColumn::class, [
                'label' => $this->translator->trans('production.customer.label', domain: 'production'),
                'field' => 'customer.name',
                'data' => function (CustomerProject $order): string {
                    $customer = $order->getCustomer();
                    $label = htmlspecialchars((string) $customer);
                    if (null === $customer || !$this->security->isGranted('@production_customers.read')) {
                        return $label;
                    }

                    return sprintf('<a href="%s">%s</a>', $this->urlGenerator->generate('production_customer_show', ['id' => $customer->getId()]), $label);
                },
            ])
            ->add('orderDate', TextColumn::class, [
                'label' => $this->translator->trans('production.customer_project.order_date', domain: 'production'),
                'field' => 'customer_project.orderDate',
                'data' => static fn(CustomerProject $order): string => $order->getOrderDate()?->format('d.m.Y') ?? '–',
            ])
            ->add('status', HTMLColumn::class, [
                'label' => $this->translator->trans('production.common.status', domain: 'production'),
                'field' => 'customer_project.status',
                'data' => function (CustomerProject $order): string {
                    $status = $order->getStatus()->value;
                    $class = match ($order->getStatus()) {
                        CustomerProjectStatus::Planning => 'bg-secondary',
                        CustomerProjectStatus::Commissioned => 'bg-info text-dark',
                        CustomerProjectStatus::InProduction => 'bg-primary',
                        CustomerProjectStatus::Completed => 'bg-success',
                        CustomerProjectStatus::Delivered => 'bg-success',
                        CustomerProjectStatus::Cancelled => 'bg-danger',
                    };

                    return sprintf('<span class="badge %s">%s</span>', $class, htmlspecialchars($this->translator->trans('production.customer_project.status.'.$status, domain: 'production')));
                },
            ])
            ->add('buildInstances', TextColumn::class, [
                'label' => $this->translator->trans('production.build_instance.plural', domain: 'production'),
                'orderable' => false,
                'searchable' => false,
                'className' => 'text-center',
                'data' => fn(CustomerProject $order): string => $this->security->isGranted('@production_build_instances.read') ? (string) $order->getBuildInstances()->count() : '–',
            ])
            ->add('actions', HTMLColumn::class, [
                'label' => '',
                'orderable' => false,
                'searchable' => false,
                'className' => 'text-end no-colvis',
                'data' => fn(CustomerProject $order): string => $this->security->isGranted('@production_orders.edit')
                    ? sprintf('<a class="btn btn-sm btn-outline-primary" href="%s"><i class="fa-solid fa-pen fa-fw"></i></a>', $this->urlGenerator->generate('production_customer_project_edit', ['id' => $order->getId()]))
                    : '',
            ]);

        $dataTable->addOrderBy('orderDate', DataTable::SORT_DESCENDING);
        $dataTable->addOrderBy('projectNumber', DataTable::SORT_DESCENDING);
        $dataTable->createAdapter(ORMAdapter::class, [
            'entity' => CustomerProject::class,
            'query' => static function (QueryBuilder $builder): void {
                $builder
                    ->select('customer_project')
                    ->addSelect('customer')
                    ->addSelect('production_project')
                    ->from(CustomerProject::class, 'customer_project')
                    ->leftJoin('customer_project.customer', 'customer')
                    ->leftJoin('customer_project.productionProject', 'production_project');
            },
            'criteria' => [
                function (QueryBuilder $builder) use ($options): void {
                    $this->applyFilters($builder, $options);
                },
                new SearchCriteriaProvider(),
            ],
        ]);
    }

    private function applyFilters(QueryBuilder $builder, array $options): void
    {
        if (null !== $options['assigned_user']) {
            // A collection join cannot be streamed by the DataTables ORM adapter.
            // MEMBER OF produces an EXISTS-style lookup without fetch-joining the users.
            $builder
                ->andWhere(':assignedUser MEMBER OF customer_project.assignedUsers')
                ->setParameter('assignedUser', $options['assigned_user']);
        }

        if (null !== $options['status']) {
            $builder->andWhere('customer_project.status = :status')->setParameter('status', $options['status']);
        } elseif ($options['active_only']) {
            $builder->andWhere('customer_project.status IN (:activeStatuses)')->setParameter('activeStatuses', [
                CustomerProjectStatus::Planning->value,
                CustomerProjectStatus::Commissioned->value,
                CustomerProjectStatus::InProduction->value,
            ]);
        }

        if (null !== $options['customer_id']) {
            $builder->andWhere('customer.id = :customerId')->setParameter('customerId', $options['customer_id']);
        }

        if (null !== $options['year']) {
            $builder
                ->andWhere('customer_project.orderDate >= :yearStart')
                ->andWhere('customer_project.orderDate < :yearEnd')
                ->setParameter('yearStart', new \DateTimeImmutable(sprintf('%d-01-01', $options['year'])))
                ->setParameter('yearEnd', new \DateTimeImmutable(sprintf('%d-01-01', $options['year'] + 1)));
        }

        if (null !== $options['search_query']) {
            $builder
                ->andWhere($builder->expr()->orX(
                    'LOWER(customer_project.projectNumber) LIKE :productionSearch',
                    'LOWER(customer_project.name) LIKE :productionSearch',
                    'LOWER(customer.name) LIKE :productionSearch',
                    'LOWER(customer.customerNumber) LIKE :productionSearch',
                    'LOWER(production_project.projectNumber) LIKE :productionSearch',
                    'LOWER(production_project.name) LIKE :productionSearch',
                ))
                ->setParameter('productionSearch', '%'.mb_strtolower($options['search_query']).'%');
        }
    }
}
