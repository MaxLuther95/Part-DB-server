<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Production\BuildInstance;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, BuildInstance> */
final class BuildInstanceVoter extends Voter
{
    public function __construct(private readonly AccessDecisionManagerInterface $decisions)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return 'read' === $attribute && $subject instanceof BuildInstance;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if (!$this->decisions->decide($token, ['@production_build_instances.read'])) {
            return false;
        }
        // Detail pages and datasheets include installed components. Fail closed
        // for the entire tree rather than exposing a restricted child indirectly.
        $pending = [$subject];
        $seen = new \SplObjectStorage();
        while ([] !== $pending) {
            $instance = array_pop($pending);
            if ($seen->contains($instance)) {
                continue;
            }
            $seen->attach($instance);
            if (null !== $instance->getSystemTemplate()
                && !$this->decisions->decide($token, ['@production_system_templates.read'])) {
                return false;
            }
            foreach ($instance->getBuildProjects() as $project) {
                if (!$this->decisions->decide($token, ['read'], $project)) {
                    return false;
                }
            }
            foreach ($instance->getChildren() as $child) {
                $pending[] = $child;
            }
        }

        return true;
    }
}
