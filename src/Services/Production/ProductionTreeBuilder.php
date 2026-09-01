<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Helpers\Trees\TreeViewNode;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ProductionTreeBuilder
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Security $security,
    ) {
    }

    /** @return list<TreeViewNode> */
    public function getTree(): array
    {
        $tree = [];
        $orderProjectNodes = [];
        if ($this->security->isGranted('@production_projects.read')) {
            $orderProjectNodes[] = (new TreeViewNode(
                $this->trans('production.project.plural'),
                $this->urlGenerator->generate('production_project_index'),
            ))->setIcon('fa-fw fa-treeview fa-solid fa-diagram-project');
        }
        if ($this->security->isGranted('@production_orders.read')) {
            $orderProjectNodes[] = (new TreeViewNode(
                $this->trans('production.customer_project.plural'),
                $this->urlGenerator->generate('production_customer_project_index'),
            ))->setIcon('fa-fw fa-treeview fa-solid fa-folder-open');
            $orderProjectNodes[] = (new TreeViewNode(
                $this->trans('production.customer_project.my_projects'),
                $this->urlGenerator->generate('production_customer_project_mine', ['scope' => 'active']),
            ))->setIcon('fa-fw fa-treeview fa-solid fa-user-check');
        }
        if ([] !== $orderProjectNodes) {
            $tree[] = (new TreeViewNode(
                $this->trans('production.navigation.orders_projects'),
                null,
                $orderProjectNodes,
            ))->setIcon('fa-fw fa-treeview fa-solid fa-clipboard-list')->setExpanded();
        }

        $managementNodes = [];
        if ($this->security->isGranted('@production_material.read')) {
            $managementNodes[] = (new TreeViewNode(
                $this->trans('production.navigation.required_parts'),
                $this->urlGenerator->generate('production_required_parts', ['missing' => 1]),
            ))->setIcon('fa-fw fa-treeview fa-solid fa-cart-flatbed');
        }
        if ($this->security->isGranted('@production_customers.read')) {
            $managementNodes[] = (new TreeViewNode(
                $this->trans('production.customer.plural'),
                $this->urlGenerator->generate('production_customer_index'),
            ))->setIcon('fa-fw fa-treeview fa-solid fa-address-book');
        }
        if ($this->security->isGranted('@production_system_templates.read')) {
            $managementNodes[] = (new TreeViewNode(
                $this->trans('production.navigation.templates'),
                $this->urlGenerator->generate('production_template_index'),
            ))->setIcon('fa-fw fa-treeview fa-solid fa-box-archive');
        }
        if ($this->security->isGranted('@production_import_mappings.read')) {
            $managementNodes[] = (new TreeViewNode(
                $this->trans('production.navigation.import_mappings'),
                $this->urlGenerator->generate('production_order_import_mapping_index'),
            ))->setIcon('fa-fw fa-treeview fa-solid fa-table-list');
        }
        if ([] !== $managementNodes) {
            $tree[] = (new TreeViewNode(
                $this->trans('production.navigation.management'),
                null,
                $managementNodes,
            ))->setIcon('fa-fw fa-treeview fa-solid fa-gears');
        }

        if ($this->security->isGranted('@production_build_instances.read')) {
            $buildNodes = [];
            if ($this->security->isGranted('@production_build_instances.build')) {
                $buildNodes[] = (new TreeViewNode(
                    $this->trans('production.navigation.build'),
                    $this->urlGenerator->generate('production_build'),
                ))->setIcon('fa-fw fa-treeview fa-solid fa-wrench');
            }
            $tree[] = (new TreeViewNode(
                $this->trans('production.build_instance.plural'),
                $this->urlGenerator->generate('production_build_instance_index'),
                $buildNodes,
            ))->setIcon('fa-fw fa-treeview fa-solid fa-layer-group');
        }

        return $tree;
    }

    private function trans(string $key): string
    {
        return $this->translator->trans($key, domain: 'production');
    }
}
