<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\ProtocolAnswer;
use App\Entity\Production\ProtocolRevisionStatus;
use App\Entity\Production\ProtocolRun;
use App\Entity\Production\ProtocolRunSectionRow;
use App\Entity\Production\ProtocolTemplate;
use App\Entity\Production\ProtocolTemplateField;
use App\Entity\Production\ProtocolTemplateRevision;
use App\Entity\Production\ProtocolTemplateSection;
use App\Entity\UserSystem\User;
use App\Repository\Production\ProtocolRunRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ProtocolManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProtocolRunRepository $runRepository,
    ) {
    }

    public function deleteTemplate(ProtocolTemplate $template): void
    {
        $run = $this->runRepository->createQueryBuilder('run')
            ->select('run.id')
            ->innerJoin('run.revision', 'revision')
            ->andWhere('revision.template = :template')
            ->setParameter('template', $template)
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
        if (null !== $run) {
            throw new \DomainException('production.protocol.template.delete_in_use');
        }

        try {
            $this->entityManager->remove($template);
            $this->entityManager->flush();
        } catch (ForeignKeyConstraintViolationException $exception) {
            // A concurrently created run must also prevent deletion; Doctrine rolls back the flush.
            throw new \DomainException('production.protocol.template.delete_in_use', previous: $exception);
        }
    }

    public function createInitialDraft(ProtocolTemplate $template): ProtocolTemplateRevision
    {
        if (0 !== $template->getRevisions()->count()) {
            throw new \LogicException('The protocol template already has a revision.');
        }

        $revision = (new ProtocolTemplateRevision())->setRevisionNumber(1);
        $template->addRevision($revision);

        return $revision;
    }

    public function getOrCreateDraft(ProtocolTemplate $template): ProtocolTemplateRevision
    {
        if (null !== $draft = $template->getDraftRevision()) {
            return $draft;
        }

        $draft = (new ProtocolTemplateRevision())->setRevisionNumber($template->getNextRevisionNumber());
        $template->addRevision($draft);
        $published = $template->getPublishedRevision();
        if (null === $published) {
            return $draft;
        }

        foreach ($published->getSections() as $sourceSection) {
            $section = (new ProtocolTemplateSection($sourceSection->getStableKey()))
                ->setName($sourceSection->getName())
                ->setDescription($sourceSection->getDescription())
                ->setPosition($sourceSection->getPosition());
            $draft->addSection($section);
            foreach ($sourceSection->getFields() as $sourceField) {
                $section->addField(
                    (new ProtocolTemplateField($sourceField->getStableKey()))
                        ->setLabel($sourceField->getLabel())
                        ->setType($sourceField->getType())
                        ->setUnit($sourceField->getUnit())
                        ->setHelpText($sourceField->getHelpText())
                        ->setPosition($sourceField->getPosition())
                        ->setLayoutColumns($sourceField->getLayoutColumns())
                        ->setStartNewRow($sourceField->isStartNewRow())
                        ->setRequired($sourceField->isRequired())
                        ->setOptions($sourceField->getOptions())
                );
            }
        }

        return $draft;
    }

    public function addSection(ProtocolTemplateRevision $revision): ProtocolTemplateSection
    {
        $position = 0;
        foreach ($revision->getSections() as $section) {
            $position = max($position, $section->getPosition() + 1);
        }
        $section = (new ProtocolTemplateSection())->setPosition($position);
        $revision->addSection($section);

        return $section;
    }

    public function addField(ProtocolTemplateSection $section): ProtocolTemplateField
    {
        $position = 0;
        foreach ($section->getFields() as $field) {
            $position = max($position, $field->getPosition() + 1);
        }
        $field = (new ProtocolTemplateField())->setPosition($position);
        $section->addField($field);

        return $field;
    }

    public function moveSection(ProtocolTemplateSection $section, int $direction): void
    {
        $revision = $section->getRevision() ?? throw new \LogicException('The section has no revision.');
        $revision->assertEditable();
        $sections = $revision->getSections()
            ->toArray();
        usort($sections, static fn (ProtocolTemplateSection $a, ProtocolTemplateSection $b): int => $a->getPosition() <=> $b->getPosition());
        $this->swapPosition($sections, $section, $direction);
    }

    public function moveField(ProtocolTemplateField $field, int $direction): void
    {
        $section = $field->getSection() ?? throw new \LogicException('The field has no section.');
        $section->getRevision()?->assertEditable();
        $fields = $section->getFields()
            ->toArray();
        usort($fields, static fn (ProtocolTemplateField $a, ProtocolTemplateField $b): int => $a->getPosition() <=> $b->getPosition());
        $this->swapPosition($fields, $field, $direction);
    }

    /**
     * @return list<string>
     */
    public function validateRevisionForPublishing(ProtocolTemplateRevision $revision): array
    {
        if (ProtocolRevisionStatus::Draft !== $revision->getStatus()) {
            return ['Nur ein Entwurf kann veröffentlicht werden.'];
        }
        if (0 === $revision->getSections()->count()) {
            return ['Die Vorlage benötigt mindestens einen Abschnitt.'];
        }

        $errors = [];
        foreach ($revision->getSections() as $section) {
            if (0 === $section->getFields()->count()) {
                $errors[] = sprintf('Der Abschnitt „%s“ enthält noch kein Feld.', $section->getName());
            }
            foreach ($section->getFields() as $field) {
                if (\App\Entity\Production\ProtocolFieldType::Choice === $field->getType() && [] === ($field->getOptions() ?? [])) {
                    $errors[] = sprintf('Das Auswahlfeld „%s“ benötigt mindestens eine Option.', $field->getLabel());
                }
            }
        }

        return $errors;
    }

    public function publish(ProtocolTemplateRevision $revision, ?User $user): void
    {
        $errors = $this->validateRevisionForPublishing($revision);
        if ([] !== $errors) {
            throw new \DomainException(implode("\n", $errors));
        }

        // Existing runs stay pinned to their revision, including unfinished runs.
        // Retirement only removes the revision from selection for new runs.
        foreach ($revision->getTemplate()?->getRevisions() ?? [] as $previous) {
            if ($previous !== $revision && ProtocolRevisionStatus::Published === $previous->getStatus()) {
                $previous->retire();
            }
        }
        $revision->publish($user);
    }

    public function createRun(BuildInstance $buildInstance, ProtocolTemplateRevision $revision, ?User $user): ProtocolRun
    {
        if (ProtocolRevisionStatus::Published !== $revision->getStatus() || ! $revision->getTemplate()?->isActive()) {
            throw new \InvalidArgumentException('Only an active, published protocol template can be used.');
        }

        if (! $revision->getTemplate()->appliesTo($buildInstance) || $revision->getTemplate()->getPublishedRevision() !== $revision) {
            throw new \InvalidArgumentException('Only the current published revision assigned to this build type can be used.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($buildInstance, $revision, $user): ProtocolRun {
            // SQLite serializes writers at database level and does not support SELECT ... FOR UPDATE.
            // MariaDB/MySQL and PostgreSQL use a row lock to keep numbering race-free.
            if (! $this->entityManager->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
                $this->entityManager->lock($buildInstance, LockMode::PESSIMISTIC_WRITE);
            }
            $run = (new ProtocolRun())
                ->setBuildInstance($buildInstance)
                ->setRevision($revision)
                ->setRunNumber($this->runRepository->getNextRunNumber($buildInstance))
                ->setStartedBy($user);

            foreach ($revision->getSections() as $section) {
                $this->appendRow($run, $section);
            }
            $this->entityManager->persist($run);
            $this->entityManager->flush();

            return $run;
        });
    }

    /**
     * @return list<string>
     */
    public function validateForCompletion(ProtocolRun $run): array
    {
        $errors = [];
        if (null === $run->getProtocolDate()) {
            $errors[] = 'Bitte das Laufzetteldatum eintragen.';
        }
        foreach ($run->getRows() as $row) {
            foreach ($row->getAnswers() as $answer) {
                $field = $answer->getField();
                if ($field?->isRequired() && (null === $answer->getValue() || (is_string($answer->getValue()) && '' === trim($answer->getValue())))) {
                    $errors[] = sprintf('Das Feld „%s / %s“ ist nicht ausgefüllt.', $row->getSection()?->getName(), $field->getLabel());
                }
            }
        }

        return $errors;
    }

    private function appendRow(ProtocolRun $run, ProtocolTemplateSection $section): ProtocolRunSectionRow
    {
        $row = (new ProtocolRunSectionRow())->setSection($section);
        $run->addRow($row);
        foreach ($section->getFields() as $field) {
            if ($field->isInputField()) {
                $row->addAnswer((new ProtocolAnswer())->setField($field));
            }
        }

        return $row;
    }

    /**
     * @param list<ProtocolTemplateSection|ProtocolTemplateField> $items
     */
    private function swapPosition(array $items, ProtocolTemplateSection|ProtocolTemplateField $item, int $direction): void
    {
        $index = array_search($item, $items, true);
        $targetIndex = false === $index ? -1 : $index + ($direction < 0 ? -1 : 1);
        if (false === $index || ! isset($items[$targetIndex])) {
            return;
        }
        $target = $items[$targetIndex];
        $itemPosition = $item->getPosition();
        $targetPosition = $target->getPosition();
        $temporaryPosition = min(array_map(static fn (ProtocolTemplateSection|ProtocolTemplateField $candidate): int => $candidate->getPosition(), $items)) - 1;
        $this->entityManager->wrapInTransaction(function () use ($item, $target, $itemPosition, $targetPosition, $temporaryPosition): void {
            // Vacate one unique position first; both flushes belong to one transaction so a failure
            // can never leave the persisted ordering in its temporary state.
            $item->setPosition($temporaryPosition);
            $this->entityManager->flush();
            $target->setPosition($itemPosition);
            $this->entityManager->flush();
            $item->setPosition($targetPosition);
            $this->entityManager->flush();
        });
    }
}
