<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\ProtocolFieldType;
use App\Entity\Production\ProtocolRun;
use App\Entity\Production\ProtocolTemplate;
use App\Entity\Production\ProtocolTemplateField;
use App\Entity\Production\ProtocolTemplateRevision;
use App\Entity\Production\ProtocolTemplateSection;
use App\Entity\Production\SystemTemplate;
use App\Entity\UserSystem\User;
use App\Services\Production\ProtocolManager;
use Doctrine\ORM\EntityManagerInterface;

final class ProtocolRunScenario
{
    public static function create(EntityManagerInterface $em, ProtocolManager $manager, bool $required = true): ProtocolRun
    {
        $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin?->setNeedPwChange(false);
        $template = (new ProtocolTemplate())->setName('Protocol concurrency test');
        $revision = new ProtocolTemplateRevision();
        $template->addRevision($revision);
        $section = (new ProtocolTemplateSection())->setName('Measurements');
        $revision->addSection($section);
        $section->addField((new ProtocolTemplateField())->setLabel('Voltage')->setType(ProtocolFieldType::Decimal)->setRequired($required));
        $system = (new SystemTemplate())->setName('Protocol concurrency system');
        $instance = (new BuildInstance())->setSystemTemplate($system)->setSerialNumber('PROTOCOL-'.bin2hex(random_bytes(6)));
        $template->addSystemTemplate($system);
        foreach ([$template, $system, $instance] as $entity) {
            $em->persist($entity);
        }
        $manager->publish($revision, $admin);
        $em->flush();
        $run = $manager->createRun($instance, $revision, $admin);
        $run->getRows()->first()->getAnswers()->first()->setValue('1.000');
        $em->flush();

        return $run;
    }
}
