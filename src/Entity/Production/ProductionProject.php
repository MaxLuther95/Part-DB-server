<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Repository\Production\ProductionProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProductionProjectRepository::class)]
#[ORM\Table(name: 'production_projects')]
#[ORM\Index(name: 'IDX_PROD_PROJECT_STATUS_DATE', columns: ['status', 'datetime_added'])]
#[UniqueEntity(fields: ['projectNumber'], message: 'production.project.number.unique')]
class ProductionProject extends AbstractProductionEntity
{
    #[ORM\Column(name: 'project_number', type: Types::STRING, length: 64, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    private string $projectNumber = '';

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $description = '';

    #[ORM\Column(type: Types::STRING, length: 32, enumType: ProductionProjectStatus::class)]
    private ProductionProjectStatus $status = ProductionProjectStatus::Planning;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /** @var Collection<int, CustomerProject> */
    #[ORM\OneToMany(mappedBy: 'productionProject', targetEntity: CustomerProject::class, fetch: 'EXTRA_LAZY')]
    #[ORM\OrderBy(['projectNumber' => 'ASC'])]
    private Collection $orders;

    public function __construct()
    {
        $this->orders = new ArrayCollection();
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

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = trim($description);

        return $this;
    }

    public function getStatus(): ProductionProjectStatus
    {
        return $this->status;
    }

    public function setStatus(ProductionProjectStatus $status): self
    {
        $this->status = $status;

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

    /** @return Collection<int, CustomerProject> */
    public function getOrders(): Collection
    {
        return $this->orders;
    }

    public function addOrder(CustomerProject $order): self
    {
        if (!$this->orders->contains($order)) {
            $this->orders->add($order);
        }

        return $this;
    }

    public function removeOrder(CustomerProject $order): self
    {
        $this->orders->removeElement($order);

        return $this;
    }
}
