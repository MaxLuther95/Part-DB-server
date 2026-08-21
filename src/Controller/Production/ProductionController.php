<?php

declare(strict_types=1);

namespace App\Controller\Production;

use App\Entity\Parts\Part;
use App\Entity\Parts\PartLot;
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
use App\Form\Production\CustomerProjectType;
use App\Form\Production\CustomerType;
use App\Form\Production\ProjectAccessoryType;
use App\Form\Production\ProjectPositionType;
use App\Form\Production\SystemTemplateSlotType;
use App\Form\Production\SystemTemplateType;
use App\Repository\Production\BuildInstanceRepository;
use App\Repository\Production\CustomerProjectRepository;
use App\Repository\Production\CustomerRepository;
use App\Repository\Production\ProjectPositionRepository;
use App\Repository\Production\SystemTemplateRepository;
use App\Entity\Production\BuildStatus;
use App\Entity\ProjectSystem\Project;
use App\Entity\UserSystem\User;
use App\Services\Production\ProductionHistoryRecorder;
use App\Services\Production\ProductionMaterialPlanner;
use App\Services\Parts\PartLotWithdrawAddHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
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
    )
    {
    }

    #[Route(path: '', name: 'production_dashboard', methods: ['GET'])]
    public function dashboard(
        CustomerRepository $customers,
        CustomerProjectRepository $projects,
        BuildInstanceRepository $buildInstances,
    ): Response {
        $this->denyAccessUnlessGranted('@projects.read');

        return $this->render('production/dashboard.html.twig', [
            'customer_count' => $customers->count([]),
            'project_count' => $projects->count([]),
            'build_instance_count' => $buildInstances->count([]),
        ]);
    }

    #[Route(path: '/build', name: 'production_build', methods: ['GET'])]
    public function build(BuildInstanceRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('@projects.read');

        $activeStatuses = [BuildStatus::Planned, BuildStatus::InProgress, BuildStatus::Paused];
        $activeBuilds = array_values(array_filter(
            $repository->findBy([], ['lastModified' => 'DESC']),
            static fn(BuildInstance $instance): bool => in_array($instance->getStatus(), $activeStatuses, true),
        ));

        return $this->render('production/build.html.twig', ['build_instances' => $activeBuilds]);
    }

    #[Route(path: '/build-templates', name: 'production_template_index', methods: ['GET'])]
    public function templateIndex(SystemTemplateRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('@projects.read');

        return $this->render('production/template/index.html.twig', [
            'templates' => $repository->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route(path: '/build-templates/new', name: 'production_template_new', methods: ['GET', 'POST'])]
    public function templateNew(Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@projects.edit');

        return $this->handleSystemTemplateForm(new SystemTemplate(), $request, $entityManager);
    }

    #[Route(path: '/build-templates/{id}', name: 'production_template_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function templateShow(SystemTemplate $template): Response
    {
        $this->denyAccessUnlessGranted('@projects.read');

        return $this->render('production/template/show.html.twig', ['template' => $template]);
    }

    #[Route(path: '/build-templates/{id}/edit', name: 'production_template_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function templateEdit(SystemTemplate $template, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@projects.edit');

        return $this->handleSystemTemplateForm($template, $request, $entityManager);
    }

    #[Route(path: '/build-templates/{id}/slots/new', name: 'production_template_slot_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function templateSlotNew(SystemTemplate $template, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@projects.edit');

        return $this->handleSystemTemplateSlotForm(
            (new SystemTemplateSlot())->setSystemTemplate($template),
            $request,
            $entityManager,
        );
    }

    #[Route(path: '/build-template-slots/{id}/edit', name: 'production_template_slot_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function templateSlotEdit(SystemTemplateSlot $slot, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@projects.edit');

        return $this->handleSystemTemplateSlotForm($slot, $request, $entityManager);
    }

    #[Route(path: '/customers', name: 'production_customer_index', methods: ['GET'])]
    public function customerIndex(CustomerRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('@projects.read');

        return $this->render('production/customer/index.html.twig', [
            'customers' => $repository->findBy([], ['customerNumber' => 'ASC']),
        ]);
    }

    #[Route(path: '/customers/new', name: 'production_customer_new', methods: ['GET', 'POST'])]
    public function customerNew(Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@projects.edit');

        return $this->handleCustomerForm(new Customer(), $request, $entityManager);
    }

    #[Route(path: '/customers/{id}', name: 'production_customer_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function customerShow(Customer $customer): Response
    {
        $this->denyAccessUnlessGranted('@projects.read');

        return $this->render('production/customer/show.html.twig', ['customer' => $customer]);
    }

    #[Route(path: '/customers/{id}/edit', name: 'production_customer_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function customerEdit(Customer $customer, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@projects.edit');

        return $this->handleCustomerForm($customer, $request, $entityManager);
    }

    #[Route(path: '/customer-projects', name: 'production_customer_project_index', methods: ['GET'])]
    public function customerProjectIndex(CustomerProjectRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('@projects.read');

        return $this->render('production/customer_project/index.html.twig', [
            'projects' => $repository->findBy([], ['projectNumber' => 'ASC']),
        ]);
    }

    #[Route(path: '/customer-projects/new', name: 'production_customer_project_new', methods: ['GET', 'POST'])]
    public function customerProjectNew(Request $request, EntityManagerInterface $entityManager, CustomerRepository $customers): Response
    {
        $this->denyAccessUnlessGranted('@projects.edit');

        $project = new CustomerProject();
        $customerId = $request->query->getInt('customer');
        if ($customerId > 0) {
            $project->setCustomer($customers->find($customerId));
        }

        return $this->handleCustomerProjectForm($project, $request, $entityManager);
    }

    #[Route(path: '/customer-projects/{id}', name: 'production_customer_project_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function customerProjectShow(CustomerProject $project, ProductionMaterialPlanner $materialPlanner): Response
    {
        $this->denyAccessUnlessGranted('@projects.read');

        return $this->render('production/customer_project/show.html.twig', [
            'project' => $project,
            'material_plan' => $materialPlanner->createPlan($project),
        ]);
    }

    #[Route(path: '/customer-projects/{id}/edit', name: 'production_customer_project_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function customerProjectEdit(CustomerProject $project, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@projects.edit');

        return $this->handleCustomerProjectForm($project, $request, $entityManager);
    }

    #[Route(path: '/customer-projects/{id}/positions/new', name: 'production_project_position_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function projectPositionNew(CustomerProject $project, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@projects.edit');

        return $this->handleProjectPositionForm(
            (new ProjectPosition())->setCustomerProject($project),
            $request,
            $entityManager,
        );
    }

    #[Route(path: '/project-positions/{id}/edit', name: 'production_project_position_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function projectPositionEdit(ProjectPosition $position, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@projects.edit');

        return $this->handleProjectPositionForm($position, $request, $entityManager);
    }

    #[Route(path: '/project-positions/{id}/build', name: 'production_project_position_build', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function projectPositionBuild(ProjectPosition $position): Response
    {
        $this->denyAccessUnlessGranted('@projects.edit');
        if (null !== $position->getBuildProject()) {
            $this->denyAccessUnlessGranted('read', $position->getBuildProject());
        }

        return $this->render('production/project_position/build.html.twig', [
            'position' => $position,
        ]);
    }

    #[Route(path: '/project-positions/{id}/delete', name: 'production_project_position_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function projectPositionDelete(ProjectPosition $position, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@projects.edit');
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
        $this->denyAccessUnlessGranted('@projects.edit');
        $template = $position->getSystemTemplate();
        if (!$template instanceof SystemTemplate) {
            throw $this->createNotFoundException('This position has no system template.');
        }

        $builder = $this->createFormBuilder(null, [
            'translation_domain' => 'production',
            'method' => 'POST',
        ]);
        foreach ($template->getSlots() as $slot) {
            $assignment = $position->getAssignmentForSlot($slot);
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
                'required' => $slot->isRequired(),
                'placeholder' => $slot->isRequired() ? false : 'production.project_position.slot.empty',
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
                'data' => $assignment?->getQuantity() ?? $partAssignment?->getQuantity() ?? max(1, $slot->getMinQuantity()),
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
                $assignment = $position->getAssignmentForSlot($slot);
                $partAssignment = $position->getPartAssignmentForSlot($slot);
                if (!$selected instanceof SystemTemplate && !$selected instanceof Project && !$selected instanceof Part) {
                    if ($slot->isRequired()) {
                        $form->get('content_'.$slot->getId())->addError(new FormError($this->translator->trans('production.project_position.slot.required', domain: 'production')));
                    } elseif (null !== $assignment && (0 !== $assignment->getBuildInstances()->count() || 0 !== $assignment->getChildren()->count())) {
                        $form->get('content_'.$slot->getId())->addError(new FormError($this->translator->trans('production.project_position.slot.in_use', domain: 'production')));
                    }
                    continue;
                }
                if ($quantity < $slot->getMinQuantity() || $quantity > $slot->getMaxQuantity()) {
                    $form->get('quantity_'.$slot->getId())->addError(new FormError($this->translator->trans('production.project_position.slot.quantity_invalid', domain: 'production')));
                }
                $samePositionContent = ($selected instanceof SystemTemplate && $assignment?->getSystemTemplate() === $selected)
                    || ($selected instanceof Project && $assignment?->getTemplateProject() === $selected);
                if (null !== $assignment && !$samePositionContent
                    && (0 !== $assignment->getBuildInstances()->count() || 0 !== $assignment->getChildren()->count())) {
                    $form->get('content_'.$slot->getId())->addError(new FormError($this->translator->trans('production.project_position.slot.in_use', domain: 'production')));
                }
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            foreach ($template->getSlots() as $slot) {
                $selected = $form->get('content_'.$slot->getId())->getData();
                $assignment = $position->getAssignmentForSlot($slot);
                $partAssignment = $position->getPartAssignmentForSlot($slot);
                if (!$selected instanceof SystemTemplate && !$selected instanceof Project && !$selected instanceof Part) {
                    if (null !== $assignment) {
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
                    if (null === $assignment) {
                        $assignment = (new ProjectPosition())
                            ->setCustomerProject($position->getCustomerProject())
                            ->setParent($position)
                            ->setSourceSlot($slot);
                        $entityManager->persist($assignment);
                    }
                    $assignment
                        ->setName($slot->getName())
                        ->setPosition($slot->getPosition())
                        ->setQuantity($quantity);
                    if ($selected instanceof SystemTemplate) {
                        $assignment->setSystemTemplate($selected);
                    } else {
                        $assignment->setTemplateProject($selected);
                    }
                    continue;
                }
                if (null !== $assignment) {
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
        $this->denyAccessUnlessGranted('@projects.edit');

        return $this->handleAccessoryForm(
            (new ProjectAccessory())->setCustomerProject($project),
            $request,
            $entityManager,
        );
    }

    #[Route(path: '/accessories/{id}/edit', name: 'production_accessory_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function accessoryEdit(ProjectAccessory $accessory, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@projects.edit');

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
        $this->denyAccessUnlessGranted('@parts_stock.withdraw');
        if (CustomerProjectStatus::InProduction !== $project->getStatus()) {
            $this->addFlash('warning', 'production.material_plan.production_only');

            return $this->redirectToRoute('production_customer_project_show', ['id' => $project->getId()]);
        }

        $planItem = null;
        foreach ($materialPlanner->createPlan($project)['items'] as $item) {
            if ($item['part'] === $part) {
                $planItem = $item;
                break;
            }
        }
        if (null === $planItem) {
            throw $this->createNotFoundException('This part is not required by the project.');
        }

        $lots = array_values(array_filter(
            $part->getPartLots()->toArray(),
            static fn(PartLot $lot): bool => !$lot->isInstockUnknown() && $lot->getAmount() > 0,
        ));
        $serialRequired = $project->requiresSerialTracking($part);
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
            ->add('quantity', NumberType::class, [
                'label' => 'production.material_plan.allocate_quantity',
                'data' => min(1.0, $planItem['remaining']),
                'scale' => 4,
                'html5' => true,
                'attr' => ['min' => 0.0001, 'max' => $serialRequired ? 1 : $planItem['remaining']],
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
            $quantity = (float) $form->get('quantity')->getData();
            $serialNumber = trim((string) $form->get('serialNumber')->getData());
            if (!$lot instanceof PartLot || $lot->getPart() !== $part) {
                $form->get('lot')->addError(new FormError($this->translator->trans('production.material_plan.invalid_lot', domain: 'production')));
            } elseif ($quantity <= 0 || $quantity > $lot->getAmount() || $quantity > $planItem['remaining'] || ($serialRequired && 1.0 !== $quantity)) {
                $form->get('quantity')->addError(new FormError($this->translator->trans('production.material_plan.invalid_quantity', domain: 'production')));
            }
            if ($serialRequired && '' === $serialNumber && (!$lot instanceof PartLot || !$lot->getUserBarcode())) {
                $form->get('serialNumber')->addError(new FormError($this->translator->trans('production.material_plan.serial_number_required', domain: 'production')));
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var PartLot $lot */
            $lot = $form->get('lot')->getData();
            $quantity = (float) $form->get('quantity')->getData();
            $serialNumber = trim((string) $form->get('serialNumber')->getData()) ?: $lot->getUserBarcode();
            $withdrawHelper->withdraw($lot, $quantity, sprintf('Projektbestand %s', $project->getProjectNumber()));
            $allocation = (new ProjectMaterialAllocation())
                ->setCustomerProject($project)
                ->setPart($part)
                ->setSourcePartLot($lot)
                ->setQuantity($quantity)
                ->setSerialNumber($serialNumber)
                ->setAllocatedBy($this->getUser() instanceof User ? $this->getUser() : null);
            $entityManager->persist($allocation);
            $this->historyRecorder->record($project, 'material_allocated', sprintf('%s × %s', $part->getName(), $quantity));
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

    #[Route(path: '/build-instances', name: 'production_build_instance_index', methods: ['GET'])]
    public function buildInstanceIndex(BuildInstanceRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('@projects.read');

        return $this->render('production/build_instance/index.html.twig', [
            'build_instances' => $repository->findBy([], ['serialNumber' => 'ASC']),
        ]);
    }

    #[Route(path: '/build-instances/new', name: 'production_build_instance_new', methods: ['GET', 'POST'])]
    public function buildInstanceNew(
        Request $request,
        EntityManagerInterface $entityManager,
        ProjectPositionRepository $positions,
    ): Response
    {
        $this->denyAccessUnlessGranted('@projects.edit');

        $buildInstance = new BuildInstance();
        $positionId = $request->query->getInt('position');
        if ($positionId > 0) {
            $buildInstance->setProjectPosition($positions->find($positionId));
        }

        return $this->handleBuildInstanceForm($buildInstance, $request, $entityManager);
    }

    #[Route(path: '/build-instances/{id}', name: 'production_build_instance_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function buildInstanceShow(BuildInstance $buildInstance): Response
    {
        $this->denyAccessUnlessGranted('@projects.read');
        if (null !== $buildInstance->getBuildProject()) {
            $this->denyAccessUnlessGranted('read', $buildInstance->getBuildProject());
        }

        return $this->render('production/build_instance/show.html.twig', ['build_instance' => $buildInstance]);
    }

    #[Route(path: '/build-instances/{id}/edit', name: 'production_build_instance_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function buildInstanceEdit(BuildInstance $buildInstance, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@projects.edit');

        return $this->handleBuildInstanceForm($buildInstance, $request, $entityManager);
    }

    #[Route(path: '/build-instances/{id}/unassign', name: 'production_build_instance_unassign', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function buildInstanceUnassign(BuildInstance $buildInstance, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@projects.edit');
        if (!$this->isCsrfTokenValid('unassign_build_instance_'.$buildInstance->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $project = $buildInstance->getCustomerProject();
        if (!$project instanceof CustomerProject) {
            $this->addFlash('info', $this->translator->trans('production.build_instance.already_unassigned', domain: 'production'));

            return $this->redirectToRoute('production_build_instance_show', ['id' => $buildInstance->getId()]);
        }

        $buildInstance->setProjectPosition(null);
        $this->historyRecorder->record(
            $project,
            'build_unassigned',
            $buildInstance->getSerialNumber(),
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
        ]);
    }

    private function handleCustomerProjectForm(CustomerProject $project, Request $request, EntityManagerInterface $entityManager): Response
    {
        $isNew = null === $project->getId();
        $form = $this->createForm(CustomerProjectType::class, $project);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($project);
            $this->historyRecorder->record($project, $isNew ? 'project_created' : 'project_updated');
            $entityManager->flush();
            $this->addFlash('success', 'production.flash.saved');

            return $this->redirectToRoute('production_customer_project_show', ['id' => $project->getId()]);
        }

        return $this->render('production/form.html.twig', [
            'form' => $form,
            'title' => null === $project->getId() ? 'production.customer_project.new' : 'production.customer_project.edit',
            'cancel_route' => 'production_customer_project_index',
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
            if (null !== $position->getBuildProject()) {
                $this->denyAccessUnlessGranted('read', $position->getBuildProject());
            }
            $entityManager->persist($position);
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
            if (null !== $template->getBaseProject()) {
                $this->denyAccessUnlessGranted('read', $template->getBaseProject());
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

    private function handleSystemTemplateSlotForm(SystemTemplateSlot $slot, Request $request, EntityManagerInterface $entityManager): Response
    {
        $isNew = null === $slot->getId();
        $form = $this->createForm(SystemTemplateSlotType::class, $slot);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            foreach ($slot->getAllowedProjects() as $project) {
                $this->denyAccessUnlessGranted('read', $project);
            }
            $entityManager->persist($slot);
            $entityManager->flush();
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

    private function handleBuildInstanceForm(BuildInstance $buildInstance, Request $request, EntityManagerInterface $entityManager): Response
    {
        $isNew = null === $buildInstance->getId();
        $previousProject = $buildInstance->getCustomerProject();
        $form = $this->createForm(BuildInstanceType::class, $buildInstance);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if (null !== $buildInstance->getBuildProject()) {
                $this->denyAccessUnlessGranted('read', $buildInstance->getBuildProject());
            }
            $entityManager->persist($buildInstance);
            $project = $buildInstance->getCustomerProject();
            if ($project instanceof CustomerProject) {
                $this->historyRecorder->record(
                    $project,
                    $isNew ? 'build_created' : 'build_updated',
                    $buildInstance->getSerialNumber(),
                    $buildInstance,
                );
            }
            if ($previousProject instanceof CustomerProject && $previousProject !== $project) {
                $this->historyRecorder->record(
                    $previousProject,
                    'build_unassigned',
                    $buildInstance->getSerialNumber(),
                    $buildInstance,
                );
            }
            $entityManager->flush();
            $this->addFlash('success', 'production.flash.saved');

            return $this->redirectToRoute('production_build_instance_show', ['id' => $buildInstance->getId()]);
        }

        return $this->render('production/form.html.twig', [
            'form' => $form,
            'title' => null === $buildInstance->getId() ? 'production.build_instance.new' : 'production.build_instance.edit',
            'cancel_route' => 'production_build_instance_index',
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

    private function removePositionTree(ProjectPosition $position, EntityManagerInterface $entityManager): void
    {
        foreach ($position->getChildren()->toArray() as $child) {
            $this->removePositionTree($child, $entityManager);
        }

        $entityManager->remove($position);
    }
}
