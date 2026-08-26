<?php

declare(strict_types=1);

namespace App\Controller\Production;

use App\Entity\Parts\Part;
use App\Entity\Parts\StorageLocation;
use App\Entity\Production\CustomerProject;
use App\Entity\Production\CustomerProjectStatus;
use App\Entity\UserSystem\User;
use App\Repository\Production\CustomerProjectRepository;
use App\Repository\Production\ProjectMaterialReservationRepository;
use App\Services\Production\ProductionMaterialPlanner;
use App\Services\Production\ProductionReservationManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/production')]
final class MaterialReservationController extends AbstractController
{
    #[Route(path: '/customer-projects/{id}/material-reservations', name: 'production_material_reservation', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function reservation(
        CustomerProject $project,
        Request $request,
        EntityManagerInterface $entityManager,
        ProductionMaterialPlanner $planner,
        ProductionReservationManager $manager,
    ): Response {
        $this->denyAccessUnlessGranted('@projects.edit');
        if (!in_array($project->getStatus(), [CustomerProjectStatus::Commissioned, CustomerProjectStatus::InProduction], true)) {
            $this->addFlash('warning', 'Reservierungen sind nur für beauftragte oder in Produktion befindliche Kundenprojekte vorgesehen.');
            return $this->redirectToRoute('production_customer_project_show', ['id' => $project->getId()]);
        }
        $locations = $entityManager->getRepository(StorageLocation::class)->findAll();
        usort($locations, static fn(StorageLocation $left, StorageLocation $right): int => strcasecmp($left->getFullPath(), $right->getFullPath()));
        $selectedSite = $manager->getPreferredSite($project);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('refresh_material_reservation_'.$project->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }
            $selectedSite = $entityManager->find(StorageLocation::class, $request->request->getInt('site_id'));
            $user = $this->getUser();
            if (!$selectedSite instanceof StorageLocation || !$user instanceof User) {
                $this->addFlash('error', 'Bitte einen gültigen Fertigungsstandort auswählen.');
            } else {
                $result = $manager->refresh($project, $selectedSite, $user);
                if ($result['missing'] > 0) {
                    $this->addFlash('warning', sprintf('%s Teile wurden reserviert; %s Teile fehlen weiterhin und erscheinen unter „Benötigte Teile“.', $result['reserved'], $result['missing']));
                } else {
                    $this->addFlash('success', sprintf('Die Reservierung wurde aktualisiert. %s Teile sind für dieses Kundenprojekt vorgemerkt.', $result['reserved']));
                }
                return $this->redirectToRoute('production_customer_project_show', ['id' => $project->getId()]);
            }
        }

        return $this->render('production/material_reservation/edit.html.twig', [
            'project' => $project,
            'material_plan' => $planner->createPlan($project),
            'locations' => $locations,
            'selected_site' => $selectedSite,
        ]);
    }

    #[Route(path: '/customer-projects/{id}/material-reservations/release', name: 'production_material_reservation_release', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function release(CustomerProject $project, Request $request, EntityManagerInterface $entityManager, ProductionReservationManager $manager): Response
    {
        $this->denyAccessUnlessGranted('@projects.edit');
        if (!$this->isCsrfTokenValid('release_material_reservation_'.$project->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $released = $manager->release($project);
        $entityManager->flush();
        $this->addFlash('info', sprintf('%s reservierte Teile wurden wieder freigegeben.', $released));

        return $this->redirectToRoute('production_customer_project_show', ['id' => $project->getId()]);
    }

    #[Route(path: '/required-parts', name: 'production_required_parts', methods: ['GET'])]
    public function requiredParts(
        Request $request,
        CustomerProjectRepository $projects,
        ProjectMaterialReservationRepository $reservations,
        ProductionMaterialPlanner $planner,
    ): Response {
        $this->denyAccessUnlessGranted('@projects.read');
        $committed = $projects->findBy(['status' => [CustomerProjectStatus::Commissioned, CustomerProjectStatus::InProduction]], ['projectNumber' => 'ASC']);
        $rows = [];
        foreach ($committed as $project) {
            foreach ($planner->createPlan($project)['items'] as $item) {
                /** @var Part $part */
                $part = $item['part'];
                $partId = $part->getId();
                if (null === $partId) { continue; }
                $rows[$partId] ??= ['part' => $part, 'required' => 0, 'allocated' => 0, 'consumed' => 0, 'reserved' => 0, 'projects' => []];
                $rows[$partId]['required'] += $item['required'];
                $rows[$partId]['allocated'] += $item['allocated'];
                $rows[$partId]['consumed'] += $item['consumed'];
                $rows[$partId]['reserved'] += $item['reserved'];
                $rows[$partId]['projects'][] = ['project' => $project, 'required' => $item['required'], 'reserved' => $item['reserved'], 'missing' => $item['missing']];
            }
        }
        $search = mb_strtolower(trim($request->query->getString('q')));
        $missingOnly = '0' !== $request->query->getString('missing', '1');
        foreach ($rows as $partId => &$row) {
            $physical = (int) floor($row['part']->getAmountSum());
            $row['reserved_total'] = $reservations->quantityForPart($row['part']);
            $row['free'] = max(0, $physical - $row['reserved_total']);
            $row['to_order'] = max(0, $row['required'] - $row['allocated'] - $row['consumed'] - $physical);
            if (($missingOnly && 0 === $row['to_order']) || ('' !== $search && !str_contains(mb_strtolower($row['part']->getName()), $search))) {
                unset($rows[$partId]);
            }
        }
        unset($row);
        uasort($rows, static fn(array $left, array $right): int => strcasecmp($left['part']->getName(), $right['part']->getName()));

        return $this->render('production/required_parts.html.twig', ['rows' => array_values($rows), 'missing_only' => $missingOnly, 'search' => $search]);
    }
}
