<?php

declare(strict_types=1);

namespace App\Controller\Production;

use App\DataTables\PartsDataTable;
use App\DataTables\ProductionProjectDataTable;
use App\Entity\Production\ProductionProject;
use App\Entity\Production\ProductionProjectStatus;
use App\Form\Production\ProductionProjectType;
use App\Repository\Production\CustomerRepository;
use App\Settings\BehaviorSettings\TableSettings;
use Doctrine\ORM\EntityManagerInterface;
use Omines\DataTablesBundle\DataTableFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(path: '/production/projects')]
final class ProductionProjectController extends AbstractController
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    #[Route(path: '', name: 'production_project_index', methods: ['GET', 'POST'])]
    public function index(Request $request, DataTableFactory $dataTableFactory, TableSettings $tableSettings, CustomerRepository $customerRepository): Response
    {
        $this->denyAccessUnlessGranted('@production_projects.read');
        $statusFilter = $request->query->getString('status');
        $status = ProductionProjectStatus::tryFrom($statusFilter);
        $activeOnly = 'all' !== $statusFilter && null === $status;
        $customerValue = $request->query->getString('customer');
        $customerId = ctype_digit($customerValue) ? (int) $customerValue : null;
        $yearValue = $request->query->getString('year');
        $year = ctype_digit($yearValue) ? (int) $yearValue : null;
        $year = $year >= 2000 && $year <= ((int) date('Y') + 1) ? $year : null;
        $searchQuery = trim($request->query->getString('q'));
        $searchQuery = '' !== $searchQuery ? mb_substr($searchQuery, 0, 200) : null;
        $table = $dataTableFactory->createFromType(ProductionProjectDataTable::class, [
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

        return $this->render('production/project/index.html.twig', [
            'datatable' => $table,
            'selected_status' => $status?->value,
            'selected_status_filter' => $activeOnly ? 'active' : (null === $status ? 'all' : $status->value),
            'selected_customer' => $customerId > 0 ? $customerId : null,
            'selected_year' => $year,
            'search_query' => $searchQuery,
            'filters_open' => null !== $searchQuery || $customerId > 0 || null !== $year || !$activeOnly,
            'project_statuses' => ProductionProjectStatus::cases(),
            'customers' => $customerRepository->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route(path: '/new', name: 'production_project_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_projects.create');

        return $this->handleForm(new ProductionProject(), $request, $entityManager);
    }

    #[Route(path: '/{id}', name: 'production_project_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(ProductionProject $project): Response
    {
        $this->denyAccessUnlessGranted('@production_projects.read');

        return $this->render('production/project/show.html.twig', ['project' => $project]);
    }

    #[Route(path: '/{id}/edit', name: 'production_project_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(ProductionProject $project, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_projects.edit');

        return $this->handleForm($project, $request, $entityManager);
    }

    #[Route(path: '/{id}/delete', name: 'production_project_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(ProductionProject $project, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_projects.delete');
        if (!$this->isCsrfTokenValid('delete_production_project_'.$project->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        if (!$project->getOrders()->isEmpty()) {
            $this->addFlash('error', $this->translator->trans('production.project.delete_has_orders', domain: 'production'));

            return $this->redirectToRoute('production_project_show', ['id' => $project->getId()]);
        }

        $entityManager->remove($project);
        $entityManager->flush();
        $this->addFlash('success', $this->translator->trans('production.project.deleted', domain: 'production'));

        return $this->redirectToRoute('production_project_index');
    }

    private function handleForm(ProductionProject $project, Request $request, EntityManagerInterface $entityManager): Response
    {
        $isNew = null === $project->getId();
        $form = $this->createForm(ProductionProjectType::class, $project);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($project);
            $entityManager->flush();
            $this->addFlash('success', 'production.flash.saved');

            return $this->redirectToRoute('production_project_show', ['id' => $project->getId()]);
        }

        return $this->render('production/form.html.twig', [
            'form' => $form,
            'title' => $isNew ? 'production.project.new' : 'production.project.edit',
            'cancel_route' => $isNew ? 'production_project_index' : 'production_project_show',
            'cancel_route_params' => $isNew ? [] : ['id' => $project->getId()],
            'delete_route' => $isNew ? null : 'production_project_delete',
            'delete_permission' => '@production_projects.delete',
            'delete_route_params' => ['id' => $project->getId()],
            'delete_token_id' => 'delete_production_project_'.$project->getId(),
            'delete_confirm' => $this->translator->trans('production.project.delete_confirm', [
                '%number%' => $project->getProjectNumber(),
            ], 'production'),
        ]);
    }
}
