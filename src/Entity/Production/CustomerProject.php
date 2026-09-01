<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Repository\Production\CustomerProjectRepository;
use App\Entity\UserSystem\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\Parts\Part;
use App\Entity\Parts\StorageLocation;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Doctrine\ORM\Event\PreUpdateEventArgs;

#[ORM\Entity(repositoryClass: CustomerProjectRepository::class)]
#[ORM\Table(name: 'production_customer_projects')]
#[ORM\Index(name: 'IDX_PROD_ORDER_STATUS_DATE', columns: ['status', 'order_date'])]
#[ORM\Index(name: 'IDX_PROD_ORDER_CUSTOMER_DATE', columns: ['customer_id', 'order_date'])]
#[UniqueEntity(fields: ['projectNumber'], message: 'production.customer_project.number.unique')]
class CustomerProject extends AbstractProductionEntity
{
    #[ORM\Column(name: 'project_number', type: Types::STRING, length: 64, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    private string $projectNumber = '';

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[ORM\ManyToOne(targetEntity: ProductionProject::class, inversedBy: 'orders')]
    #[ORM\JoinColumn(name: 'production_project_id', nullable: false)]
    #[Assert\NotNull]
    private ?ProductionProject $productionProject = null;

    #[ORM\ManyToOne(targetEntity: Customer::class, inversedBy: 'projects')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Customer $customer = null;

