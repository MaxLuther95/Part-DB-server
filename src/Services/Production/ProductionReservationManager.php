<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Entity\Parts\Part;
use App\Entity\Parts\PartLot;
use App\Entity\Parts\StorageLocation;
use App\Entity\Production\CustomerProject;
use App\Entity\Production\CustomerProjectStatus;
use App\Entity\Production\ProjectMaterialReservation;
use App\Entity\UserSystem\User;
use App\Repository\Production\ProjectMaterialReservationRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ProductionReservationManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProjectMaterialReservationRepository $repository,
        private ProductionMaterialPlanner $materialPlanner,
        private ProductionHistoryRecorder $historyRecorder,
    ) {
    }

    /** @return array{reserved: int, missing: int, lines: int} */
    public function refresh(CustomerProject $project, StorageLocation $site, User $user): array
    {
        if (!in_array($project->getStatus(), [CustomerProjectStatus::Commissioned, CustomerProjectStatus::InProduction], true)) {
            throw new \DomainException('Material kann nur für beauftragte oder in Produktion befindliche Kundenprojekte reserviert werden.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($project, $site, $user): array {
            foreach ($project->getMaterialReservations()->toArray() as $reservation) {
                $this->entityManager->remove($reservation);
                $project->getMaterialReservations()->removeElement($reservation);
            }
            $reservedTotal = 0;
            $missingTotal = 0;
            $lines = 0;
            foreach ($this->materialPlanner->createPlan($project)['items'] as $item) {
                $part = $item['part'];
                $remaining = $item['remaining'];
                if (!$part instanceof Part || $remaining < 1) { continue; }
                foreach ($part->getPartLots() as $lot) {
                    if ($remaining < 1) { break; }
                    if ($lot->isInstockUnknown() || $lot->getAmount() <= 0 || !$this->lotBelongsToSite($lot, $site)) { continue; }
                    $available = max(0, (int) floor($lot->getAmount()) - $this->repository->quantityForLot($lot, $project));
                    if ($available < 1) { continue; }
                    $quantity = min($remaining, $available);
                    $reservation = (new ProjectMaterialReservation())
                        ->setCustomerProject($project)
                        ->setPart($part)
                        ->setSourcePartLot($lot)
                        ->setSite($site)
                        ->setQuantity($quantity)
                        ->setReservedBy($user);
                    $this->entityManager->persist($reservation);
                    $remaining -= $quantity;
                    $reservedTotal += $quantity;
                    ++$lines;
                }
                $missingTotal += $remaining;
            }
            $this->historyRecorder->record($project, 'material_reservation_updated', sprintf('%s reserviert, %s offen', $reservedTotal, $missingTotal));
            $this->entityManager->flush();

            return ['reserved' => $reservedTotal, 'missing' => $missingTotal, 'lines' => $lines];
        });
    }

    public function release(CustomerProject $project): int
    {
        $quantity = 0;
        foreach ($project->getMaterialReservations()->toArray() as $reservation) {
            $quantity += $reservation->getQuantity();
            $this->entityManager->remove($reservation);
            $project->getMaterialReservations()->removeElement($reservation);
        }
        if ($quantity > 0) {
            $this->historyRecorder->record($project, 'material_reservation_released', sprintf('%s freigegeben', $quantity));
        }

        return $quantity;
    }

    public function consumeExact(ProjectMaterialReservation $reservation, int $quantity): void
    {
        if ($quantity < 1 || $quantity > $reservation->getQuantity()) {
            throw new \InvalidArgumentException('Invalid reservation consumption quantity.');
        }
        if ($quantity === $reservation->getQuantity()) {
            $this->entityManager->remove($reservation);
            $reservation->getCustomerProject()?->getMaterialReservations()->removeElement($reservation);
        } else {
            $reservation->setQuantity($reservation->getQuantity() - $quantity);
        }
    }

    public function consumeForProvision(CustomerProject $project, Part $part, ?PartLot $preferredLot, int $quantity): int
    {
        $remaining = $quantity;
        $reservations = array_values(array_filter(
            $project->getMaterialReservations()->toArray(),
            static fn(ProjectMaterialReservation $reservation): bool => $reservation->getPart() === $part,
        ));
        usort($reservations, static fn(ProjectMaterialReservation $left, ProjectMaterialReservation $right): int => ($right->getSourcePartLot() === $preferredLot) <=> ($left->getSourcePartLot() === $preferredLot));
        foreach ($reservations as $reservation) {
            if ($remaining < 1) { break; }
            $take = min($remaining, $reservation->getQuantity());
            $this->consumeExact($reservation, $take);
            $remaining -= $take;
        }

        return $quantity - $remaining;
    }

    public function getPreferredSite(CustomerProject $project): ?StorageLocation
    {
        foreach ($project->getMaterialReservations() as $reservation) {
            if ($reservation->getSite() instanceof StorageLocation) { return $reservation->getSite(); }
        }

        return null;
    }

    public function availableToProject(PartLot $lot, CustomerProject $project): int
    {
        return max(0, (int) floor($lot->getAmount()) - $this->repository->quantityForLot($lot, $project));
    }

    public function lotBelongsToSite(PartLot $lot, StorageLocation $site): bool
    {
        $location = $lot->getStorageLocation();
        while ($location instanceof StorageLocation) {
            if ($location->getId() === $site->getId()) { return true; }
            $location = $location->getParent();
        }

        return false;
    }
}
