<?php

declare(strict_types=1);

namespace App\Tests\Controller\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\BuildStatus;
use App\Entity\Production\SystemTemplate;
use App\Entity\UserSystem\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BuildStatusControllerTest extends WebTestCase
{
    #[DataProvider('submittedStatuses')]
    public function testStableStatusValuesCannotBeConfusedWithOldChoicePositions(string $submitted, bool $valid): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        self::assertInstanceOf(User::class, $admin);
        $client->loginUser($admin);

        $template = (new SystemTemplate())->setName('Status form test');
        $instance = (new BuildInstance())->setSystemTemplate($template)->setSerialNumber('STATUS-FORM')->setStatus(BuildStatus::InProgress);
        $em->persist($template);
        $em->persist($instance);
        $em->flush();
        $id = $instance->getId();

        $crawler = $client->request('GET', '/en/production/build-instances/'.$id.'/edit');
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['planned', 'in_progress', 'completed', 'installed', 'scrapped'],
            $crawler->filter('select[name="build_instance[status]"] option')->each(static fn($option): ?string => $option->attr('value')),
        );

        $form = $crawler->filter('form[name="build_instance"]')->form();
        $form['build_instance[serialNumber][confirmed]']->tick();
        $form['build_instance[status]']->disableValidation()->setValue($submitted);
        $client->submit($form);
        if ($valid) {
            self::assertResponseRedirects();
        } else {
            self::assertResponseStatusCodeSame(422);
        }

        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(
            $valid ? 'completed' : 'in_progress',
            $connection->fetchOne('SELECT status FROM production_build_instances WHERE id = ?', [$id]),
        );
    }

    public static function submittedStatuses(): iterable
    {
        yield 'old numeric paused choice' => ['2', false];
        yield 'removed status' => ['paused', false];
        yield 'explicit completion' => ['completed', true];
    }
}
