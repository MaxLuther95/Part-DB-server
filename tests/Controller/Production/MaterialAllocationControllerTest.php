<?php

declare(strict_types=1);

namespace App\Tests\Controller\Production;

use App\Entity\Parts\PartLot;
use App\Entity\Production\{ProjectMaterialAllocation, ProjectMaterialReservation};
use App\Entity\UserSystem\User;
use App\Services\Production\{ProductionBuildWorkflow, ProductionReservationManager};
use App\Tests\Fixtures\StaleBuildScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MaterialAllocationControllerTest extends WebTestCase
{
    public static function submissions(): iterable
    {
        yield 'partial provision' => ['1', false, true];
        yield 'zero quantity' => ['0', false, false];
        yield 'quantity exceeds remaining demand' => ['4', false, false];
        yield 'fractional quantity' => ['1.5', false, false];
        yield 'invalid CSRF token' => ['1', true, false];
    }

    #[DataProvider('submissions')]
    public function testProvisionFormAndStockConsistency(string $quantity, bool $invalidToken, bool $valid): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $scenario = StaleBuildScenario::create($em, self::getContainer()->get(ProductionBuildWorkflow::class));
        $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->setNeedPwChange(false);
        self::getContainer()->get(ProductionReservationManager::class)->refresh($scenario['order'], $scenario['site'], $admin);
        $orderId = $scenario['order']->getId();
        $partId = $scenario['part']->getId();
        $lotId = $scenario['lot']->getId();
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/en/production/customer-projects/'.$orderId.'/materials/'.$partId.'/allocate');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="form[quantity]"][type="number"][min="1"][max="3"][step="1"]');
        $form = $crawler->filter('form[name="form"]')->form([
            'form[lot]' => (string) $lotId,
            'form[quantity]' => $quantity,
        ]);
        if ($invalidToken) {
            $form['form[_token]'] = 'invalid-token';
        }
        $client->submit($form);
        self::assertResponseStatusCodeSame($valid ? 302 : 422);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame($valid ? 9.0 : 10.0, $em->find(PartLot::class, $lotId)->getAmount());
        $reservations = $em->getRepository(ProjectMaterialReservation::class)->findBy(['customerProject' => $orderId]);
        self::assertCount(1, $reservations);
        self::assertSame($valid ? 2 : 3, $reservations[0]->getQuantity());
        $allocations = $em->getRepository(ProjectMaterialAllocation::class)->findBy(['customerProject' => $orderId]);
        self::assertCount($valid ? 1 : 0, $allocations);
        if ($valid) {
            self::assertSame(1, $allocations[0]->getQuantity());
            self::assertSame($lotId, $allocations[0]->getSourcePartLot()->getId());
        }
    }
}
