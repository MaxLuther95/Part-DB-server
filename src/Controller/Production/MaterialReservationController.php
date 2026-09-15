<?php

declare(strict_types=1);

namespace App\Controller\Production;

use App\Entity\Parts\Part;
use App\Entity\Parts\PartLot;
use App\Entity\Parts\StorageLocation;
use App\Entity\Production\CustomerProject;
use App\Entity\Production\CustomerProjectStatus;
use App\Entity\Production\ProjectMaterialReservation;
use App\Entity\UserSystem\User;
use App\Form\Production\RequiredPartsFilterType;
use App\Repository\Production\CustomerProjectRepository;
use App\Services\Parts\PartLotWithdrawAddHelper;
use App\Services\Production\ProductionHistoryRecorder;
use App\Services\Production\ProductionMaterialPlanner;
use App\Services\Production\ProductionReservationManager;
use App\Services\Production\RequiredPartsPlanner;
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
        $this->denyAccessUnlessGranted('@production_material.reserve');
        if (!in_array($project->getStatus(), [CustomerProjectStatus::Commissioned, CustomerProjectStatus::InProduction], true)) {
            $this->addFlash('warning', 'Reservierungen sind nur für beauftragte oder in Produktion befindliche Aufträge vorgesehen.');
            return $this->redirectToRoute('production_customer_project_show', ['id' => $project->getId()]);
        }
        /** @var list<StorageLocation> $locations */
        $locations = [];
        foreach ($entityManager->getRepository(StorageLocation::class)->findAll() as $location) {
            if ($location instanceof StorageLocation && null === $location->getParent()) {
                $locations[] = $location;
            }
        }
        usort($locations, static fn(StorageLocation $left, StorageLocation $right): int => strcasecmp($left->getFullPath(), $right->getFullPath()));
        $selectedSite = $manager->getPreferredSite($project);
        if ($request->isMethod('GET') && $request->query->getInt('site_id') > 0) {
            $requestedSite = $entityManager->find(StorageLocation::class, $request->query->getInt('site_id'));
            if ($requestedSite instanceof StorageLocation && null === $requestedSite->getParent()) {
                $selectedSite = $requestedSite;
            }
        }
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('refresh_material_reservation_'.$project->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }
            $selectedSite = $entityManager->find(StorageLocation::class, $request->request->getInt('site_id'));
            $user = $this->getUser();
            if (!$selectedSite instanceof StorageLocation || null !== $selectedSite->getParent() || !$user instanceof User) {
                $this->addFlash('error', 'Bitte einen gültigen Fertigungsstandort auswählen.');
            } else {
                $result = $manager->refresh($project, $selectedSite, $user, $request->request->all('remote_lots'));
                if ($result['missing'] > 0) {
                    $this->addFlash('warning', sprintf('%s Teile wurden reserviert; %s Teile sind weiterhin weder am Fertigungsstandort noch an einem ausgewählten anderen Standort gesichert.', $result['reserved'], $result['missing']));
                } elseif ($result['transfer_pending'] > 0) {
                    $this->addFlash('info', sprintf('%s Teile wurden reserviert. Davon warten %s Teile an einem anderen Standort auf den Transfer.', $result['reserved'], $result['transfer_pending']));
                } else {
                    $this->addFlash('success', sprintf('Die Reservierung wurde aktualisiert. %s Teile sind für diesen Auftrag vorgemerkt.', $result['reserved']));
                }
                return $this->redirectToRoute('production_customer_project_show', ['id' => $project->getId()]);
            }
        }

        return $this->render('production/material_reservation/edit.html.twig', [
            'project' => $project,
            'material_plan' => $planner->createPlan($project, $selectedSite),
            'locations' => $locations,
            'selected_site' => $selectedSite,
        ]);
    }

    #[Route(path: '/material-reservations/{id}/receive', name: 'production_material_reservation_receive', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function receive(
        ProjectMaterialReservation $reservation,
        Request $request,
        EntityManagerInterface $entityManager,
        ProductionReservationManager $manager,
        PartLotWithdrawAddHelper $withdrawHelper,
        ProductionHistoryRecorder $historyRecorder,
    ): Response {
        $this->denyAccessUnlessGranted('@production_material.provide');
        $this->denyAccessUnlessGranted('@parts_stock.move');
        $project = $reservation->getCustomerProject();
        $site = $reservation->getSite();
        $sourceLot = $reservation->getSourcePartLot();
        $part = $reservation->getPart();
        if (!$project instanceof CustomerProject || !$site instanceof StorageLocation || !$sourceLot instanceof PartLot || !$part instanceof Part) {
            throw $this->createNotFoundException('Die Transferreservierung ist nicht mehr vollständig verknüpft.');
        }
        if ($manager->lotBelongsToSite($sourceLot, $site)) {
            $this->addFlash('info', 'Dieses reservierte Lagerlos befindet sich bereits am Fertigungsstandort.');

            return $this->redirectToRoute('production_material_reservation', ['id' => $project->getId(), 'site_id' => $site->getId()]);
        }

        /** @var list<StorageLocation> $locations */
        $locations = [];
        foreach ($entityManager->getRepository(StorageLocation::class)->findAll() as $location) {
            if ($location instanceof StorageLocation
                && !$location->isNotSelectable()
                && !$location->isFull()
                && $manager->locationBelongsToSite($location, $site)) {
                $locations[] = $location;
            }
        }
        usort($locations, static fn(StorageLocation $left, StorageLocation $right): int => strcasecmp($left->getFullPath(), $right->getFullPath()));

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('receive_material_reservation_'.$reservation->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }
            $targetLocation = $entityManager->find(StorageLocation::class, $request->request->getInt('target_location_id'));
            if (!$targetLocation instanceof StorageLocation
                || $targetLocation->isNotSelectable()
                || $targetLocation->isFull()
                || !$manager->locationBelongsToSite($targetLocation, $site)) {
                $this->addFlash('error', 'Bitte einen gültigen Ziel-Lagerort innerhalb des Fertigungsstandorts auswählen.');
            } elseif ($manager->availableToProject($sourceLot, $project) < $reservation->getQuantity()) {
                $this->addFlash('error', 'Der reservierte Bestand am Quellstandort ist nicht mehr vollständig vorhanden. Bitte die Reservierung neu abgleichen.');
            } else {
                $targetLot = (new PartLot())
                    ->setPart($part)
                    ->setStorageLocation($targetLocation)
                    ->setDescription(sprintf('Transfer für %s', $project->getProjectNumber()))
                    ->setComment(sprintf('Übernommen aus %s', $sourceLot->getStorageLocation()?->getFullPath() ?? 'unbekanntem Standort'))
                    ->setAmount(0);
                $entityManager->persist($targetLot);
                $withdrawHelper->move($sourceLot, $targetLot, $reservation->getQuantity(), sprintf('Standorttransfer für Auftrag %s', $project->getProjectNumber()));
                $reservation->setSourcePartLot($targetLot);
                $historyRecorder->record($project, 'material_transfer_received', sprintf('%s × %s nach %s übernommen', $part->getName(), $reservation->getQuantity(), $targetLocation->getFullPath()));
                $entityManager->flush();
                $this->addFlash('success', sprintf('%s × %s wurde am Fertigungsstandort eingebucht. Die Reservierung bleibt bestehen.', $part->getName(), $reservation->getQuantity()));

                return $this->redirectToRoute('production_material_reservation', ['id' => $project->getId(), 'site_id' => $site->getId()]);
            }
        }

        return $this->render('production/material_reservation/receive.html.twig', [
            'reservation' => $reservation,
            'project' => $project,
            'site' => $site,
            'source_lot' => $sourceLot,
            'locations' => $locations,
        ]);
    }

    #[Route(path: '/customer-projects/{id}/material-reservations/release', name: 'production_material_reservation_release', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function release(CustomerProject $project, Request $request, EntityManagerInterface $entityManager, ProductionReservationManager $manager): Response
    {
        $this->denyAccessUnlessGranted('@production_material.reserve');
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
        RequiredPartsPlanner $planner,
    ): Response {
        $this->denyAccessUnlessGranted('@production_material.read');
        if ($request->query->has('site') && '' !== $request->query->getString('site')) {
            $this->denyAccessUnlessGranted('@storelocations.read');
        }
        if ($request->query->has('supplier') && '' !== $request->query->getString('supplier')) {
            $this->denyAccessUnlessGranted('@suppliers.read');
        }
        $form = $this->createForm(RequiredPartsFilterType::class, ['missing' => '1'], [
            'allow_sites' => $this->isGranted('@storelocations.read'),
            'allow_suppliers' => $this->isGranted('@suppliers.read'),
            'action' => $this->generateUrl('production_required_parts'),
        ]);
        $form->handleRequest($request);
        $rows = [];
        if (! $form->isSubmitted() || $form->isValid()) {
            $filters = $form->getData();
            $committed = $projects->findBy(['status' => [CustomerProjectStatus::Commissioned, CustomerProjectStatus::InProduction]], ['projectNumber' => 'ASC']);
            $rows = $planner->createRows($committed, $filters['site'] ?? null, $filters['supplier'] ?? null, '0' !== ($filters['missing'] ?? '1'));
        }

        return $this->render('production/required_parts.html.twig', ['rows' => $rows, 'filter_form' => $form]);
    }
}
