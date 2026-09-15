<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Entity\Production\ProtocolFieldType;
use App\Entity\Production\ProtocolTemplate;
use App\Entity\Production\ProtocolTemplateField;
use App\Entity\Production\ProtocolTemplateRevision;
use App\Entity\Production\ProtocolTemplateSection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ProtocolTemplateImporter
{
    public function __construct(private ProtocolTemplateImportValidator $validator, private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param array<int, array{revision: int, name: string}> $selection Source template index to selected source revision/name.
     * @return list<ProtocolTemplate>
     */
    public function import(string $json, array $selection): array
    {
        $document = $this->validator->parse($json);
        if ([] === $selection) {
            throw new \DomainException('production.protocol.import.select_one');
        }
        $prepared = [];
        $names = [];
        foreach ($selection as $index => $choice) {
            $source = $document['templates'][$index] ?? null;
            $name = trim($choice['name']);
            if (null === $source || '' === $name || mb_strlen($name) > 255 || preg_match('/[\x00-\x1F]/', $name)) {
                throw new \DomainException('production.protocol.import.invalid_selection');
            }
            $revisions = array_column($source['revisions'], null, 'number');
            $revision = $revisions[$choice['revision']] ?? null;
            if (null === $revision) {
                throw new \DomainException('production.protocol.import.invalid_selection');
            }
            $normalized = mb_strtolower($name);
            if (isset($names[$normalized]) || $this->nameExists($name)) {
                throw new \DomainException('production.protocol.import.name_conflict');
            }
            $names[$normalized] = true;
            $prepared[] = [$source, $revision, $name];
        }

        return $this->entityManager->wrapInTransaction(function () use ($prepared): array {
            $created = [];
            foreach ($prepared as [$source, $data, $name]) {
                // Recheck using database collation, also against earlier items in this batch.
                if ($this->nameExists($name)) {
                    throw new \DomainException('production.protocol.import.name_conflict');
                }
                $template = (new ProtocolTemplate())->setName($name)->setDescription($source['description'])->setActive($source['active']);
                $revision = (new ProtocolTemplateRevision())->setRevisionNumber(1)->setChangeNote($data['change_note']);
                $template->addRevision($revision);
                foreach ($data['sections'] as $sectionData) {
                    $section = (new ProtocolTemplateSection($sectionData['key']))->setName($sectionData['name'])
                        ->setDescription($sectionData['description'])->setPosition($sectionData['position']);
                    $revision->addSection($section);
                    foreach ($sectionData['fields'] as $fieldData) {
                        $section->addField((new ProtocolTemplateField($fieldData['key']))->setLabel($fieldData['label'])
                            ->setType(ProtocolFieldType::from($fieldData['type']))->setUnit($fieldData['unit'])
                            ->setHelpText($fieldData['help_text'])->setPosition($fieldData['position'])
                            ->setLayoutColumns($fieldData['layout_columns'])->setStartNewRow($fieldData['start_new_row'])
                            ->setRequired($fieldData['required'])->setOptions($fieldData['options']));
                    }
                }
                $this->entityManager->persist($template);
                $this->entityManager->flush();
                $created[] = $template;
            }

            return $created;
        });
    }

    private function nameExists(string $name): bool
    {
        return (int) $this->entityManager->createQueryBuilder()->select('COUNT(template.id)')
            ->from(ProtocolTemplate::class, 'template')->where('LOWER(template.name) = LOWER(:name)')
            ->setParameter('name', $name)->getQuery()->getSingleScalarResult() > 0;
    }
}
