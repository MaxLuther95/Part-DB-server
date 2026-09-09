<?php

declare(strict_types=1);

namespace App\Entity\Production;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'production_protocol_run_rows')]
#[ORM\Index(name: 'IDX_PROD_PROTOCOL_ROW_RUN', columns: ['run_id'])]
#[ORM\Index(name: 'IDX_PROD_PROTOCOL_ROW_SECTION', columns: ['section_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_PROD_PROTOCOL_RUN_SECTION', columns: ['run_id', 'section_id'])]
class ProtocolRunSectionRow extends AbstractProductionEntity
{
    #[ORM\ManyToOne(targetEntity: ProtocolRun::class, inversedBy: 'rows')]
    #[ORM\JoinColumn(name: 'run_id', nullable: false, onDelete: 'CASCADE')]
    private ?ProtocolRun $run = null;

    #[ORM\ManyToOne(targetEntity: ProtocolTemplateSection::class)]
    #[ORM\JoinColumn(name: 'section_id', nullable: false)]
    private ?ProtocolTemplateSection $section = null;

    /**
     * @var Collection<int, ProtocolAnswer>
     */
    #[ORM\OneToMany(mappedBy: 'row', targetEntity: ProtocolAnswer::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy([
        'id' => 'ASC',
    ])]
    private Collection $answers;

    public function __construct()
    {
        $this->answers = new ArrayCollection();
    }

    public function getRun(): ?ProtocolRun
    {
        return $this->run;
    }

    public function setRun(ProtocolRun $run): self
    {
        $run->assertEditable();
        $this->run = $run;

        return $this;
    }

    public function getSection(): ?ProtocolTemplateSection
    {
        return $this->section;
    }

    public function setSection(ProtocolTemplateSection $section): self
    {
        $this->run?->assertEditable();
        $this->section = $section;

        return $this;
    }

    /**
     * @return Collection<int, ProtocolAnswer>
     */
    public function getAnswers(): Collection
    {
        return $this->answers;
    }

    public function addAnswer(ProtocolAnswer $answer): self
    {
        $this->run?->assertEditable();
        if (! $this->answers->contains($answer)) {
            $this->answers->add($answer);
            $answer->setRow($this);
        }

        return $this;
    }

    public function getAnswerForField(ProtocolTemplateField $field): ?ProtocolAnswer
    {
        foreach ($this->answers as $answer) {
            if ($answer->getField() === $field) {
                return $answer;
            }
        }

        return null;
    }
}
