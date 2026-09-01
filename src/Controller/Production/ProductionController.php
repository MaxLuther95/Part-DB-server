<?php

declare(strict_types=1);

namespace App\Controller\Production;

use App\DataTables\PartsDataTable;
use App\DataTables\ProductionBuildInstanceDataTable;
use App\DataTables\ProductionOrderDataTable;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartLot;
use App\Entity\Parts\StorageLocation;
use App\Entity\Production\BuildInstance;
use App\Entity\Production\Customer;
use App\Entity\Production\CustomerProject;
use App\Entity\Production\CustomerProjectStatus;
use App\Entity\Production\ProjectMaterialAllocation;
use App\Entity\Production\ProjectPosition;
use App\Entity\Production\ProjectAccessory;
use App\Entity\Production\SystemTemplate;
use App\Entity\Production\SystemTemplateSlot;
use App\Form\Production\BuildInstanceType;
use App\Form\Production\BuildStartType;
use App\Form\Production\CustomerProjectType;
use App\Form\Production\CustomerType;
use App\Form\Production\ProjectAccessoryType;
use App\Form\Production\ProjectPositionType;
use App\Form\Production\SystemTemplateSlotType;
use App\Form\Production\SystemTemplateType;
use App\Repository\Production\BuildInstanceRepository;
use App\Repository\Production\CustomerRepository;
use App\Repository\Production\ProductionProjectRepository;
use App\Repository\Production\SystemTemplateRepository;
use App\Entity\Production\BuildStatus;
use App\Entity\ProjectSystem\Project;
use App\Entity\UserSystem\User;
use App\Services\Production\ProductionHistoryRecorder;
use App\Services\Production\BuildConfigurationCompatibility;
use App\Services\Production\ProductionMaterialPlanner;
use App\Services\Production\ProductionBuildWorkflow;
use App\Services\Production\ProjectPositionInitializer;
use App\Services\Production\ProductionReservationManager;
use App\Services\Production\OrderAttachmentStorage;
use App\Services\Production\SystemTemplateSlotPositioner;
use App\Services\Parts\PartLotWithdrawAddHelper;
use App\Settings\BehaviorSettings\TableSettings;
use Doctrine\ORM\EntityManagerInterface;
use Omines\DataTablesBundle\DataTableFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormError;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(path: '/production')]
final class ProductionController extends AbstractController
{
    public function __construct(
        private readonly ProductionHistoryRecorder $historyRecorder,
        private readonly TranslatorInterface $translator,
        private readonly ProductionReservationManager $reservationManager,
        private readonly ProjectPositionInitializer $positionInitializer,
        private readonly SystemTemplateSlotPositioner $slotPositioner,
    )
    {
    }

