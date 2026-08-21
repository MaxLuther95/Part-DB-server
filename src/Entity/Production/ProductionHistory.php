<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\UserSystem\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'production_history')]
class ProductionHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CustomerProject::class, inversedBy: 'history')]
    #[ORM\JoinColumn(name: 'customer_project_id', nullable: false, onDelete: 'CASCADE')]
    private CustomerProject $customerProject;

    #[ORM\ManyToOne(targetEntity: BuildInstance::class)]
    #[ORM\JoinColumn(name: 'build_instance_id', nullable: true, onDelete: 'SET NULL')]
    private ?BuildInstance $buildInstance;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $actor;

    #[ORM\Column(name: 'event_type', type: Types::STRING, length: 64)]
    private string $eventType;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $description;

    #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    public function __construct(
        CustomerProject $customerProject,
        string $eventType,
        string $description = '',
        ?BuildInstance $buildInstance = null,
        ?User $actor = null,
    ) {
        $this->customerProject = $customerProject;
        $this->eventType = $eventType;
        $this->description = $description;
        $this->buildInstance = $buildInstance;
        $this->actor = $actor;
        $this->occurredAt = new \DateTimeImmutable('now');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomerProject(): CustomerProject
    {
        return $this->customerProject;
    }

    public function getBuildInstance(): ?BuildInstance
    {
        return $this->buildInstance;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
