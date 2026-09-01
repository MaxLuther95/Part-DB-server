<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Entity\Parts\Part;
use App\Entity\Production\CustomerProject;
use App\Entity\Production\ProjectAccessory;
use App\Entity\Production\ProjectPosition;
use App\Entity\Production\SystemTemplate;
use App\Entity\Production\SystemTemplateSlot;
use App\Entity\ProjectSystem\Project;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates the unambiguous required contents of a newly added system position.
 * Optional slots and required slots with more than one allowed choice remain a
 * deliberate user decision on the configuration page.
 */
final readonly class ProjectPositionInitializer
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function initializeRequiredDefaults(ProjectPosition $position): void
    {
        $this->initialize($position, []);
    }

    /**
     * Applies newly added unambiguous required slots to all existing positions
     * which use the changed template. Existing assignments are never replaced.
     */
    public function synchronizeTemplatePositions(SystemTemplate $template): void
    {
        /** @var list<ProjectPosition> $positions */
        $positions = $this->entityManager->getRepository(ProjectPosition::class)->findBy([
            'systemTemplate' => $template,
        ]);

        foreach ($positions as $position) {
            $this->initializeRequiredDefaults($position);
        }

        $this->entityManager->flush();
    }

    /** @param array<int, true> $templatePath */
    private function initialize(ProjectPosition $position, array $templatePath): void
    {
        $template = $position->getSystemTemplate();
        $project = $position->getCustomerProject();
        if (!$template instanceof SystemTemplate || !$project instanceof CustomerProject) {
            return;
        }

        $templateObjectId = spl_object_id($template);
        if (isset($templatePath[$templateObjectId])) {
            return;
        }
        $templatePath[$templateObjectId] = true;

        foreach ($template->getSlots() as $slot) {
            if (!$slot->isRequired()
                || [] !== $position->getAssignmentsForSlot($slot)
                || null !== $position->getPartAssignmentForSlot($slot)) {
                continue;
            }

            $content = $this->getSingleAllowedContent($slot);
            if (!$content instanceof SystemTemplate && !$content instanceof Project && !$content instanceof Part) {
                continue;
            }

            if ($content instanceof Part) {
                $partAssignment = (new ProjectAccessory())
                    ->setProjectPosition($position)
                    ->setSourceSlot($slot)
                    ->setPart($content)
                    ->setQuantity($slot->getMinQuantity())
                    ->setSerialTracking($slot->isSerialTracking());
                $this->entityManager->persist($partAssignment);
                continue;
            }

            for ($index = 0; $index < $slot->getMinQuantity(); ++$index) {
                $assignment = (new ProjectPosition())
                    ->setCustomerProject($project)
                    ->setSourceSlot($slot)
                    ->setName($slot->getMinQuantity() > 1 ? sprintf('%s %d', $slot->getName(), $index + 1) : $slot->getName())
                    ->setPosition($slot->getPosition())
                    ->setQuantity(1);

                if ($content instanceof SystemTemplate) {
                    $assignment->setSystemTemplate($content);
                } else {
                    $assignment->setTemplateProject($content);
                }

                $position->addChild($assignment);
                $this->entityManager->persist($assignment);

                if ($content instanceof SystemTemplate) {
                    $this->initialize($assignment, $templatePath);
                }
            }
        }
    }

    private function getSingleAllowedContent(SystemTemplateSlot $slot): SystemTemplate|Project|Part|null
    {
        $choices = [
            ...$slot->getAllowedSystemTemplates()->toArray(),
            ...$slot->getAllowedProjects()->toArray(),
            ...$slot->getAllowedParts()->toArray(),
        ];

        return 1 === count($choices) ? $choices[0] : null;
    }
}
