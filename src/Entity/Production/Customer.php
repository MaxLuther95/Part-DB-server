<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Repository\Production\CustomerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CustomerRepository::class)]
#[ORM\Table(name: 'production_customers')]
#[UniqueEntity(fields: ['customerNumber'], message: 'production.customer.number.unique')]
class Customer extends AbstractProductionEntity
{
    #[ORM\Column(name: 'customer_number', type: Types::STRING, length: 64, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    private string $customerNumber = '';

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $description = '';

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    /** @var Collection<int, CustomerProject> */
    #[ORM\OneToMany(mappedBy: 'customer', targetEntity: CustomerProject::class)]
    #[ORM\OrderBy(['projectNumber' => 'ASC'])]
    private Collection $projects;

    public function __construct()
    {
        $this->projects = new ArrayCollection();
    }

    public function __toString(): string
    {
        return sprintf('%s – %s', $this->customerNumber, $this->name);
    }

    public function getCustomerNumber(): string
    {
        return $this->customerNumber;
    }

    public function setCustomerNumber(string $customerNumber): self
    {
        $this->customerNumber = trim($customerNumber);

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
        $this->description = $description;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    /** @return Collection<int, CustomerProject> */
    public function getProjects(): Collection
    {
        return $this->projects;
    }

    /** @return Collection<int, CustomerProject> */
    public function getOrders(): Collection
    {
        return $this->projects;
    }
}