    #[ORM\ManyToOne(targetEntity: StorageLocation::class)]
    #[ORM\JoinColumn(name: 'production_site_id', nullable: true, onDelete: 'SET NULL')]
    private ?StorageLocation $productionSite = null;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: CustomerProjectStatus::class)]
    private CustomerProjectStatus $status = CustomerProjectStatus::Planning;

    #[ORM\Column(type: Types::TEXT)]
    private string $description = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'order_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $orderDate = null;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'production_customer_project_users')]
    #[ORM\JoinColumn(name: 'customer_project_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $assignedUsers;

    /** @var Collection<int, BuildInstance> */
    #[ORM\OneToMany(mappedBy: 'customerProject', targetEntity: BuildInstance::class, fetch: 'EXTRA_LAZY')]
    #[ORM\OrderBy(['serialNumber' => 'ASC'])]
    private Collection $buildInstances;

    /** @var Collection<int, ProjectPosition> */
    #[ORM\OneToMany(mappedBy: 'customerProject', targetEntity: ProjectPosition::class)]
    #[ORM\OrderBy(['position' => 'ASC', 'name' => 'ASC'])]
    private Collection $positions;

    /** @var Collection<int, ProductionHistory> */
    #[ORM\OneToMany(mappedBy: 'customerProject', targetEntity: ProductionHistory::class)]
    #[ORM\OrderBy(['occurredAt' => 'DESC'])]
    private Collection $history;

    /** @var Collection<int, ProjectAccessory> */
    #[ORM\OneToMany(mappedBy: 'customerProject', targetEntity: ProjectAccessory::class)]
    #[ORM\OrderBy(['addedDate' => 'ASC'])]
    private Collection $accessories;

    /** @var Collection<int, ProjectMaterialAllocation> */
    #[ORM\OneToMany(mappedBy: 'customerProject', targetEntity: ProjectMaterialAllocation::class)]
    #[ORM\OrderBy(['addedDate' => 'ASC'])]
    private Collection $materialAllocations;

    /** @var Collection<int, ProjectMaterialReservation> */
    #[ORM\OneToMany(mappedBy: 'customerProject', targetEntity: ProjectMaterialReservation::class)]
    #[ORM\OrderBy(['addedDate' => 'ASC'])]
    private Collection $materialReservations;

    /** @var Collection<int, OrderAttachment> */
    #[ORM\OneToMany(mappedBy: 'order', targetEntity: OrderAttachment::class, cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['addedDate' => 'DESC'])]
    private Collection $attachments;

    /** @var Collection<int, OrderImportLine> */
    #[ORM\OneToMany(mappedBy: 'order', targetEntity: OrderImportLine::class, cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['lineNumber' => 'ASC'])]
    private Collection $importLines;

    public function __construct()
    {
        $this->assignedUsers = new ArrayCollection();
        $this->buildInstances = new ArrayCollection();
        $this->positions = new ArrayCollection();
        $this->history = new ArrayCollection();
        $this->accessories = new ArrayCollection();
        $this->materialAllocations = new ArrayCollection();
        $this->materialReservations = new ArrayCollection();
        $this->attachments = new ArrayCollection();
        $this->importLines = new ArrayCollection();
    }

    public function __toString(): string
    {
        return sprintf('%s – %s', $this->projectNumber, $this->name);
    }

    public function getProjectNumber(): string
    {
        return $this->projectNumber;
    }

    public function setProjectNumber(string $projectNumber): self
    {
        $this->projectNumber = trim($projectNumber);

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = trim($name);

        return $this;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function getProductionProject(): ?ProductionProject
    {
        return $this->productionProject;
    }

    public function setProductionProject(?ProductionProject $productionProject): self
    {
        if ($this->productionProject === $productionProject) {
            return $this;
        }
        $this->productionProject?->removeOrder($this);
        $this->productionProject = $productionProject;
        $productionProject?->addOrder($this);

        return $this;
    }

    public function getOrderNumber(): string
    {
        return $this->getProjectNumber();
    }

    public function setOrderNumber(string $orderNumber): self
    {
        return $this->setProjectNumber($orderNumber);
    }

    public function setCustomer(?Customer $customer): self
    {
        $this->customer = $customer;

        return $this;
    }

    public function getProductionSite(): ?StorageLocation
    {
        return $this->productionSite;
    }

    public function setProductionSite(?StorageLocation $productionSite): self
    {
        $this->productionSite = $productionSite;

        return $this;
    }

    public function getStatus(): CustomerProjectStatus
    {
        return $this->status;
    }

    public function setStatus(CustomerProjectStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $notes = null === $notes ? null : trim($notes);
        $this->notes = '' === $notes ? null : $notes;

        return $this;
    }

    public function getOrderDate(): ?\DateTimeImmutable
    {
        return $this->orderDate;
    }

    public function setOrderDate(?\DateTimeImmutable $orderDate): self
    {
        $this->orderDate = $orderDate;

        return $this;
    }

    /** @return Collection<int, OrderAttachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(OrderAttachment $attachment): self
    {
        if (!$this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
            $attachment->setOrder($this);
        }
        return $this;
    }

    public function removeAttachment(OrderAttachment $attachment): self
    {
        $this->attachments->removeElement($attachment);

        return $this;
    }

    /** @return Collection<int, OrderImportLine> */
    public function getImportLines(): Collection
    {
        return $this->importLines;
    }

    public function addImportLine(OrderImportLine $line): self
    {
        if (!$this->importLines->contains($line)) {
            $this->importLines->add($line);
            $line->setOrder($this);
        }
        return $this;
    }

    public function removeImportLine(OrderImportLine $line): self
    {
        $this->importLines->removeElement($line);

        return $this;
    }

    /** @return Collection<int, User> */
    public function getAssignedUsers(): Collection
    {
        return $this->assignedUsers;
    }

    public function addAssignedUser(User $user): self
    {
        if (!$this->assignedUsers->contains($user)) {
            $this->assignedUsers->add($user);
        }

        return $this;
    }

    public function removeAssignedUser(User $user): self
    {
        $this->assignedUsers->removeElement($user);

        return $this;
    }

    public function isAssignedTo(User $user): bool
    {
        return $this->assignedUsers->contains($user);
    }

    /** @return Collection<int, BuildInstance> */
    public function getBuildInstances(): Collection
    {
        return $this->buildInstances;
    }

    /** @return Collection<int, ProjectPosition> */
    public function getPositions(): Collection
    {
        return $this->positions;
    }

    /** @return Collection<int, ProductionHistory> */
    public function getHistory(): Collection
    {
        return $this->history;
    }

    /** @return array<int, ProjectPosition> */
    public function getRootPositions(): array
    {
        return array_values($this->positions->filter(
            static fn(ProjectPosition $position): bool => null === $position->getParent(),
        )->toArray());
    }

    /** @return Collection<int, ProjectAccessory> */
    public function getAccessories(): Collection
    {
        return $this->accessories;
    }

    /** @return Collection<int, ProjectMaterialAllocation> */
    public function getMaterialAllocations(): Collection
    {
        return $this->materialAllocations;
    }

    /** @return Collection<int, ProjectMaterialReservation> */
    public function getMaterialReservations(): Collection
    {
        return $this->materialReservations;
    }

    public function requiresSerialTracking(Part $part): bool
    {
        foreach ($this->accessories as $accessory) {
            if ($accessory->getPart() === $part && $accessory->isSerialTracking()) {
                return true;
            }
        }

        return false;
    }

    public function isReadyForCompletion(): bool
    {
        foreach ($this->positions as $position) {
            $instance = $position->getBuildInstances()->first();
            if (!$instance instanceof BuildInstance || null === $instance->getSerialNumber()) {
                return false;
            }
        }

        return true;
    }

    /** @return list<ProjectPosition> */
    public function getPositionsMissingSerialNumber(): array
    {
        return array_values($this->positions->filter(static function (ProjectPosition $position): bool {
            $instance = $position->getBuildInstances()->first();

            return !$instance instanceof BuildInstance || null === $instance->getSerialNumber();
        })->toArray());
    }

    #[Assert\Callback]
    public function validateCompletionStatus(ExecutionContextInterface $context): void
    {
        if (in_array($this->status, [CustomerProjectStatus::Completed, CustomerProjectStatus::Delivered], true)
            && !$this->isReadyForCompletion()) {
            $context->buildViolation('production.customer_project.completion_requires_serials')
                ->atPath('status')
                ->addViolation();
        }
    }

    #[ORM\PrePersist]
    public function preventInvalidInitialCompletion(): void
    {
        if (in_array($this->status, [CustomerProjectStatus::Completed, CustomerProjectStatus::Delivered], true)
            && !$this->isReadyForCompletion()) {
            throw new \DomainException('A project cannot be completed or delivered without one serialized device per project position.');
        }
    }

    #[ORM\PreUpdate]
    public function preventInvalidCompletionTransition(PreUpdateEventArgs $event): void
    {
        if ($event->hasChangedField('status')
            && in_array($this->status, [CustomerProjectStatus::Completed, CustomerProjectStatus::Delivered], true)
            && !$this->isReadyForCompletion()) {
            throw new \DomainException('A project cannot be completed or delivered without one serialized device per project position.');
        }
    }
}
