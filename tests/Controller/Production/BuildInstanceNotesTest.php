<?php

declare(strict_types=1);

namespace App\Tests\Controller\Production;

use App\Entity\Production\{BuildInstance, Customer, CustomerProject, ProductionProject, ProjectPosition, SystemTemplate};
use App\Entity\ProjectSystem\Project;
use App\Entity\UserSystem\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BuildInstanceNotesTest extends WebTestCase
{
    public static function contentTypes(): iterable
    {
        yield 'system device' => [true];
        yield 'project assembly' => [false];
    }

    #[DataProvider('contentTypes')]
    public function testExistingNoteEditingAndOrderMarkers(bool $system): void
    {
        $client = self::createClient();
        $client->catchExceptions(false);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->setNeedPwChange(false);
        $customer = (new Customer())->setName('Synthetic note customer')->setCustomerNumber('BUILD-NOTE-C');
        $project = (new ProductionProject())->setName('Synthetic note project')->setProjectNumber('BUILD-NOTE-P');
        $order = (new CustomerProject())->setCustomer($customer)->setProductionProject($project)->setProjectNumber('BUILD-NOTE-O');
        $content = $system ? (new SystemTemplate())->setName('Synthetic device') : (new Project())->setName('Synthetic assembly');
        foreach ([$customer, $project, $order, $content] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $position = (new ProjectPosition())->setCustomerProject($order)->setName('Synthetic position')->setNotes('Separate position note');
        $system ? $position->setSystemTemplate($content) : $position->setTemplateProject($content);
        $secondPosition = (new ProjectPosition())->setCustomerProject($order)->setName('Second synthetic position')->setPosition(1);
        $system ? $secondPosition->setSystemTemplate($content) : $secondPosition->setTemplateProject($content);
        $notes = "Device note with ä and \"quotes\"\n<script>example</script>\n".str_repeat('Long description. ', 30);
        $first = (new BuildInstance())->setProjectPosition($position)->setSerialNumber('NOTE-0001')->setNotes($notes);
        $second = (new BuildInstance())->setProjectPosition($secondPosition)->setSerialNumber('NOTE-0002');
        foreach ([$position, $secondPosition, $first, $second] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $id = $first->getId();
        $secondId = $second->getId();
        $orderId = $order->getId();
        $positionId = $position->getId();
        $notes = trim($notes);
        $client->loginUser($admin);
        $url = '/en/production/build-instances/'.$id;
        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-build-instance-details] + [data-build-instance-notes]');
        self::assertSelectorTextContains('[data-build-instance-note-text]', '<script>example</script>');
        self::assertSelectorNotExists('[data-build-instance-notes] script');
        self::assertSelectorExists('[data-build-instance-notes] a[href="'.$url.'/edit#build_instance_notes"]');

        $client->request('GET', '/en/production/customer-projects/'.$orderId);
        self::assertResponseIsSuccessful();
        foreach (['positions', 'build-instances'] as $section) {
            $selector = '[data-order-section="'.$section.'"] [data-order-instance="'.$id.'"]';
            self::assertSame($notes, $client->getCrawler()->filter($selector.' .fa-note-sticky')->attr('title'));
            self::assertSelectorNotExists($selector.' script');
            self::assertSelectorNotExists('[data-order-section="'.$section.'"] [data-order-instance="'.$secondId.'"] .fa-note-sticky');
        }

        foreach (["Revised device note\nSecond line with <b>literal text</b>", ''] as $replacement) {
            $crawler = $client->request('GET', $url.'/edit');
            self::assertResponseIsSuccessful();
            $form = $crawler->filter('form[name="build_instance"]')->form();
            self::assertSame($notes, $form['build_instance[notes]']->getValue());
            $form['build_instance[notes]'] = $replacement;
            $form['build_instance[serialNumber][confirmed]'] = true;
            $client->submit($form);
            self::assertResponseRedirects($url);
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            if ('' === $replacement) {
                self::assertSelectorTextContains('[data-build-instance-notes] a', 'Add note');
            } else {
                self::assertSelectorTextContains('[data-build-instance-note-text]', '<b>literal text</b>');
                self::assertSelectorNotExists('[data-build-instance-note-text] b');
            }
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $em->clear();
            self::assertSame('' === $replacement ? null : $replacement, $em->find(BuildInstance::class, $id)->getNotes());
            self::assertNull($em->find(BuildInstance::class, $secondId)->getNotes());
            self::assertSame('Separate position note', $em->find(ProjectPosition::class, $positionId)->getNotes());
            self::assertSame($positionId, $em->find(BuildInstance::class, $id)->getProjectPosition()->getId());
            $client->request('GET', '/en/production/customer-projects/'.$orderId);
            $markers = $client->getCrawler()->filter('[data-order-instance="'.$id.'"] .fa-note-sticky');
            self::assertCount('' === $replacement ? 0 : 2, $markers);
            foreach ($markers as $marker) {
                self::assertSame($replacement, $marker->getAttribute('title'));
            }
            $notes = $replacement;
        }
    }

    public static function permissions(): iterable
    {
        yield 'read only' => [true];
        yield 'no read access' => [false];
    }

    #[DataProvider('permissions')]
    public function testNotesRespectReadAndEditPermissions(bool $canRead): void
    {
        $client = self::createClient(['debug' => false]);
        $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->setNeedPwChange(false);
        $admin->getPermissions()->setPermissionValue('users', 'edit_permissions', false);
        $admin->getPermissions()->setPermissionValue('groups', 'edit_permissions', false);
        $admin->getPermissions()->setPermissionValue('production_build_instances', 'edit', false);
        $admin->getPermissions()->setPermissionValue('production_build_instances', 'read', $canRead);
        $template = (new SystemTemplate())->setName('Synthetic restricted type');
        $instance = (new BuildInstance())->setSystemTemplate($template)->setSerialNumber('NOTE-ACCESS')->setNotes('Private synthetic device note');
        $em->persist($template);
        $em->persist($instance);
        $em->flush();
        $client->loginUser($admin);
        $url = '/en/production/build-instances/'.$instance->getId();
        $client->request('GET', $url);
        if (!$canRead) {
            self::assertResponseStatusCodeSame(403);
            self::assertStringNotContainsString('Private synthetic device note', $client->getResponse()->getContent());
            return;
        }
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-build-instance-note-text]', 'Private synthetic device note');
        self::assertSelectorNotExists('[data-build-instance-notes] a');
        $client->request('GET', $url.'/edit');
        self::assertResponseStatusCodeSame(403);
    }
}
