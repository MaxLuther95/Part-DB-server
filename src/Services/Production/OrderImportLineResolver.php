<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Entity\Parts\Part;
use App\Entity\Production\{CustomerProject, CustomerProjectStatus, OrderImportLine, OrderImportLineDisposition, OrderPositionUnit, ProjectAccessory, ProjectPosition, SystemTemplate};
use App\Entity\ProjectSystem\Project;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class OrderImportLineResolver
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProjectPositionInitializer $initializer,
        private ProductionHistoryRecorder $history,
        private ManufacturingSnapshotFactory $snapshots,
    ) {
    }

    /** Called inside the import's existing transaction, before its final flush. */
    public function createAssignment(OrderImportLine $line, Part|SystemTemplate|Project $target): void
    {
        if (OrderImportLineDisposition::Assigned === $line->getDisposition()) {
            throw new \DomainException('Diese Position wurde bereits zugeordnet.');
        }
        $order = $line->getOrder();
        if (!$order instanceof CustomerProject) {
            throw new \DomainException('Die Position gehört zu keinem Auftrag.');
        }
        $expectedUnit = $target instanceof SystemTemplate ? $target->getOrderUnit() : OrderPositionUnit::Piece;
        if ($line->getUnit() !== $expectedUnit->value) {
            throw new \DomainException('Die gewählte Zuordnung benötigt die Einheit '.$expectedUnit->getLabel().'.');
        }
        if (!$target instanceof Part && $line->getQuantity() > 2000) {
            throw new \DomainException('Eine Zuordnung darf höchstens 2.000 Fertigungspositionen erzeugen.');
        }
        if ($target instanceof Part) {
            $this->entityManager->persist((new ProjectAccessory())
                ->setCustomerProject($order)->setPart($target)->setQuantity($line->getQuantity())
                ->setNote(sprintf('PDF-Position %d: %s', $line->getLineNumber(), $line->getDescription())));
        } else {
            $snapshot = $this->snapshots->capture($target);
            $this->entityManager->persist($snapshot);
            $positions = $order->getRootPositions();
            $number = [] === $positions ? 0 : max(array_map(static fn(ProjectPosition $position): int => $position->getPosition(), $positions)) + 1;
            for ($index = 1; $index <= $line->getQuantity(); ++$index) {
                $position = (new ProjectPosition())->setCustomerProject($order)->setPosition($number++)->setQuantity(1)
                    ->setName($line->getQuantity() > 1 ? sprintf('%s %d', $line->getDescription(), $index) : $line->getDescription());
                if ($target instanceof SystemTemplate) {
                    $position->setSystemTemplate($target);
                } else {
                    $position->setTemplateProject($target);
                }
                $position->setManufacturingSnapshot($snapshot, ManufacturingSnapshotFactory::key($target));
                $this->entityManager->persist($position);
                $this->initializer->initializeRequiredDefaults($position);
            }
        }
        $line->setDisposition(OrderImportLineDisposition::Assigned);
    }

    public function assign(OrderImportLine $line, Part|SystemTemplate|Project $target, OrderPositionUnit $unit, string $expected): void
    {
        $this->change($line, $expected, function () use ($line, $target, $unit): void {
            $line->setUnit($unit);
            $this->createAssignment($line, $target);
        }, 'import_position_assigned');
    }

    public function classify(OrderImportLine $line, OrderImportLineDisposition $disposition, string $expected): void
    {
        if (OrderImportLineDisposition::Assigned === $disposition) {
            throw new \InvalidArgumentException('Assignments require a concrete target.');
        }
        $this->change($line, $expected, static fn() => $line->setDisposition($disposition), 'import_position_classified');
    }

    private function change(OrderImportLine $line, string $expected, callable $change, string $event): void
    {
        $this->entityManager->wrapInTransaction(function () use ($line, $expected, $change, $event): void {
            $order = $line->getOrder();
            if (!$order instanceof CustomerProject) {
                throw new \DomainException('Der Auftrag ist nicht mehr vorhanden.');
            }
            $this->entityManager->refresh($order, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($line, LockMode::PESSIMISTIC_WRITE);
            if (in_array($order->getStatus(), [CustomerProjectStatus::Completed, CustomerProjectStatus::Delivered, CustomerProjectStatus::Cancelled], true)) {
                throw new \DomainException('Abgeschlossene oder stornierte Aufträge können hier nicht verändert werden.');
            }
            if ($line->getDisposition()->value !== $expected || OrderImportLineDisposition::Assigned === $line->getDisposition()) {
                throw new \DomainException('Diese Position wurde inzwischen verändert. Bitte den Auftrag neu öffnen.');
            }
            $change();
            $this->history->record($order, $event, sprintf('PDF-Position %d: %s (%s)', $line->getLineNumber(), $line->getDescription(), $line->getDisposition()->value));
        });
    }
}
