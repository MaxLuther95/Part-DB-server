<?php

declare(strict_types=1);

namespace App\Tests\Controller\Production;

use App\Entity\UserSystem\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProductionNavigationControllerTest extends WebTestCase
{
    private const READ_MODULES = [
        'production_orders', 'production_projects', 'production_customers',
        'production_system_templates', 'production_build_instances', 'production_material',
        'production_protocol_templates', 'production_datasheet_templates', 'production_import_mappings',
    ];

    public function testFullTreeUsesTheAgreedGroupsAndExistingDestinations(): void
    {
        $client = self::createClient();
        $admin = self::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $client->loginUser($admin);
        $client->request('GET', '/en/tree/production');
        self::assertResponseIsSuccessful();
        $tree = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['Orders & projects', 'Production workflow', 'Templates', 'Master data'], array_column($tree, 'text'));
        self::assertSame([
            ['My orders', 'Orders', 'Projects'],
            ['Build', 'Devices and assemblies', 'Required parts'],
            ['System templates', 'Protocol templates', 'Datasheet templates'],
            ['Customers', 'Serial number ranges', 'Import mappings'],
        ], array_map(static fn (array $group): array => array_column($group['nodes'], 'text'), $tree));
        self::assertSame([true, true, false, false], array_map(static fn (array $group): bool => $group['state']['expanded'], $tree));
        foreach ($tree as $group) {
            self::assertFalse($group['selectable']);
            foreach ($group['nodes'] as $leaf) {
                self::assertArrayNotHasKey('nodes', $leaf);
            }
        }
        self::assertSame('/en/production/customer-projects/mine?scope=active', $tree[0]['nodes'][0]['href']);
        self::assertSame('/en/production/required-parts?missing=1', $tree[1]['nodes'][2]['href']);
        $hrefs = array_merge(...array_map(static fn (array $group): array => array_column($group['nodes'], 'href'), $tree));
        self::assertCount(12, array_unique($hrefs));

        $client->request('GET', '/de/tree/production');
        self::assertResponseIsSuccessful();
        $german = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['Aufträge und Projekte', 'Fertigungsablauf', 'Vorlagen', 'Stammdaten'], array_column($german, 'text'));
        self::assertSame('Seriennummernkreise', $german[3]['nodes'][1]['text']);
    }

    /** @param list<array{string, string}> $grants @param list<string> $leaves */
    #[DataProvider('singleFeatureCases')]
    public function testSidebarSelectorAndEndpointAgreeForRestrictedUsers(array $grants, string $group, array $leaves): void
    {
        $client = $this->restrictedClient($grants);
        $client->request('GET', '/en/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#sidebar-panel-production');
        self::assertSelectorExists('#sidebar-panel-production [data-mode="production"]');
        $client->request('GET', '/en/tree/production');
        self::assertResponseIsSuccessful();
        $tree = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $tree);
        self::assertSame($group, $tree[0]['text']);
        self::assertSame($leaves, array_column($tree[0]['nodes'], 'text'));

        // Each visible leaf must be usable; tree access must not grant unrelated pages.
        foreach ($tree[0]['nodes'] as $leaf) {
            $client->request('GET', $leaf['href']);
            self::assertResponseIsSuccessful();
        }
        $client->request('GET', in_array(['production_material', 'read'], $grants, true)
            ? '/en/production/protocol-templates' : '/en/production/required-parts');
        self::assertResponseStatusCodeSame(403);
    }

    public static function singleFeatureCases(): iterable
    {
        yield 'orders only' => [[['production_orders', 'read']], 'Orders & projects', ['My orders', 'Orders']];
        yield 'projects only' => [[['production_projects', 'read']], 'Orders & projects', ['Projects']];
        yield 'material only' => [[['production_material', 'read']], 'Production workflow', ['Required parts']];
        yield 'instances read only' => [[['production_build_instances', 'read']], 'Production workflow', ['Devices and assemblies']];
        yield 'instances read and build' => [[['production_build_instances', 'read'], ['production_build_instances', 'build']], 'Production workflow', ['Build', 'Devices and assemblies']];
        yield 'system templates only' => [[['production_system_templates', 'read']], 'Templates', ['System templates']];
        yield 'protocol templates only' => [[['production_protocol_templates', 'read']], 'Templates', ['Protocol templates']];
        yield 'datasheet templates only' => [[['production_datasheet_templates', 'read']], 'Templates', ['Datasheet templates']];
        yield 'customers only' => [[['production_customers', 'read']], 'Master data', ['Customers']];
        yield 'import mappings only' => [[['production_import_mappings', 'read']], 'Master data', ['Import mappings']];
        yield 'user permission administrator only' => [[['users', 'edit_permissions']], 'Master data', ['Serial number ranges']];
        yield 'group permission administrator only' => [[['groups', 'edit_permissions']], 'Master data', ['Serial number ranges']];
    }

    public function testNoVisibleLeafDeniesTheTreeAndHidesBothSidebarAndSelector(): void
    {
        // Build permission alone does not grant access to the device list/build overview.
        $client = $this->restrictedClient([['production_build_instances', 'build']]);
        $client->request('GET', '/en/');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#sidebar-panel-production');
        self::assertSelectorNotExists('[data-mode="production"]');
        $client->request('GET', '/en/tree/production');
        self::assertResponseStatusCodeSame(403);
    }

    public function testRevokedTemplateAccessDoesNotLeaveCachedNavigationVisible(): void
    {
        $client = $this->restrictedClient([['production_protocol_templates', 'read']]);
        $client->request('GET', '/en/');
        self::assertSelectorExists('#sidebar-panel-production');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->getPermissions()->setPermissionValue('production_protocol_templates', 'read', false);
        $em->flush();
        $client->loginUser($admin);
        $client->request('GET', '/en/');
        self::assertSelectorNotExists('#sidebar-panel-production');
        self::assertSelectorNotExists('[data-mode="production"]');
        $client->request('GET', '/en/tree/production');
        self::assertResponseStatusCodeSame(403);
    }

    /** @param list<array{string, string}> $grants */
    private function restrictedClient(array $grants): KernelBrowser
    {
        $client = self::createClient();
        $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->setNeedPwChange(false);
        foreach (self::READ_MODULES as $module) {
            $admin->getPermissions()->setPermissionValue($module, 'read', false);
        }
        $admin->getPermissions()->setPermissionValue('production_build_instances', 'build', false);
        $admin->getPermissions()->setPermissionValue('users', 'edit_permissions', false);
        $admin->getPermissions()->setPermissionValue('groups', 'edit_permissions', false);
        foreach ($grants as [$module, $operation]) {
            $admin->getPermissions()->setPermissionValue($module, $operation, true);
        }
        $em->flush();
        $client->loginUser($admin);

        return $client;
    }
}
