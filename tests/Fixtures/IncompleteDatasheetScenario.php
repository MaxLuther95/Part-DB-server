<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Entity\Production\DatasheetBlockType;
use App\Entity\Production\DatasheetTemplate;
use App\Services\Production\DatasheetTemplateManager;
use App\Services\Production\ProtocolManager;
use Doctrine\ORM\EntityManagerInterface;

final class IncompleteDatasheetScenario
{
    /** @return array<string, mixed> */
    public static function create(EntityManagerInterface $em, ProtocolManager $protocols, DatasheetTemplateManager $datasheets): array
    {
        $run = ProtocolRunScenario::create($em, $protocols);
        $run->complete($run->getStartedBy());
        $em->flush();
        $instance = $run->getBuildInstance();
        $second = $protocols->createRun($instance, $run->getRevision(), $run->getStartedBy());
        $second->getRows()->first()->getAnswers()->first()->setValue('2.000');
        $second->complete($run->getStartedBy());
        $em->flush();
        $template = (new DatasheetTemplate())->setName('Incomplete datasheet test')->setProductTitle('Test data sheet');
        $template->addSystemTemplate($instance->getSystemTemplate());
        $revision = $datasheets->createInitialDraft($template);
        $field = $run->getRows()->first()->getAnswers()->first()->getField();
        $datasheets->addBlock($revision)->setType(DatasheetBlockType::Value)->setLabel('Measured voltage')
            ->setSourcePath('protocol.'.$run->getRevision()->getTemplate()->getId().'.'.$field->getStableKey());
        $missing = $datasheets->addBlock($revision)->setType(DatasheetBlockType::Value)->setLabel('Device comment')->setRequired(true)->setSourcePath('instance.notes');
        $requiredNote = $datasheets->addBlock($revision)->setType(DatasheetBlockType::EditableNote)->setLabel('Required customer statement')->setRequired(true)->setText('Default customer statement');
        $optionalNote = $datasheets->addBlock($revision)->setType(DatasheetBlockType::EditableNote)->setLabel('Optional customer note')->setRequired(false);
        $em->persist($template);
        $datasheets->publish($revision, $run->getStartedBy());
        $em->flush();
        $selectionKey = $instance->getId().'_'.$run->getRevision()->getTemplate()->getId();

        return compact('run', 'second', 'instance', 'template', 'revision', 'missing', 'requiredNote', 'optionalNote', 'selectionKey');
    }
}