    #[Route(path: '', name: 'production_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        $destinations = [
            '@production_projects.read' => 'production_project_index',
            '@production_orders.read' => 'production_customer_project_index',
            '@production_build_instances.read' => 'production_build_instance_index',
            '@production_material.read' => 'production_required_parts',
            '@production_customers.read' => 'production_customer_index',
            '@production_system_templates.read' => 'production_template_index',
            '@production_import_mappings.read' => 'production_order_import_mapping_index',
        ];
        foreach ($destinations as $permission => $route) {
            if ($this->isGranted($permission)) {
                return $this->redirectToRoute($route);
            }
        }

        throw $this->createAccessDeniedException();
    }

    #[Route(path: '/build', name: 'production_build', methods: ['GET'])]
    public function build(BuildInstanceRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('@production_build_instances.read');

        $activeStatuses = [BuildStatus::Planned, BuildStatus::InProgress, BuildStatus::Paused];
        $activeBuilds = array_values(array_filter(
            $repository->findBy([], ['lastModified' => 'DESC']),
            static fn(BuildInstance $instance): bool => in_array($instance->getStatus(), $activeStatuses, true),
        ));

        return $this->render('production/build.html.twig', ['build_instances' => $activeBuilds]);
    }

    #[Route(path: '/build/new', name: 'production_build_new', methods: ['GET', 'POST'])]
    public function buildNew(Request $request, ProductionBuildWorkflow $workflow): Response
    {
        $this->denyAccessUnlessGranted('@production_build_instances.build');

        $form = $this->createForm(BuildStartType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $selectedContent = $form->get('content')->getData();
            if ($selectedContent instanceof Project) {
                $this->denyAccessUnlessGranted('read', $selectedContent);
            }
            if ($selectedContent instanceof SystemTemplate || $selectedContent instanceof Project) {
                $token = bin2hex(random_bytes(16));
                $request->getSession()->set('production_build_'.$token, $workflow->createDraft($selectedContent));

                return $this->redirectToRoute('production_build_workflow_next', ['token' => $token]);
            }
        }

        return $this->render('production/build_new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/build-templates', name: 'production_template_index', methods: ['GET'])]
    public function templateIndex(SystemTemplateRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('@production_system_templates.read');

        $templates = $repository->findBy([], ['name' => 'ASC']);
        $roots = [];
        $childrenByParent = [];
        foreach ($templates as $template) {
            $parents = $template->getParentTemplates();
            if ([] === $parents) {
                $roots[] = $template;
            }
            foreach ($parents as $parent) {
                $childrenByParent[$parent->getId()][] = $template;
            }
        }
        // Invalid legacy cycles must not make every template disappear from the list.
        if ([] === $roots && [] !== $templates) {
            $roots = $templates;
        }

        return $this->render('production/template/index.html.twig', [
            'templates' => $templates,
            'root_templates' => $roots,
            'children_by_parent' => $childrenByParent,
        ]);
    }

    #[Route(path: '/build-templates/new', name: 'production_template_new', methods: ['GET', 'POST'])]
    public function templateNew(Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_system_templates.create');

        return $this->handleSystemTemplateForm(new SystemTemplate(), $request, $entityManager);
    }

    #[Route(path: '/build-templates/{id}', name: 'production_template_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function templateShow(SystemTemplate $template): Response
    {
        $this->denyAccessUnlessGranted('@production_system_templates.read');

        return $this->render('production/template/show.html.twig', ['template' => $template]);
    }

    #[Route(path: '/build-templates/{id}/edit', name: 'production_template_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function templateEdit(SystemTemplate $template, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_system_templates.edit');

        return $this->handleSystemTemplateForm($template, $request, $entityManager);
    }

    #[Route(path: '/build-templates/{id}/delete', name: 'production_template_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function templateDelete(SystemTemplate $template, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_system_templates.delete');
        if (!$this->isCsrfTokenValid('delete_system_template_'.$template->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $entityManager->remove($template);
        $entityManager->flush();
        $this->addFlash('success', $this->translator->trans('production.system_template.deleted', domain: 'production'));

        return $this->redirectToRoute('production_template_index');
    }

    #[Route(path: '/build-templates/{id}/slots/new', name: 'production_template_slot_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function templateSlotNew(SystemTemplate $template, Request $request): Response
    {
        $this->denyAccessUnlessGranted('@production_system_templates.edit');

        $slot = new SystemTemplateSlot();
        $template->addSlot($slot);

        return $this->handleSystemTemplateSlotForm(
            $slot,
            $request,
        );
    }

    #[Route(path: '/build-template-slots/{id}/edit', name: 'production_template_slot_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function templateSlotEdit(SystemTemplateSlot $slot, Request $request): Response
    {
        $this->denyAccessUnlessGranted('@production_system_templates.edit');

        return $this->handleSystemTemplateSlotForm($slot, $request);
    }

    #[Route(path: '/customers', name: 'production_customer_index', methods: ['GET'])]
    public function customerIndex(CustomerRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('@production_customers.read');

        return $this->render('production/customer/index.html.twig', [
            'customers' => $repository->findBy([], ['customerNumber' => 'ASC']),
        ]);
    }

    #[Route(path: '/customers/new', name: 'production_customer_new', methods: ['GET', 'POST'])]
    public function customerNew(Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_customers.create');

        return $this->handleCustomerForm(new Customer(), $request, $entityManager);
    }

    #[Route(path: '/customers/{id}', name: 'production_customer_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function customerShow(Customer $customer): Response
    {
        $this->denyAccessUnlessGranted('@production_customers.read');

        return $this->render('production/customer/show.html.twig', ['customer' => $customer]);
    }

    #[Route(path: '/customers/{id}/edit', name: 'production_customer_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function customerEdit(Customer $customer, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_customers.edit');

        return $this->handleCustomerForm($customer, $request, $entityManager);
    }

    #[Route(path: '/customers/{id}/delete', name: 'production_customer_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function customerDelete(Customer $customer, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_customers.delete');
        if (!$this->isCsrfTokenValid('delete_customer_'.$customer->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $projectCount = $customer->getProjects()->count();
        if ($projectCount > 0) {
            $this->addFlash('error', $this->translator->trans('production.customer.delete_has_orders', ['%orders%' => $projectCount], 'production'));

            return $this->redirectToRoute('production_customer_show', ['id' => $customer->getId()]);
        }
        $entityManager->remove($customer);
        $entityManager->flush();
        $this->addFlash('success', $this->translator->trans('production.customer.deleted', ['%projects%' => $projectCount], 'production'));

        return $this->redirectToRoute('production_customer_index');
    }

    #[Route(path: '/customer-projects', name: 'production_customer_project_index', methods: ['GET', 'POST'])]
    public function customerProjectIndex(Request $request, DataTableFactory $dataTableFactory, TableSettings $tableSettings, CustomerRepository $customerRepository): Response
    {
        $this->denyAccessUnlessGranted('@production_orders.read');
        $statusFilter = $request->query->getString('status');
        $status = CustomerProjectStatus::tryFrom($statusFilter);
        $activeOnly = 'all' !== $statusFilter && null === $status;
        $customerValue = $request->query->getString('customer');
        $customerId = ctype_digit($customerValue) ? (int) $customerValue : null;
        $yearValue = $request->query->getString('year');
        $year = ctype_digit($yearValue) ? (int) $yearValue : null;
        $year = $year >= 2000 && $year <= ((int) date('Y') + 1) ? $year : null;
        $searchQuery = trim($request->query->getString('q'));
        $searchQuery = '' !== $searchQuery ? mb_substr($searchQuery, 0, 200) : null;
        $table = $dataTableFactory->createFromType(ProductionOrderDataTable::class, [
            'active_only' => $activeOnly,
            'status' => $status?->value,
            'customer_id' => $customerId > 0 ? $customerId : null,
            'year' => $year,
            'search_query' => $searchQuery,
        ], [
            'pageLength' => $tableSettings->fullDefaultPageSize,
            'lengthMenu' => PartsDataTable::LENGTH_MENU,
        ]);
        $table->setTemplate('@DataTables/datatable_html.html.twig', ['className' => 'table table-striped table-hover align-middle data-table']);
        $table->handleRequest($request);
        if ($table->isCallback()) {
            return $table->getResponse();
        }

        return $this->render('production/customer_project/index.html.twig', [
            'datatable' => $table,
            'selected_status' => $status?->value,
            'selected_status_filter' => $activeOnly ? 'active' : (null === $status ? 'all' : $status->value),
            'selected_customer' => $customerId > 0 ? $customerId : null,
            'selected_year' => $year,
            'search_query' => $searchQuery,
            'filters_open' => null !== $searchQuery || $customerId > 0 || null !== $year || !$activeOnly,
            'customers' => $customerRepository->findBy([], ['name' => 'ASC']),
            'project_statuses' => CustomerProjectStatus::cases(),
        ]);
    }

    #[Route(path: '/customer-projects/mine', name: 'production_customer_project_mine', methods: ['GET', 'POST'])]
    public function customerProjectMine(Request $request, DataTableFactory $dataTableFactory, TableSettings $tableSettings, CustomerRepository $customerRepository): Response
    {
        $this->denyAccessUnlessGranted('@production_orders.read');
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $statusFilter = $request->query->getString('status');
        $status = CustomerProjectStatus::tryFrom($statusFilter);
        $activeOnly = 'all' !== $statusFilter && null === $status;
        $customerValue = $request->query->getString('customer');
        $customerId = ctype_digit($customerValue) ? (int) $customerValue : null;
        $yearValue = $request->query->getString('year');
        $year = ctype_digit($yearValue) ? (int) $yearValue : null;
        $year = $year >= 2000 && $year <= ((int) date('Y') + 1) ? $year : null;
        $searchQuery = trim($request->query->getString('q'));
        $searchQuery = '' !== $searchQuery ? mb_substr($searchQuery, 0, 200) : null;
        $table = $dataTableFactory->createFromType(ProductionOrderDataTable::class, [
            'active_only' => false,
            'status' => $activeOnly ? CustomerProjectStatus::InProduction->value : $status?->value,
            'assigned_user' => $user,
            'customer_id' => $customerId > 0 ? $customerId : null,
            'year' => $year,
            'search_query' => $searchQuery,
        ], [
            'pageLength' => $tableSettings->fullDefaultPageSize,
            'lengthMenu' => PartsDataTable::LENGTH_MENU,
        ]);
        $table->setTemplate('@DataTables/datatable_html.html.twig', ['className' => 'table table-striped table-hover align-middle data-table']);
        $table->handleRequest($request);
        if ($table->isCallback()) {
            return $table->getResponse();
        }

        return $this->render('production/customer_project/index.html.twig', [
            'datatable' => $table,
            'title' => 'production.customer_project.my_projects',
            'my_projects' => true,
            'selected_status' => $status?->value,
            'selected_status_filter' => $activeOnly ? 'active' : (null === $status ? 'all' : $status->value),
            'selected_customer' => $customerId > 0 ? $customerId : null,
            'selected_year' => $year,
            'search_query' => $searchQuery,
            'filters_open' => null !== $searchQuery || $customerId > 0 || null !== $year || !$activeOnly,
            'customers' => $customerRepository->findBy([], ['name' => 'ASC']),
            'project_statuses' => CustomerProjectStatus::cases(),
        ]);
    }

    #[Route(path: '/customer-projects/new', name: 'production_customer_project_new', methods: ['GET', 'POST'])]
    public function customerProjectNew(Request $request, EntityManagerInterface $entityManager, CustomerRepository $customers, ProductionProjectRepository $projects): Response
    {
        $this->denyAccessUnlessGranted('@production_orders.create');

        $project = new CustomerProject();
        $customerId = $request->query->getInt('customer');
        if ($customerId > 0) {
            $project->setCustomer($customers->find($customerId));
        }
        $productionProjectId = $request->query->getInt('project');
        if ($productionProjectId > 0) {
            $project->setProductionProject($projects->find($productionProjectId));
        }

        return $this->handleCustomerProjectForm($project, $request, $entityManager);
    }

    #[Route(path: '/customer-projects/{id}', name: 'production_customer_project_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function customerProjectShow(CustomerProject $project, ProductionMaterialPlanner $materialPlanner, OrderAttachmentStorage $attachmentStorage): Response
    {
        $this->denyAccessUnlessGranted('@production_orders.read');

        return $this->render('production/customer_project/show.html.twig', [
            'project' => $project,
            'material_plan' => $this->isGranted('@production_material.read')
                ? $materialPlanner->createPlan($project, $project->getProductionSite())
                : null,
            'max_attachment_size' => $attachmentStorage->getMaximumFileSize(),
        ]);
    }

    #[Route(path: '/customer-projects/{id}/edit', name: 'production_customer_project_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function customerProjectEdit(CustomerProject $project, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_orders.edit');

        return $this->handleCustomerProjectForm($project, $request, $entityManager);
    }

    #[Route(path: '/customer-projects/{id}/delete', name: 'production_customer_project_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function customerProjectDelete(CustomerProject $project, Request $request, EntityManagerInterface $entityManager, OrderAttachmentStorage $attachmentStorage): Response
    {
        $this->denyAccessUnlessGranted('@production_orders.delete');
        if (!$this->isCsrfTokenValid('delete_customer_project_'.$project->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        if (!$project->getMaterialAllocations()->isEmpty()) {
            $this->addFlash('error', $this->translator->trans('production.customer_project.delete_has_material', domain: 'production'));

            return $this->redirectToRoute('production_customer_project_show', ['id' => $project->getId()]);
        }

        $buildCount = $project->getBuildInstances()->count();
        foreach ($project->getBuildInstances()->toArray() as $buildInstance) {
            $buildInstance->setProjectPosition(null);
        }
        $attachments = $project->getAttachments()->toArray();
        $entityManager->remove($project);
        $entityManager->flush();
        foreach ($attachments as $attachment) {
            $attachmentStorage->remove($attachment);
        }
        $this->addFlash('success', $this->translator->trans('production.customer_project.deleted', ['%builds%' => $buildCount], 'production'));

        return $this->redirectToRoute('production_customer_project_index');
    }

    #[Route(path: '/customer-projects/{id}/positions/new', name: 'production_project_position_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function projectPositionNew(CustomerProject $project, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_orders.edit');

        return $this->handleProjectPositionForm(
            (new ProjectPosition())->setCustomerProject($project),
            $request,
            $entityManager,
        );
    }

    #[Route(path: '/project-positions/{id}/edit', name: 'production_project_position_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function projectPositionEdit(ProjectPosition $position, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_orders.edit');

        return $this->handleProjectPositionForm($position, $request, $entityManager);
    }

    #[Route(path: '/project-positions/{id}/build', name: 'production_project_position_build', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function projectPositionBuild(ProjectPosition $position, Request $request, ProductionBuildWorkflow $workflow): Response
    {
        $this->denyAccessUnlessGranted('@production_orders.edit');
        $this->denyAccessUnlessGranted('@production_build_instances.build');
        $project = $position->getCustomerProject();
        if (!$project instanceof CustomerProject) {
            throw $this->createNotFoundException('This position has no customer project.');
        }
        if (CustomerProjectStatus::InProduction !== $project->getStatus()) {
            $this->addFlash('info', $this->translator->trans('production.project_position.build_requires_production', domain: 'production'));

            return $this->redirectToRoute('production_customer_project_show', ['id' => $project->getId()]);
        }
        foreach ($position->getBuildProjects() as $buildProject) {
            $this->denyAccessUnlessGranted('read', $buildProject);
        }
        if (!$position->getBuildInstances()->isEmpty()) {
            $this->addFlash('info', $this->translator->trans('production.build_instance.position_already_assigned', domain: 'production'));

            return $this->redirectToRoute('production_customer_project_show', ['id' => $project->getId()]);
        }
        $token = bin2hex(random_bytes(16));
        $request->getSession()->set('production_build_'.$token, $workflow->createDraft($position->getSystemTemplate() ?? $position->getTemplateProject(), $position));

        return $this->redirectToRoute('production_build_workflow_next', ['token' => $token]);
    }

    #[Route(path: '/project-positions/{id}/delete', name: 'production_project_position_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function projectPositionDelete(ProjectPosition $position, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_orders.edit');
        $project = $position->getCustomerProject();
        if (!$project instanceof CustomerProject) {
            throw $this->createNotFoundException('This position is not assigned to a project.');
        }

        if (!$this->isCsrfTokenValid('delete_project_position_'.$position->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($this->positionTreeHasBuildInstances($position)) {
            $this->addFlash('error', $this->translator->trans('production.project_position.delete_has_builds', domain: 'production'));

            return $this->redirectToRoute('production_customer_project_show', ['id' => $project->getId()]);
        }

        if (!$project->getMaterialAllocations()->isEmpty()) {
            $this->addFlash('error', $this->translator->trans('production.project_position.delete_has_material', domain: 'production'));

            return $this->redirectToRoute('production_customer_project_show', ['id' => $project->getId()]);
        }

        $positionName = $position->getName();
        $this->removePositionTree($position, $entityManager);
        $this->historyRecorder->record($project, 'position_deleted', $positionName);
        $entityManager->flush();
        $this->addFlash('success', $this->translator->trans('production.project_position.deleted', domain: 'production'));

        return $this->redirectToRoute('production_customer_project_show', ['id' => $project->getId()]);
    }

    #[Route(path: '/project-positions/{id}/configure', name: 'production_project_position_configure', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function projectPositionConfigure(ProjectPosition $position, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_orders.edit');
        $template = $position->getSystemTemplate();
        if (!$template instanceof SystemTemplate) {
            throw $this->createNotFoundException('This position has no system template.');
        }

        $builder = $this->createFormBuilder(null, [
            'translation_domain' => 'production',
            'method' => 'POST',
        ]);
        foreach ($template->getSlots() as $slot) {
            $assignments = $position->getAssignmentsForSlot($slot);
            $assignment = $assignments[0] ?? null;
            $partAssignment = $position->getPartAssignmentForSlot($slot);
            $choices = [
                ...$slot->getAllowedSystemTemplates()->toArray(),
                ...$slot->getAllowedProjects()->toArray(),
                ...$slot->getAllowedParts()->toArray(),
            ];
            $builder->add('content_'.$slot->getId(), ChoiceType::class, [
                'label' => $slot->getName(),
                'choices' => $choices,
                'choice_label' => static fn(SystemTemplate|Project|Part $item): string => match (true) {
                    $item instanceof SystemTemplate => 'System: '.$item->getName(),
                    $item instanceof Project => 'Bauprojekt: '.$item->getFullPath(),
                    default => sprintf('Lagerteil: %s (#%d)', $item->getName(), $item->getId()),
                },
                'choice_value' => static fn(SystemTemplate|Project|Part|null $item): string => match (true) {
                    $item instanceof SystemTemplate => 'system_'.$item->getId(),
                    $item instanceof Project => 'project_'.$item->getId(),
                    $item instanceof Part => 'part_'.$item->getId(),
                    default => '',
                },
                // Required template slots may deliberately be configured in
                // several passes. An empty value therefore remains saveable,
                // while the project completeness check still marks it open.
                'required' => false,
                'placeholder' => $slot->isRequired()
                    ? 'production.project_position.slot.choice_required'
                    : 'production.project_position.slot.empty',
                'data' => $assignment?->getSystemTemplate() ?? $assignment?->getTemplateProject() ?? $partAssignment?->getPart(),
                'mapped' => false,
                'help' => 'production.project_position.slot.range',
                'help_translation_parameters' => [
                    '%min%' => (string) $slot->getMinQuantity(),
                    '%max%' => (string) $slot->getMaxQuantity(),
                ],
            ]);
            $quantityType = 1 === $slot->getMaxQuantity() ? HiddenType::class : IntegerType::class;
            $quantityOptions = [
                'label' => 'production.project_position.quantity',
                'data' => [] !== $assignments
                    ? array_sum(array_map(static fn(ProjectPosition $item): int => $item->getQuantity(), $assignments))
                    : ($partAssignment?->getQuantity() ?? max(1, $slot->getMinQuantity())),
                'mapped' => false,
            ];
            if (IntegerType::class === $quantityType) {
                $quantityOptions['attr'] = ['min' => max(1, $slot->getMinQuantity()), 'max' => $slot->getMaxQuantity()];
            }
            $builder->add('quantity_'.$slot->getId(), $quantityType, $quantityOptions);
        }

        $form = $builder->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            foreach ($template->getSlots() as $slot) {
                $selected = $form->get('content_'.$slot->getId())->getData();
                $quantity = (int) $form->get('quantity_'.$slot->getId())->getData();
                $assignments = $position->getAssignmentsForSlot($slot);
                $partAssignment = $position->getPartAssignmentForSlot($slot);
                if (!$selected instanceof SystemTemplate && !$selected instanceof Project && !$selected instanceof Part) {
                    if ($this->anyPositionIsInUse($assignments)) {
                        $form->get('content_'.$slot->getId())->addError(new FormError($this->translator->trans('production.project_position.slot.in_use', domain: 'production')));
                    }
                    continue;
                }
                if ($quantity < $slot->getMinQuantity() || $quantity > $slot->getMaxQuantity()) {
                    $form->get('quantity_'.$slot->getId())->addError(new FormError($this->translator->trans('production.project_position.slot.quantity_invalid', domain: 'production')));
                }
                $allPositionsHaveSelectedContent = true;
                foreach ($assignments as $assignment) {
                    if (!(($selected instanceof SystemTemplate && $assignment->getSystemTemplate() === $selected)
                        || ($selected instanceof Project && $assignment->getTemplateProject() === $selected))) {
                        $allPositionsHaveSelectedContent = false;
                        break;
                    }
                }
                $positionsToRemove = array_slice($assignments, $quantity);
                if ((!$allPositionsHaveSelectedContent && $this->anyPositionIsInUse($assignments))
                    || $this->anyPositionIsInUse($positionsToRemove)) {
                    $form->get('content_'.$slot->getId())->addError(new FormError($this->translator->trans('production.project_position.slot.in_use', domain: 'production')));
                }
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            foreach ($template->getSlots() as $slot) {
                $selected = $form->get('content_'.$slot->getId())->getData();
                $assignments = $position->getAssignmentsForSlot($slot);
                $partAssignment = $position->getPartAssignmentForSlot($slot);
                if (!$selected instanceof SystemTemplate && !$selected instanceof Project && !$selected instanceof Part) {
                    foreach ($assignments as $assignment) {
                        $position->removeChild($assignment);
                        $entityManager->remove($assignment);
                    }
                    if (null !== $partAssignment) {
                        $entityManager->remove($partAssignment);
                    }
                    continue;
                }
                $quantity = (int) $form->get('quantity_'.$slot->getId())->getData();
                if ($selected instanceof SystemTemplate || $selected instanceof Project) {
                    if (null !== $partAssignment) {
                        $entityManager->remove($partAssignment);
                    }

                    $legacyGroupedAssignment = 1 === count($assignments) && $assignments[0]->getQuantity() > 1
                        ? $assignments[0]
                        : null;
                    while (count($assignments) < $quantity) {
                        $assignment = $legacyGroupedAssignment instanceof ProjectPosition
                            ? $this->clonePositionConfiguration($legacyGroupedAssignment, $position, $entityManager)
                            : (new ProjectPosition())
                                ->setCustomerProject($position->getCustomerProject())
                                ->setSourceSlot($slot);
                        $position->addChild($assignment);
                        $entityManager->persist($assignment);
                        $assignments[] = $assignment;
                    }

                    foreach (array_slice($assignments, $quantity) as $assignment) {
                        $position->removeChild($assignment);
                        $this->removePositionTree($assignment, $entityManager);
                    }
                    $assignments = array_slice($assignments, 0, $quantity);

                    foreach ($assignments as $index => $assignment) {
                        $isNewAssignment = null === $assignment->getId();
                        $assignment
                            ->setName($quantity > 1 ? sprintf('%s %d', $slot->getName(), $index + 1) : $slot->getName())
                            ->setPosition($slot->getPosition())
                            ->setQuantity(1);
                        if ($selected instanceof SystemTemplate) {
                            $assignment->setSystemTemplate($selected);
                            if ($isNewAssignment) {
                                $this->positionInitializer->initializeRequiredDefaults($assignment);
                            }
                        } else {
                            $assignment->setTemplateProject($selected);
                        }
                    }
                    continue;
                }
                foreach ($assignments as $assignment) {
                    $position->removeChild($assignment);
                    $entityManager->remove($assignment);
                }
                if (null === $partAssignment) {
                    $partAssignment = (new ProjectAccessory())
                        ->setProjectPosition($position)
                        ->setSourceSlot($slot);
                    $entityManager->persist($partAssignment);
                }
                $partAssignment
                    ->setPart($selected)
                    ->setQuantity($quantity)
                    ->setSerialTracking($slot->isSerialTracking());
            }
            $project = $position->getCustomerProject();
            if ($project instanceof CustomerProject) {
                $this->historyRecorder->record($project, 'position_configured', $position->getName());
            }
            $entityManager->flush();
            $this->addFlash('success', 'production.flash.saved');

            return $this->redirectToRoute('production_customer_project_show', ['id' => $project?->getId()]);
        }

        return $this->render('production/configure_position.html.twig', [
            'form' => $form,
            'position' => $position,
            'template' => $template,
        ]);
    }

    #[Route(path: '/customer-projects/{id}/accessories/new', name: 'production_accessory_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function accessoryNew(CustomerProject $project, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_orders.edit');

        return $this->handleAccessoryForm(
            (new ProjectAccessory())->setCustomerProject($project),
            $request,
            $entityManager,
        );
    }

    #[Route(path: '/accessories/{id}/edit', name: 'production_accessory_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function accessoryEdit(ProjectAccessory $accessory, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_orders.edit');

        return $this->handleAccessoryForm($accessory, $request, $entityManager);
    }

    #[Route(path: '/customer-projects/{project}/materials/{part}/allocate', name: 'production_material_allocate', requirements: ['project' => '\d+', 'part' => '\d+'], methods: ['GET', 'POST'])]
    public function materialAllocate(
        #[MapEntity(id: 'project')] CustomerProject $project,
        #[MapEntity(id: 'part')] Part $part,
        Request $request,
        EntityManagerInterface $entityManager,
        ProductionMaterialPlanner $materialPlanner,
        PartLotWithdrawAddHelper $withdrawHelper,
    ): Response {
        $this->denyAccessUnlessGranted('@production_orders.edit');
        $this->denyAccessUnlessGranted('@production_material.provide');
        $this->denyAccessUnlessGranted('@parts_stock.withdraw');
        if (CustomerProjectStatus::InProduction !== $project->getStatus()) {
            $this->addFlash('warning', 'production.material_plan.production_only');

            return $this->redirectToRoute('production_customer_project_show', ['id' => $project->getId()]);
        }
        $productionSite = $project->getProductionSite();
        if (!$productionSite instanceof StorageLocation) {
            $this->addFlash('warning', 'Bitte zuerst einen Fertigungsstandort am Auftrag festlegen.');

            return $this->redirectToRoute('production_customer_project_edit', ['id' => $project->getId()]);
        }

        $planItem = null;
        foreach ($materialPlanner->createPlan($project, $productionSite)['items'] as $item) {
            if ($item['part'] === $part) {
                $planItem = $item;
                break;
            }
        }
        if (null === $planItem) {
            throw $this->createNotFoundException('This part is not required by the project.');
        }
        if ($planItem['remaining'] < 1) {
            $this->addFlash('info', 'Der Materialbedarf für dieses Bauteil ist bereits vollständig gedeckt.');

            return $this->redirectToRoute('production_customer_project_show', ['id' => $project->getId()]);
        }

        $lots = array_values(array_filter(
            $part->getPartLots()->toArray(),
            fn(PartLot $lot): bool => !$lot->isInstockUnknown()
                && $this->reservationManager->lotBelongsToSite($lot, $productionSite)
                && $this->reservationManager->availableToProject($lot, $project) > 0,
        ));
        $serialRequired = $project->requiresSerialTracking($part);
        $remainingQuantity = max(1, (int) ceil($planItem['remaining']));
        $form = $this->createFormBuilder(null, ['translation_domain' => 'production', 'method' => 'POST'])
            ->add('lot', ChoiceType::class, [
                'label' => 'production.material_plan.source_lot',
                'choices' => $lots,
                'choice_label' => static function (PartLot $lot): string {
                    $location = $lot->getStorageLocation()?->getName() ?? '–';
                    $serial = $lot->getUserBarcode();

                    return sprintf('%s | %s | Bestand: %s%s', $lot->getDescription() ?: '#'.$lot->getId(), $location, $lot->getAmount(), $serial ? ' | SN: '.$serial : '');
                },
                'choice_value' => static fn(?PartLot $lot): string => null === $lot ? '' : (string) $lot->getId(),
                'placeholder' => 'production.material_plan.choose_lot',
            ])
            ->add('quantity', IntegerType::class, [
                'label' => 'production.material_plan.allocate_quantity',
                'data' => 1,
                'html5' => true,
                'attr' => ['min' => 1, 'max' => $serialRequired ? 1 : $remainingQuantity, 'step' => 1],
            ])
            ->add('serialNumber', TextType::class, [
                'label' => 'production.material_plan.serial_number',
                'required' => false,
                'help' => $serialRequired ? 'production.material_plan.serial_number_required_help' : 'production.material_plan.serial_number_help',
            ])
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            $lot = $form->get('lot')->getData();
            $quantity = (int) $form->get('quantity')->getData();
            $serialNumber = trim((string) $form->get('serialNumber')->getData());
            if (!$lot instanceof PartLot || $lot->getPart() !== $part) {
                $form->get('lot')->addError(new FormError($this->translator->trans('production.material_plan.invalid_lot', domain: 'production')));
            } elseif ($quantity <= 0 || $quantity > $this->reservationManager->availableToProject($lot, $project) || $quantity > $remainingQuantity || ($serialRequired && 1 !== $quantity)) {
                $form->get('quantity')->addError(new FormError($this->translator->trans('production.material_plan.invalid_quantity', domain: 'production')));
            }
            if ($serialRequired && '' === $serialNumber && (!$lot instanceof PartLot || !$lot->getUserBarcode())) {
                $form->get('serialNumber')->addError(new FormError($this->translator->trans('production.material_plan.serial_number_required', domain: 'production')));
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var PartLot $lot */
            $lot = $form->get('lot')->getData();
            $quantity = (int) $form->get('quantity')->getData();
            $serialNumber = trim((string) $form->get('serialNumber')->getData()) ?: $lot->getUserBarcode();
            $withdrawHelper->withdraw($lot, $quantity, sprintf('Auftragsbestand %s', $project->getProjectNumber()));
            $releasedReservation = $this->reservationManager->consumeForProvision($project, $part, $lot, $quantity);
            $allocation = (new ProjectMaterialAllocation())
                ->setCustomerProject($project)
                ->setPart($part)
                ->setSourcePartLot($lot)
                ->setQuantity($quantity)
                ->setSerialNumber($serialNumber)
                ->setAllocatedBy($this->getUser() instanceof User ? $this->getUser() : null);
            $entityManager->persist($allocation);
            $this->historyRecorder->record($project, 'material_allocated', sprintf('%s × %s', $part->getName(), $quantity));
            if ($releasedReservation > 0) {
                $this->historyRecorder->record($project, 'material_reservation_consumed', sprintf('%s × %s bereitgestellt', $part->getName(), $releasedReservation));
            }
            $entityManager->flush();
            $this->addFlash('success', 'production.material_plan.allocation_success');

            return $this->redirectToRoute('production_customer_project_show', ['id' => $project->getId()]);
        }

        return $this->render('production/material_allocate.html.twig', [
            'form' => $form,
            'project' => $project,
            'part' => $part,
            'plan_item' => $planItem,
            'serial_required' => $serialRequired,
        ]);
    }

    #[Route(path: '/build-instances', name: 'production_build_instance_index', methods: ['GET', 'POST'])]
    public function buildInstanceIndex(Request $request, DataTableFactory $dataTableFactory, TableSettings $tableSettings, CustomerRepository $customerRepository): Response
    {
        $this->denyAccessUnlessGranted('@production_build_instances.read');
        $statusFilter = $request->query->getString('status');
        $status = BuildStatus::tryFrom($statusFilter);
        $activeOnly = 'all' !== $statusFilter && null === $status;
        $customerValue = $request->query->getString('customer');
        $customerId = ctype_digit($customerValue) ? (int) $customerValue : null;
        $yearValue = $request->query->getString('year');
        $year = ctype_digit($yearValue) ? (int) $yearValue : null;
        $year = $year >= 2000 && $year <= ((int) date('Y') + 1) ? $year : null;
        $searchQuery = trim($request->query->getString('q'));
        $searchQuery = '' !== $searchQuery ? mb_substr($searchQuery, 0, 200) : null;
        $table = $dataTableFactory->createFromType(ProductionBuildInstanceDataTable::class, [
            'active_only' => $activeOnly,
            'status' => $status?->value,
            'customer_id' => $customerId > 0 ? $customerId : null,
            'year' => $year,
            'search_query' => $searchQuery,
        ], [
            'pageLength' => $tableSettings->fullDefaultPageSize,
            'lengthMenu' => PartsDataTable::LENGTH_MENU,
        ]);
        $table->setTemplate('@DataTables/datatable_html.html.twig', ['className' => 'table table-striped table-hover align-middle data-table']);
        $table->handleRequest($request);
        if ($table->isCallback()) {
            return $table->getResponse();
        }

        return $this->render('production/build_instance/index.html.twig', [
            'datatable' => $table,
            'selected_status' => $status?->value,
            'selected_status_filter' => $activeOnly ? 'active' : (null === $status ? 'all' : $status->value),
            'selected_customer' => $customerId > 0 ? $customerId : null,
            'selected_year' => $year,
            'search_query' => $searchQuery,
            'filters_open' => null !== $searchQuery || $customerId > 0 || null !== $year || !$activeOnly,
            'build_statuses' => BuildStatus::cases(),
            'customers' => $customerRepository->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route(path: '/build-instances/new', name: 'production_build_instance_new', methods: ['GET', 'POST'])]
    public function buildInstanceNew(
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response
    {
        $this->denyAccessUnlessGranted('@production_build_instances.create');

        $buildInstance = new BuildInstance();

        return $this->handleBuildInstanceForm($buildInstance, $request, $entityManager, true);
    }

    #[Route(path: '/build-instances/{id}', name: 'production_build_instance_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function buildInstanceShow(BuildInstance $buildInstance): Response
    {
        $this->denyAccessUnlessGranted('@production_build_instances.read');
        foreach ($buildInstance->getBuildProjects() as $buildProject) {
            $this->denyAccessUnlessGranted('read', $buildProject);
        }

        return $this->render('production/build_instance/show.html.twig', ['build_instance' => $buildInstance]);
    }

    #[Route(path: '/build-instances/{id}/edit', name: 'production_build_instance_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function buildInstanceEdit(BuildInstance $buildInstance, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_build_instances.edit');

        return $this->handleBuildInstanceForm($buildInstance, $request, $entityManager);
    }

    #[Route(path: '/build-instances/{id}/delete', name: 'production_build_instance_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function buildInstanceDelete(BuildInstance $buildInstance, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_build_instances.delete');
        if (!$this->isCsrfTokenValid('delete_build_instance_'.$buildInstance->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $identifier = $buildInstance->getDisplayIdentifier();
        $project = $buildInstance->getCustomerProject() ?? $buildInstance->getProjectPosition()?->getCustomerProject();
        foreach ($buildInstance->getChildren()->toArray() as $child) {
            $child->setParent(null);
        }
        $buildInstance->setProjectPosition(null);
        $buildInstance->setParent(null);
        if ($project instanceof CustomerProject) {
            $this->historyRecorder->record($project, 'build_deleted', $identifier);
        }
        $entityManager->remove($buildInstance);
        $entityManager->flush();
        $this->addFlash('success', $this->translator->trans('production.build_instance.deleted', ['%serial%' => $identifier], 'production'));

        return $this->redirectToRoute('production_build_instance_index');
    }

    #[Route(path: '/project-positions/{id}/assign-instance', name: 'production_project_position_assign_instance', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function projectPositionAssignInstance(
        ProjectPosition $position,
        Request $request,
        BuildInstanceRepository $repository,
        BuildConfigurationCompatibility $compatibility,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->denyAccessUnlessGranted('@production_orders.edit');
        $this->denyAccessUnlessGranted('@production_build_instances.assign');
        $project = $position->getCustomerProject();
        if (!$project instanceof CustomerProject) {
            throw $this->createNotFoundException('This position has no customer project.');
        }
        if (CustomerProjectStatus::InProduction !== $project->getStatus()) {
            $this->addFlash('info', $this->translator->trans('production.project_position.build_requires_production', domain: 'production'));

            return $this->redirectToRoute('production_customer_project_show', ['id' => $project->getId()]);
        }
        if (!$position->getBuildInstances()->isEmpty()) {
            $this->addFlash('info', $this->translator->trans('production.build_instance.position_already_assigned', domain: 'production'));

            return $this->redirectToRoute('production_customer_project_show', ['id' => $project->getId()]);
        }

        $assignableInstances = array_values(array_filter(
            $repository->findAssignmentCandidates($position),
            static fn(BuildInstance $instance): bool => $compatibility->isCompatible($instance, $position),
        ));
        $builder = $this->createFormBuilder(null, [
            'translation_domain' => 'production',
            'method' => 'POST',
        ]);
        $builder->add('buildInstance', ChoiceType::class, [
            'label' => 'production.build_instance.assign_choice',
            'choices' => $assignableInstances,
            'choice_label' => fn(BuildInstance $instance): string => sprintf(
                '%s · %s%s',
                $instance->getDisplayIdentifier(),
                $this->translator->trans('production.build_instance.status.'.$instance->getStatus()->value, domain: 'production'),
                null !== $instance->getLocation() ? ' · '.$instance->getLocation() : '',
            ),
            'choice_value' => static fn(?BuildInstance $instance): string => null === $instance ? '' : (string) $instance->getId(),
            'placeholder' => 'production.build_instance.assign_placeholder',
            'required' => true,
            'mapped' => false,
        ]);
        $form = $builder->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $buildInstance = $form->get('buildInstance')->getData();
            if (!$buildInstance instanceof BuildInstance || !$compatibility->assign($buildInstance, $position)) {
                $form->addError(new FormError($this->translator->trans('production.build_instance.assign_invalid', domain: 'production')));
            } else {
                $this->historyRecorder->record(
                    $project,
                    'build_assigned',
                    $buildInstance->getDisplayIdentifier(),
                    $buildInstance,
                );
                $entityManager->flush();
                $this->addFlash('success', $this->translator->trans('production.build_instance.assign_success', domain: 'production'));

                return $this->redirectToRoute('production_customer_project_show', ['id' => $project->getId()]);
            }
        }

        return $this->render('production/build_instance/assign.html.twig', [
            'form' => $form,
            'position' => $position,
            'assignable_instances' => $assignableInstances,
        ]);
    }

    #[Route(path: '/build-instances/{id}/unassign', name: 'production_build_instance_unassign', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function buildInstanceUnassign(BuildInstance $buildInstance, Request $request, BuildConfigurationCompatibility $compatibility, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_orders.edit');
        $this->denyAccessUnlessGranted('@production_build_instances.assign');
        if (!$this->isCsrfTokenValid('unassign_build_instance_'.$buildInstance->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Also accept records created by older extension versions where only the
        // project-position relation remained set after an attempted unassignment.
        $project = $buildInstance->getCustomerProject() ?? $buildInstance->getProjectPosition()?->getCustomerProject();
        if (!$project instanceof CustomerProject) {
            $this->addFlash('info', $this->translator->trans('production.build_instance.already_unassigned', domain: 'production'));

            return $this->redirectToRoute('production_build_instance_show', ['id' => $buildInstance->getId()]);
        }

        if (!$compatibility->unassign($buildInstance)) {
            $this->addFlash('error', 'Bitte zuerst die diesem Gerät zugewiesenen Unterbaugruppen lösen.');

            return $this->redirectToRoute('production_customer_project_show', ['id' => $project->getId()]);
        }
        $this->historyRecorder->record(
            $project,
            'build_unassigned',
            $buildInstance->getDisplayIdentifier(),
            $buildInstance,
        );
        $entityManager->flush();
        $this->addFlash('success', $this->translator->trans('production.build_instance.unassign_success', domain: 'production'));

        return $this->redirectToRoute('production_customer_project_show', ['id' => $project->getId()]);
    }

    private function handleCustomerForm(Customer $customer, Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(CustomerType::class, $customer);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($customer);
            $entityManager->flush();
            $this->addFlash('success', 'production.flash.saved');

            return $this->redirectToRoute('production_customer_show', ['id' => $customer->getId()]);
        }

        return $this->render('production/form.html.twig', [
            'form' => $form,
            'title' => null === $customer->getId() ? 'production.customer.new' : 'production.customer.edit',
            'cancel_route' => 'production_customer_index',
            'delete_route' => null === $customer->getId() ? null : 'production_customer_delete',
            'delete_permission' => '@production_customers.delete',
            'delete_route_params' => ['id' => $customer->getId()],
            'delete_token_id' => 'delete_customer_'.$customer->getId(),
            'delete_confirm' => $this->translator->trans('production.customer.delete_confirm', [
                '%name%' => $customer->getName(),
                '%projects%' => $customer->getProjects()->count(),
            ], 'production'),
        ]);
    }

    private function handleCustomerProjectForm(CustomerProject $project, Request $request, EntityManagerInterface $entityManager): Response
    {
        $isNew = null === $project->getId();
        $previousStatus = $project->getStatus();
        $previousProductionSiteId = $project->getProductionSite()?->getId();
        $form = $this->createForm(CustomerProjectType::class, $project);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($project);
            $this->historyRecorder->record($project, $isNew ? 'project_created' : 'project_updated');
            $activeReservationStatuses = [CustomerProjectStatus::Commissioned, CustomerProjectStatus::InProduction];
            $released = 0;
            $productionSiteChanged = $previousProductionSiteId !== $project->getProductionSite()?->getId();
            if ($productionSiteChanged && !$project->getMaterialReservations()->isEmpty()) {
                $released += $this->reservationManager->release($project);
            }
            if (in_array($previousStatus, $activeReservationStatuses, true)
                && !in_array($project->getStatus(), $activeReservationStatuses, true)) {
                $released += $this->reservationManager->release($project);
            }
            $entityManager->flush();
            $this->addFlash('success', 'production.flash.saved');
            if ($released > 0) {
                $this->addFlash('info', sprintf('%s reservierte Teile wurden aufgrund des neuen Auftragsstatus freigegeben.', $released));
            }
            if (CustomerProjectStatus::Commissioned === $project->getStatus()
                && CustomerProjectStatus::Commissioned !== $previousStatus) {
                $this->addFlash('info', 'Der Auftrag ist jetzt beauftragt. Bitte prüfen und bestätigen Sie die vorgeschlagene Materialreservierung. Der Lagerbestand wird dabei noch nicht verändert.');

                return $this->redirectToRoute('production_material_reservation', ['id' => $project->getId()]);
            }
            if ($productionSiteChanged
                && $project->getProductionSite() instanceof StorageLocation
                && in_array($project->getStatus(), $activeReservationStatuses, true)) {
                $this->addFlash('info', 'Der Fertigungsstandort wurde geändert. Bitte die standortbezogene Materialreservierung prüfen und bestätigen.');

                return $this->redirectToRoute('production_material_reservation', ['id' => $project->getId()]);
            }

            return $this->redirectToRoute('production_customer_project_show', ['id' => $project->getId()]);
        }

        return $this->render('production/form.html.twig', [
            'form' => $form,
            'title' => null === $project->getId() ? 'production.customer_project.new' : 'production.customer_project.edit',
            'cancel_route' => 'production_customer_project_index',
            'delete_route' => null === $project->getId() ? null : 'production_customer_project_delete',
            'delete_permission' => '@production_orders.delete',
            'delete_route_params' => ['id' => $project->getId()],
            'delete_token_id' => 'delete_customer_project_'.$project->getId(),
            'delete_confirm' => $this->translator->trans('production.customer_project.delete_confirm', [
                '%number%' => $project->getProjectNumber(),
                '%builds%' => $project->getBuildInstances()->count(),
            ], 'production'),
        ]);
    }

    private function handleProjectPositionForm(ProjectPosition $position, Request $request, EntityManagerInterface $entityManager): Response
    {
        $isNew = null === $position->getId();
        $form = $this->createForm(ProjectPositionType::class, $position);
        $form->handleRequest($request);
        if ($form->isSubmitted() && null === $position->getParent()) {
            $position->setQuantity(1);
        }
        if ($form->isSubmitted() && $form->isValid()) {
            foreach ($position->getBuildProjects() as $buildProject) {
                $this->denyAccessUnlessGranted('read', $buildProject);
            }
            $entityManager->persist($position);
            if ($isNew) {
                $this->positionInitializer->initializeRequiredDefaults($position);
            }
            $project = $position->getCustomerProject();
            if ($project instanceof CustomerProject) {
                $this->historyRecorder->record(
                    $project,
                    $isNew ? 'position_created' : 'position_updated',
                    $position->getName(),
                );
            }
            $entityManager->flush();
            $this->addFlash('success', 'production.flash.saved');

            return $this->redirectToRoute('production_customer_project_show', ['id' => $project?->getId()]);
        }

        $project = $position->getCustomerProject();

        return $this->render('production/form.html.twig', [
            'form' => $form,
            'title' => $isNew ? 'production.project_position.new' : 'production.project_position.edit',
            'cancel_route' => 'production_customer_project_show',
            'cancel_route_params' => ['id' => $project?->getId()],
        ]);
    }

    private function handleSystemTemplateForm(SystemTemplate $template, Request $request, EntityManagerInterface $entityManager): Response
    {
        $isNew = null === $template->getId();
        $form = $this->createForm(SystemTemplateType::class, $template);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            foreach ($template->getBaseProjects() as $baseProject) {
                $this->denyAccessUnlessGranted('read', $baseProject);
            }
            $entityManager->persist($template);
            $entityManager->flush();
            $this->addFlash('success', 'production.flash.saved');

            return $this->redirectToRoute('production_template_show', ['id' => $template->getId()]);
        }

        return $this->render('production/form.html.twig', [
            'form' => $form,
            'title' => $isNew ? 'production.system_template.new' : 'production.system_template.edit',
            'cancel_route' => 'production_template_index',
        ]);
    }

    private function handleSystemTemplateSlotForm(SystemTemplateSlot $slot, Request $request): Response
    {
        $isNew = null === $slot->getId();
        $previousPosition = $isNew ? null : $slot->getPosition();
        $form = $this->createForm(SystemTemplateSlotType::class, $slot);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            foreach ($slot->getAllowedProjects() as $project) {
                $this->denyAccessUnlessGranted('read', $project);
            }
            $this->slotPositioner->save($slot, $previousPosition);
            $template = $slot->getSystemTemplate();
            if ($template instanceof SystemTemplate) {
                $this->positionInitializer->synchronizeTemplatePositions($template);
            }
            $this->addFlash('success', 'production.flash.saved');

            return $this->redirectToRoute('production_template_show', ['id' => $slot->getSystemTemplate()?->getId()]);
        }

        return $this->render('production/form.html.twig', [
            'form' => $form,
            'title' => $isNew ? 'production.system_template.slot.new' : 'production.system_template.slot.edit',
            'cancel_route' => 'production_template_show',
            'cancel_route_params' => ['id' => $slot->getSystemTemplate()?->getId()],
        ]);
    }

    private function handleAccessoryForm(ProjectAccessory $accessory, Request $request, EntityManagerInterface $entityManager): Response
    {
        $isNew = null === $accessory->getId();
        $form = $this->createForm(ProjectAccessoryType::class, $accessory);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->denyAccessUnlessGranted('read', $accessory->getPart());
            $entityManager->persist($accessory);
            $project = $accessory->getCustomerProject();
            if ($project instanceof CustomerProject) {
                $this->historyRecorder->record(
                    $project,
                    $isNew ? 'accessory_created' : 'accessory_updated',
                    $accessory->getPart()?->getName() ?? '',
                );
            }
            $entityManager->flush();
            $this->addFlash('success', 'production.flash.saved');

            return $this->redirectToRoute('production_customer_project_show', ['id' => $project?->getId()]);
        }

        $project = $accessory->getCustomerProject();

        return $this->render('production/form.html.twig', [
            'form' => $form,
            'title' => $isNew ? 'production.accessory.new' : 'production.accessory.edit',
            'cancel_route' => 'production_customer_project_show',
            'cancel_route_params' => ['id' => $project?->getId()],
        ]);
    }

    private function handleBuildInstanceForm(
        BuildInstance $buildInstance,
        Request $request,
        EntityManagerInterface $entityManager,
        bool $registerExisting = false,
    ): Response
    {
        $isNew = null === $buildInstance->getId();
        $previousProject = $buildInstance->getCustomerProject();
        $form = $this->createForm(BuildInstanceType::class, $buildInstance, [
            'default_status' => $registerExisting ? BuildStatus::Completed : null,
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            foreach ($buildInstance->getBuildProjects() as $buildProject) {
                $this->denyAccessUnlessGranted('read', $buildProject);
            }
            $entityManager->persist($buildInstance);
            $project = $buildInstance->getCustomerProject();
            if ($project instanceof CustomerProject) {
                $this->historyRecorder->record(
                    $project,
                    $isNew ? 'build_created' : 'build_updated',
                    $buildInstance->getDisplayIdentifier(),
                    $buildInstance,
                );
            }
            if ($previousProject instanceof CustomerProject && $previousProject !== $project) {
                $this->historyRecorder->record(
                    $previousProject,
                    'build_unassigned',
                    $buildInstance->getDisplayIdentifier(),
                    $buildInstance,
                );
            }
            $entityManager->flush();
            $this->addFlash('success', 'production.flash.saved');

            return $this->redirectToRoute('production_build_instance_show', ['id' => $buildInstance->getId()]);
        }

        return $this->render('production/form.html.twig', [
            'form' => $form,
            'title' => $registerExisting ? 'production.build_instance.register_existing' : 'production.build_instance.edit',
            'intro' => $registerExisting ? 'production.build_instance.register_existing_intro' : null,
            'cancel_route' => $registerExisting ? 'production_build' : 'production_build_instance_index',
            'delete_route' => $isNew ? null : 'production_build_instance_delete',
            'delete_permission' => '@production_build_instances.delete',
            'delete_route_params' => ['id' => $buildInstance->getId()],
            'delete_token_id' => 'delete_build_instance_'.$buildInstance->getId(),
            'delete_confirm' => $this->translator->trans('production.build_instance.delete_confirm', [
                '%serial%' => $buildInstance->getDisplayIdentifier(),
            ], 'production'),
        ]);
    }

    private function positionTreeHasBuildInstances(ProjectPosition $position): bool
    {
        if (!$position->getBuildInstances()->isEmpty()) {
            return true;
        }

        foreach ($position->getChildren() as $child) {
            if ($this->positionTreeHasBuildInstances($child)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<ProjectPosition> $positions */
    private function anyPositionIsInUse(array $positions): bool
    {
        foreach ($positions as $position) {
            if (!$position->getBuildInstances()->isEmpty()
                || !$position->getChildren()->isEmpty()
                || !$position->getPartAssignments()->isEmpty()) {
                return true;
            }
        }

        return false;
    }

    private function clonePositionConfiguration(
        ProjectPosition $source,
        ProjectPosition $parent,
        EntityManagerInterface $entityManager,
    ): ProjectPosition {
        $clone = (new ProjectPosition())
            ->setCustomerProject($source->getCustomerProject())
            ->setParent($parent)
            ->setSourceSlot($source->getSourceSlot())
            ->setName($source->getName())
            ->setPosition($source->getPosition())
            ->setQuantity(1);
        if (null !== $source->getSystemTemplate()) {
            $clone->setSystemTemplate($source->getSystemTemplate());
        } else {
            $clone->setTemplateProject($source->getTemplateProject());
        }
        $entityManager->persist($clone);

        foreach ($source->getPartAssignments() as $partAssignment) {
            $entityManager->persist(
                (new ProjectAccessory())
                    ->setProjectPosition($clone)
                    ->setSourceSlot($partAssignment->getSourceSlot())
                    ->setPart($partAssignment->getPart())
                    ->setQuantity($partAssignment->getQuantity())
                    ->setSerialTracking($partAssignment->isSerialTracking())
                    ->setNote($partAssignment->getNote()),
            );
        }

        foreach ($source->getChildren() as $sourceChild) {
            $clone->addChild($this->clonePositionConfiguration($sourceChild, $clone, $entityManager));
        }

        return $clone;
    }

    private function removePositionTree(ProjectPosition $position, EntityManagerInterface $entityManager): void
    {
        foreach ($position->getChildren()->toArray() as $child) {
            $this->removePositionTree($child, $entityManager);
        }

        $entityManager->remove($position);
    }
}
