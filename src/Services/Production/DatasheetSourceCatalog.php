<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\ProtocolRun;
use App\Entity\Production\ProtocolRunStatus;
use App\Repository\Production\ProtocolTemplateRepository;

final readonly class DatasheetSourceCatalog
{
    public const AMBIGUOUS_VALUE = '[multiple completed protocols – selection required]';
    public const AMBIGUOUS_CHILD_POSITION = '[multiple installed items at this position]';
    public const INVALID_SELECTION = '[invalid protocol selection]';

    private const MAX_SELECTABLE_CHILD_POSITION = 12;

    public function __construct(
        private ProtocolTemplateRepository $protocolTemplateRepository,
    ) {
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function getRootChoices(): array
    {
        $choices = $this->baseRootChoices();
        $templates = $this->protocolTemplateRepository->findActiveWithPublishedRevision();
        $this->appendProtocolChoices($choices, $templates, false);

        for ($position = 1; $position <= self::MAX_SELECTABLE_CHILD_POSITION; ++$position) {
            $group = sprintf('Eingebaute Komponente · Position %d', $position);
            $prefix = sprintf('child_position.%d.', $position);
            $choices[$group] = $this->instanceChoices($prefix);
            foreach ($templates as $template) {
                $revision = $template->getPublishedRevision();
                if (null === $revision || null === $template->getId()) {
                    continue;
                }
                foreach ($revision->getSections() as $section) {
                    foreach ($section->getFields() as $field) {
                        if (! $field->isInputField()) {
                            continue;
                        }
                        $label = $template->getName().' · '.$section->getName().' · '.$field->getLabel();
                        $choices[$group][$label] = sprintf('%sprotocol.%d.%s', $prefix, $template->getId(), $field->getStableKey());
                    }
                }
            }
        }

        return $choices;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function getChildChoices(): array
    {
        $choices = [
            'Eingebaute Komponente' => $this->instanceChoices('child.'),
            'Kunde der eingebauten Komponente' => $this->customerChoices('child.'),
            'Auftrag der eingebauten Komponente' => $this->orderChoices('child.'),
            'Projekt der eingebauten Komponente' => $this->projectChoices('child.'),
        ];
        $this->appendProtocolChoices($choices, $this->protocolTemplateRepository->findActiveWithPublishedRevision(), true);

        return $choices;
    }

    public function describe(?string $sourcePath): string
    {
        if (null === $sourcePath || '' === $sourcePath) {
            return 'No source selected';
        }
        foreach ([$this->getRootChoices(), $this->getChildChoices()] as $groups) {
            foreach ($groups as $group => $choices) {
                $label = array_search($sourcePath, $choices, true);
                if (false !== $label) {
                    return $group.' · '.$label;
                }
            }
        }

        return 'Unknown source';
    }

    public function isKnownRootSource(?string $sourcePath): bool
    {
        return $this->contains($this->getRootChoices(), $sourcePath);
    }

    public function isKnownChildSource(?string $sourcePath): bool
    {
        return $this->contains($this->getChildChoices(), $sourcePath);
    }

    public function resolve(?string $sourcePath, ?BuildInstance $instance, ?int $selectedRunId = null): string
    {
        if (null === $sourcePath || null === $instance) {
            return null === $sourcePath ? '' : '{'.$this->describe($sourcePath).'}';
        }

        $target = $this->targetForSource($sourcePath, $instance);
        if ($target['ambiguous']) {
            return self::AMBIGUOUS_CHILD_POSITION;
        }
        if (null === $target['instance']) {
            return '';
        }

        return match ($target['path']) {
            'instance.serial_number', 'child.serial_number' => $target['instance']->getDisplayIdentifier(),
            'serial_number' => $target['instance']->getDisplayIdentifier(),
            'instance.product_name', 'child.product_name', 'product_name' => (string) $target['instance']->getContentName(),
            'instance.product_description', 'child.product_description', 'product_description' => (string) ($target['instance']->getSystemTemplate()?->getDescription() ?? $target['instance']->getBuildProject()?->getDescription()),
            'instance.location', 'child.location', 'location' => (string) $target['instance']->getLocation(),
            'instance.notes', 'child.notes', 'notes' => (string) $target['instance']->getNotes(),
            'instance.status', 'child.status', 'status' => $target['instance']->getStatus()->value,
            'instance.completion_date', 'child.completion_date', 'completion_date' => $target['instance']->getCompletedAt()?->format('Y-m-d') ?? '',
            'instance.project_position_name', 'child.project_position_name', 'project_position_name' => (string) $target['instance']->getProjectPosition()?->getName(),
            'instance.project_position_number', 'child.project_position_number', 'project_position_number' => null === $target['instance']->getProjectPosition() ? '' : (string) $target['instance']->getProjectPosition()->getPosition(),
            'instance.project_position_notes', 'child.project_position_notes', 'project_position_notes' => (string) $target['instance']->getProjectPosition()?->getNotes(),
            'instance.customer_name', 'child.customer_name', 'customer_name' => (string) $target['instance']->getCustomerProject()?->getCustomer()?->getName(),
            'instance.customer_number', 'child.customer_number', 'customer_number' => (string) $target['instance']->getCustomerProject()?->getCustomer()?->getCustomerNumber(),
            'instance.customer_description', 'child.customer_description', 'customer_description' => (string) $target['instance']->getCustomerProject()?->getCustomer()?->getDescription(),
            'instance.order_number', 'child.order_number', 'order_number' => (string) $target['instance']->getCustomerProject()?->getOrderNumber(),
            'instance.order_name', 'child.order_name', 'order_name' => (string) $target['instance']->getCustomerProject()?->getName(),
            'instance.order_date', 'child.order_date', 'order_date' => $target['instance']->getCustomerProject()?->getOrderDate()?->format('Y-m-d') ?? '',
            'instance.planned_delivery_date', 'child.planned_delivery_date', 'planned_delivery_date' => $target['instance']->getCustomerProject()?->getPlannedDeliveryDate()?->format('Y-m-d') ?? '',
            'instance.order_description', 'child.order_description', 'order_description' => (string) $target['instance']->getCustomerProject()?->getDescription(),
            'instance.order_notes', 'child.order_notes', 'order_notes' => (string) $target['instance']->getCustomerProject()?->getNotes(),
            'instance.production_site', 'child.production_site', 'production_site' => (string) $target['instance']->getCustomerProject()?->getProductionSite(),
            'instance.project_number', 'child.project_number', 'project_number' => (string) $target['instance']->getCustomerProject()?->getProductionProject()?->getProjectNumber(),
            'instance.project_name', 'child.project_name', 'project_name' => (string) $target['instance']->getCustomerProject()?->getProductionProject()?->getName(),
            'instance.project_description', 'child.project_description', 'project_description' => (string) $target['instance']->getCustomerProject()?->getProductionProject()?->getDescription(),
            'generated.date' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'child.slot_name', 'slot_name' => (string) $target['instance']->getInstalledSlot()?->getName(),
            'child.slot_position', 'slot_position' => null === $target['instance']->getInstalledSlotIndex() ? '' : (string) ($target['instance']->getInstalledSlotIndex() + 1),
            default => $this->resolveProtocolValue($target['path'], $target['instance'], $selectedRunId),
        };
    }

    public function selectionKey(?string $sourcePath, BuildInstance $instance): ?string
    {
        if (null === $sourcePath) {
            return null;
        }
        $target = $this->targetForSource($sourcePath, $instance);
        $templateId = $this->protocolTemplateId($target['path']);

        return null === $templateId || null === $target['instance']?->getId() ? null : $target['instance']->getId().'_'.$templateId;
    }

    /**
     * @return list<ProtocolRun>
     */
    public function completedRuns(?string $sourcePath, BuildInstance $instance): array
    {
        if (null === $sourcePath) {
            return [];
        }
        $target = $this->targetForSource($sourcePath, $instance);
        $templateId = $this->protocolTemplateId($target['path']);
        if (null === $templateId || null === $target['instance']) {
            return [];
        }

        return array_values(array_filter(
            $target['instance']->getProtocolRuns()
                ->toArray(),
            static fn (ProtocolRun $run): bool => ProtocolRunStatus::Completed === $run->getStatus()
                && $run->getRevision()?->getTemplate()?->getId() === $templateId
        ));
    }

    public function resolvedRunId(?string $sourcePath, BuildInstance $instance, ?int $selectedRunId = null): ?int
    {
        $runs = $this->completedRuns($sourcePath, $instance);
        if (null !== $selectedRunId) {
            foreach ($runs as $run) {
                if ($run->getId() === $selectedRunId) {
                    return $selectedRunId;
                }
            }

            return null;
        }

        return 1 === count($runs) ? $runs[0]->getId() : null;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function baseRootChoices(): array
    {
        return [
            'Aktuelles Produkt / gebaute Instanz' => $this->instanceChoices('instance.'),
            'Kunde' => $this->customerChoices('instance.'),
            'Auftrag' => $this->orderChoices('instance.'),
            'Projekt' => $this->projectChoices('instance.'),
            'Dokument' => [
                'Erstellungsdatum des Datenblatts' => 'generated.date',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function instanceChoices(string $prefix): array
    {
        return [
            'Seriennummer / ID' => $prefix.'serial_number',
            'Produkt / Typ' => $prefix.'product_name',
            'Produktbeschreibung' => $prefix.'product_description',
            'Status' => $prefix.'status',
            'Standort' => $prefix.'location',
            'Notizen zur gebauten Instanz' => $prefix.'notes',
            'Fertigstellungsdatum' => $prefix.'completion_date',
            'Auftragsposition: Bezeichnung' => $prefix.'project_position_name',
            'Auftragsposition: Nummer' => $prefix.'project_position_number',
            'Auftragsposition: Notizen' => $prefix.'project_position_notes',
            'Einbauplatz: Bezeichnung' => $prefix.'slot_name',
            'Einbauplatz: Physische Position' => $prefix.'slot_position',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function customerChoices(string $prefix): array
    {
        return [
            'Kundenname' => $prefix.'customer_name',
            'Kundennummer' => $prefix.'customer_number',
            'Kundenbeschreibung' => $prefix.'customer_description',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function orderChoices(string $prefix): array
    {
        return [
            'Auftragsnummer' => $prefix.'order_number',
            'Auftragsname' => $prefix.'order_name',
            'Auftragsdatum' => $prefix.'order_date',
            'Geplantes Lieferdatum' => $prefix.'planned_delivery_date',
            'Auftragsbeschreibung' => $prefix.'order_description',
            'Interne Auftragsnotizen' => $prefix.'order_notes',
            'Fertigungsstandort' => $prefix.'production_site',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function projectChoices(string $prefix): array
    {
        return [
            'Projektnummer' => $prefix.'project_number',
            'Projektname' => $prefix.'project_name',
            'Projektbeschreibung' => $prefix.'project_description',
        ];
    }

    /**
     * @param array<string, array<string, string>>                      $choices
     * @param array<array-key, \App\Entity\Production\ProtocolTemplate> $templates
     */
    private function appendProtocolChoices(array &$choices, array $templates, bool $child): void
    {
        $prefix = $child ? 'child.' : '';
        foreach ($templates as $template) {
            $revision = $template->getPublishedRevision();
            if (null === $revision || null === $template->getId()) {
                continue;
            }
            $group = ($child ? 'Laufzettel der eingebauten Komponente: ' : 'Laufzettel des aktuellen Produkts: ').$template->getName();
            foreach ($revision->getSections() as $section) {
                foreach ($section->getFields() as $field) {
                    if (! $field->isInputField()) {
                        continue;
                    }
                    $choices[$group][$section->getName().' · '.$field->getLabel()] = sprintf('%sprotocol.%d.%s', $prefix, $template->getId(), $field->getStableKey());
                }
            }
        }
    }

    /**
     * @param array<string, array<string, string>> $groups
     */
    private function contains(array $groups, ?string $sourcePath): bool
    {
        foreach ($groups as $choices) {
            if (in_array($sourcePath, $choices, true)) {
                return true;
            }
        }

        return false;
    }

    private function resolveProtocolValue(string $sourcePath, BuildInstance $instance, ?int $selectedRunId): string
    {
        if (1 !== preg_match('/^(?:child\.)?protocol\.(\d+)\.([0-9a-f-]{36})$/D', $sourcePath, $matches)) {
            return '';
        }
        $fieldKey = $matches[2];
        $runs = $this->completedRuns($sourcePath, $instance);
        if (0 === count($runs)) {
            return '';
        }
        if (null !== $selectedRunId) {
            $selected = array_values(array_filter($runs, static fn (ProtocolRun $run): bool => $run->getId() === $selectedRunId));
            if (1 !== count($selected)) {
                return self::INVALID_SELECTION;
            }
            $runs = $selected;
        } elseif (1 < count($runs)) {
            return self::AMBIGUOUS_VALUE;
        }
        foreach ($runs[0]->getRows() as $row) {
            foreach ($row->getAnswers() as $answer) {
                if ($answer->getField()?->getStableKey() !== $fieldKey) {
                    continue;
                }
                $value = $answer->getValue();
                if ($value instanceof \DateTimeInterface) {
                    return $value->format('Y-m-d H:i');
                }
                if (is_bool($value)) {
                    return $value ? 'Yes' : 'No';
                }

                return (string) $value;
            }
        }

        return '';
    }

    private function protocolTemplateId(?string $sourcePath): ?int
    {
        if (null === $sourcePath || 1 !== preg_match('/^(?:(?:child|child_position\.\d+)\.)?protocol\.(\d+)\.[0-9a-f-]{36}$/D', $sourcePath, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * @return array{path: string, instance: ?BuildInstance, ambiguous: bool}
     */
    private function targetForSource(string $sourcePath, BuildInstance $instance): array
    {
        if (1 !== preg_match('/^child_position\.(\d+)\.(.+)$/D', $sourcePath, $matches)) {
            return [
                'path' => $sourcePath,
                'instance' => $instance,
                'ambiguous' => false,
            ];
        }

        $index = (int) $matches[1] - 1;
        $children = array_values(array_filter(
            $instance->getChildren()
                ->toArray(),
            static fn (BuildInstance $child): bool => $child->getInstalledSlotIndex() === $index
        ));

        return [
            'path' => $matches[2],
            'instance' => 1 === count($children) ? $children[0] : null,
            'ambiguous' => 1 < count($children),
        ];
    }
}
