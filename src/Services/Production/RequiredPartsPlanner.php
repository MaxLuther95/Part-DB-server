<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Entity\Parts\StorageLocation;
use App\Entity\Parts\Supplier;
use App\Entity\Production\CustomerProject;
use App\Repository\Production\ProjectMaterialReservationRepository;

final readonly class RequiredPartsPlanner
{
    public function __construct(
        private ProductionMaterialPlanner $planner,
        private ProductionReservationManager $reservationManager,
        private ProjectMaterialReservationRepository $reservations,
    ) {
    }

    /**
     * @param list<CustomerProject> $orders Committed orders, in display order.
     * @return list<array<string, mixed>>
     */
    public function createRows(array $orders, ?StorageLocation $site, ?Supplier $supplier, bool $missingOnly): array
    {
        $rows = [];
        foreach ($orders as $order) {
            if (null !== $site && ! $this->reservationManager->locationBelongsToSite($this->reservationManager->getPreferredSite($order), $site)) {
                continue;
            }
            foreach ($this->planner->createPlan($order, $site)['items'] as $item) {
                $part = $item['part'];
                $id = $part->getId();
                if (null === $id) {
                    continue;
                }
                $sources = [];
                foreach ($part->getOrderdetails(true) as $detail) {
                    $source = $detail->getSupplier();
                    if (null !== $source && (null === $supplier || $source->getId() === $supplier->getId())) {
                        $sources[$source->getId()] = $source;
                    }
                }
                if (null !== $supplier && [] === $sources) {
                    continue;
                }
                $rows[$id] ??= ['part' => $part, 'suppliers' => array_values($sources), 'required' => 0, 'allocated' => 0, 'consumed' => 0, 'reserved' => 0, 'projects' => []];
                foreach (['required', 'allocated', 'consumed', 'reserved'] as $quantity) {
                    $rows[$id][$quantity] += $item[$quantity];
                }
                $rows[$id]['projects'][$order->getId()] = [
                    'project' => $order, 'required' => $item['required'], 'reserved' => $item['reserved'],
                    'remaining' => $item['remaining'], 'covered' => 0,
                ];
            }
        }

        foreach ($rows as $id => &$row) {
            $byLot = [];
            foreach ($this->reservations->findBy(['part' => $row['part']], ['id' => 'ASC']) as $reservation) {
                $byLot[$reservation->getSourcePartLot()?->getId()][] = $reservation;
            }
            $row['free'] = 0;
            foreach ($row['part']->getPartLots() as $lot) {
                if ($lot->isInstockUnknown() || $lot->getAmount() <= 0 || (null !== $site && ! $this->reservationManager->lotBelongsToSite($lot, $site))) {
                    continue;
                }
                $physical = (int) floor($lot->getAmount());
                $allReserved = 0;
                $otherReserved = 0;
                foreach ($byLot[$lot->getId()] ?? [] as $reservation) {
                    $quantity = max(0, $reservation->getQuantity());
                    $allReserved += $quantity;
                    if (! isset($row['projects'][$reservation->getCustomerProject()?->getId()])) {
                        $otherReserved += $quantity;
                    }
                }
                // Count each lot once; reservations outside the filter remain unavailable.
                $row['free'] += max(0, $physical - $allReserved);
                $coverable = max(0, $physical - $otherReserved);
                foreach ($byLot[$lot->getId()] ?? [] as $reservation) {
                    $orderId = $reservation->getCustomerProject()?->getId();
                    if (isset($row['projects'][$orderId])) {
                        $covered = min($coverable, max(0, $reservation->getQuantity()));
                        $row['projects'][$orderId]['covered'] += $covered;
                        $coverable -= $covered;
                    }
                }
            }
            $uncovered = 0;
            foreach ($row['projects'] as $detail) {
                // One order's excess allocation/reservation cannot cover another order.
                $uncovered += max(0, $detail['remaining'] - $detail['covered']);
            }
            $row['to_order'] = max(0, $uncovered - $row['free']);
            if ($missingOnly && $row['to_order'] === 0) {
                unset($rows[$id]);
            }
        }
        unset($row);
        uasort($rows, static fn (array $a, array $b) => strcasecmp($a['part']->getName(), $b['part']->getName()));

        return array_values($rows);
    }
}
