<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Repository\Production\CustomerProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\Parts\Part;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CustomerProjectRepository::class)]
#[ORM\Table(name: 'production_customer_projects')]
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

    #[ORM\ManyToOne(targetEntity: Customer::class, inversedBy: 'projects')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Customer $customer = null;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: CustomerProjectStatus::class)]
    private CustomerProjectStatus $status = CustomerProjectStatus::Planning;

    #[ORM\Column(type: Types::TEXT)]
    private string $description = '';

    /** @var Collection<int, BuildInstance> */
    #[ORM\OneToMany(mappedBy: 'customerProject', targetEntity: BuildInstance::class)]
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

    public function __construct()
    {
        $this->buildInstances = new ArrayCollection();
        $this->positions = new ArrayCollection();
        $this->history = new ArrayCollection();
        $this->accessories = new ArrayCollection();
        $this->materialAllocations = new ArrayCollection();
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

    public function setCustomer(?Customer $customer): self
    {
        $this->customer = $customer;

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

    public function requiresSerialTracking(Part $part): bool
    {
        foreach ($this->accessories as $accessory) {
            if ($accessory->getPart() === $part && $accessory->isSerialTracking()) {
                return true;
            }
        }

        return false;
    }
}
