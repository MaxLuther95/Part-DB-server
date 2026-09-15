<?php

declare(strict_types=1);

namespace App\Tests\Controller\Production;

use App\Entity\Parts\PartLot;
use App\Entity\Production\{BuildInstance, CustomerProject, CustomerProjectStatus, ProjectPosition, SerialNumberRange};
use App\Entity\ProjectSystem\Project;
use App\Entity\UserSystem\User;
use App\Services\Production\ProductionBuildWorkflow;
use App\Tests\Fixtures\StaleBuildScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StaleBuildWorkflowControllerTest extends WebTestCase
{
    public static function changes(): iterable
    {
        yield 'cancelled order' => ['cancelled'];
        yield 'deleted position' => ['deleted'];
    }

    #[DataProvider('changes')]
    public function testOldReviewPostShowsRecoveryWithoutMutation(string $change): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $s = StaleBuildScenario::create($em, self::getContainer()->get(ProductionBuildWorkflow::class));
        $ids = array_map(static fn($entity) => is_object($entity) ? $entity->getId() : null, $s);
        $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->setNeedPwChange(false);
        $em->flush();
        $client->loginUser($admin);
        $client->request('GET', '/en/production/project-positions/'.$ids['position'].'/build');
        $client->followRedirect();
        $details = $client->followRedirect();
        $client->submit($details->selectButton('Material auswählen')->form([
            'site_id' => $ids['site'],
            'details[n0][confirmed]' => '1',
        ]));
        $materials = $client->followRedirect();
        $client->submit($materials->selectButton('Auswahl prüfen')->form([
            'taken['.$ids['part'].']' => '1',
        ]));
        $review = $client->followRedirect();
        $form = $review->selectButton('Bau starten und Material ausbuchen')->form();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        if ('cancelled' === $change) {
            $em->find(CustomerProject::class, $ids['order'])->setStatus(CustomerProjectStatus::Cancelled);
        } else {
            $em->remove($em->find(ProjectPosition::class, $ids['position']));
        }
        $em->flush();
        $before = $em->getRepository(BuildInstance::class)->count([]);
        $client->submit($form);
        self::assertResponseStatusCodeSame(409);
        self::assertSelectorTextContains('.alert-warning', 'cancelled' === $change ? 'nicht mehr in Produktion' : 'Auftragsposition');
        self::assertSelectorExists('a[href="/en/production/customer-projects"]');
        self::assertSelectorNotExists('button[type="submit"]');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame($before, $em->getRepository(BuildInstance::class)->count([]));
        self::assertSame(10.0, $em->find(PartLot::class, $ids['lot'])->getAmount());
        self::assertSame(1, $em->find(SerialNumberRange::class, $ids['range'])->getNextNumber());
        // GET requests from bookmarks also show recovery instead of stale materials.
        $client->request('GET', preg_replace('~/review$~', '/materials', $form->getUri()));
        self::assertResponseStatusCodeSame(409);
    }
}
