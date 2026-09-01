<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\BuildStatus;
use App\Entity\Production\ProjectPosition;
use App\Entity\Production\SystemTemplateSlot;

/**
 * Validates and performs one direct assignment between a configured project
 * position and one previously built device. Child positions are deliberately
 * handled separately; assigning a parent never assigns a complete tree.
 */
final readonly class BuildConfigurationCompatibility
{
    public function isCompatible(BuildInstance $instance, ProjectPosition $position): bool
    {
        if (null !== $instance->getProjectPosition()
            || null !== $instance->getCustomerProject()
            || null !== $instance->getParent()
            || !$instance->getChildren()->isEmpty()
            || !$position->getBuildInstances()->isEmpty()
            || !in_array($instance->getStatus(), [BuildStatus::InProgress, BuildStatus::Paused, BuildStatus::Completed], true)
            || !$this->contentMatches($instance, $position)) {
            return false;
        }

        $plannedParent = $position->getParent();
        if (!$plannedParent instanceof ProjectPosition) {
            return true;
        }

        $builtParent = $plannedParent->getBuildInstances()->first();
        $slot = $position->getSourceSlot();
        if (!$slot instanceof SystemTemplateSlot) {
            return false;
        }

        $siblings = $plannedParent->getAssignmentsForSlot($slot);
        $slotIndex = array_search($position, $siblings, true);
        if (false === $slotIndex) {
            return false;
        }

        if (!$builtParent instanceof BuildInstance) {
            return true;
        }

        foreach ($builtParent->getChildren() as $child) {
            if ($child->getInstalledSlot() === $slot && $child->getInstalledSlotIndex() === $slotIndex) {
                return false;
            }
        }

        return true;
    }

    public function assign(BuildInstance $instance, ProjectPosition $position): bool
    {
        if (!$this->isCompatible($instance, $position)) {
            return false;
        }

        $instance->setProjectPosition($position);
        if (!$this->synchronizePhysicalRelations($instance, $position)) {
            $instance->setProjectPosition(null);

            return false;
        }

        return true;
    }

    public function synchronizePhysicalRelations(BuildInstance $instance, ProjectPosition $position): bool
    {
        if (!$this->attachToConfiguredParent($instance, $position)) {
            return false;
        }

        foreach ($position->getChildren() as $childPosition) {
            $childInstance = $childPosition->getBuildInstances()->first();
            if ($childInstance instanceof BuildInstance && !$this->attachToConfiguredParent($childInstance, $childPosition)) {
                return false;
            }
        }

        return true;
    }

    public function unassign(BuildInstance $instance): bool
    {
        if (null === $instance->getParent()) {
            foreach ($instance->getChildren() as $child) {
                if (null !== $child->getProjectPosition()) {
                    return false;
                }
            }
        }

        $wasInstalled = null !== $instance->getParent();
        $instance->setProjectPosition(null);
        if ($wasInstalled) {
            $instance->setParent(null)->setStatus(BuildStatus::Completed);
        }

        return true;
    }

    private function contentMatches(BuildInstance $instance, ProjectPosition $position): bool
    {
        if (null !== $position->getSystemTemplate()) {
            return $instance->getSystemTemplate() === $position->getSystemTemplate();
        }
        if (null !== $position->getTemplateProject()) {
            return $instance->getTemplateProject() === $position->getTemplateProject();
        }

        return $instance->getContentReferenceType() === $position->getContentReferenceType()
            && $instance->getContentReferenceId() === $position->getContentReferenceId();
    }

    private function attachToConfiguredParent(BuildInstance $instance, ProjectPosition $position): bool
    {
        $plannedParent = $position->getParent();
        if (!$plannedParent instanceof ProjectPosition) {
            return true;
        }

        $slot = $position->getSourceSlot();
        if (!$slot instanceof SystemTemplateSlot) {
            return false;
        }
        $siblings = $plannedParent->getAssignmentsForSlot($slot);
        $slotIndex = array_search($position, $siblings, true);
        if (false === $slotIndex) {
            return false;
        }

        $builtParent = $plannedParent->getBuildInstances()->first();
        if (!$builtParent instanceof BuildInstance) {
            return true;
        }
        if (null !== $instance->getParent() && $instance->getParent() !== $builtParent) {
            return false;
        }
        foreach ($builtParent->getChildren() as $child) {
            if ($child !== $instance && $child->getInstalledSlot() === $slot && $child->getInstalledSlotIndex() === $slotIndex) {
                return false;
            }
        }

        $instance
            ->setParent($builtParent)
            ->setInstalledSlot($slot)
            ->setInstalledSlotIndex($slotIndex)
            ->setStatus(BuildStatus::Installed);

        return true;
    }

}
