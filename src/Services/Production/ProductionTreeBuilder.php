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

    public function hasVisibleEntries(): bool
    {
        // Derive access from the actual leaves, never a second permission list.
        return [] !== $this->getTree();
    }

    /** @return list<TreeViewNode> */
    public function getTree(): array
    {
        $tree = [];
        $orderProjectNodes = [];
        if ($this->security->isGranted('@production_orders.read')) {
            $orderProjectNodes[] = (new TreeViewNode(
                $this->trans('production.customer_project.my_projects'),
                $this->urlGenerator->generate('production_customer_project_mine', ['scope' => 'active']),
            ))->setIcon('fa-fw fa-treeview fa-solid fa-user-check');
            $orderProjectNodes[] = (new TreeViewNode(
                $this->trans('production.customer_project.plural'),
                $this->urlGenerator->generate('production_customer_project_index'),
            ))->setIcon('fa-fw fa-treeview fa-solid fa-folder-open');
        }
        if ($this->security->isGranted('@production_projects.read')) {
            $orderProjectNodes[] = (new TreeViewNode(
                $this->trans('production.project.plural'),
                $this->urlGenerator->generate('production_project_index'),
            ))->setIcon('fa-fw fa-treeview fa-solid fa-diagram-project');
        }
        if ([] !== $orderProjectNodes) {
            $tree[] = (new TreeViewNode(
                $this->trans('production.navigation.orders_projects'),
                null,
                $orderProjectNodes,
            ))->setIcon('fa-fw fa-treeview fa-solid fa-clipboard-list')->setExpanded();
        }

        $workflowNodes = [];
        if ($this->security->isGranted('@production_build_instances.read')) {
            if ($this->security->isGranted('@production_build_instances.build')) {
                $workflowNodes[] = (new TreeViewNode(
                    $this->trans('production.navigation.build'),
                    $this->urlGenerator->generate('production_build'),
                ))->setIcon('fa-fw fa-treeview fa-solid fa-wrench');
            }
            $workflowNodes[] = (new TreeViewNode(
                $this->trans('production.build_instance.plural'),
                $this->urlGenerator->generate('production_build_instance_index'),
            ))->setIcon('fa-fw fa-treeview fa-solid fa-layer-group');
        }
        if ($this->security->isGranted('@production_material.read')) {
            $workflowNodes[] = (new TreeViewNode(
                $this->trans('production.navigation.required_parts'),
                $this->urlGenerator->generate('production_required_parts', ['missing' => 1]),
            ))->setIcon('fa-fw fa-treeview fa-solid fa-cart-flatbed');
        }
        if ([] !== $workflowNodes) {
            $tree[] = (new TreeViewNode(
                $this->trans('production.navigation.workflow'),
                null,
                $workflowNodes,
            ))->setIcon('fa-fw fa-treeview fa-solid fa-industry')->setExpanded();
        }

        $templateNodes = [];
        if ($this->security->isGranted('@production_system_templates.read')) {
            $templateNodes[] = (new TreeViewNode(
                $this->trans('production.navigation.templates'),
                $this->urlGenerator->generate('production_template_index'),
            ))->setIcon('fa-fw fa-treeview fa-solid fa-box-archive');
        }
        if ($this->security->isGranted('@production_protocol_templates.read')) {
            $templateNodes[] = (new TreeViewNode(
                $this->trans('production.navigation.protocol_templates'),
                $this->urlGenerator->generate('production_protocol_template_index'),
            ))->setIcon('fa-fw fa-treeview fa-solid fa-clipboard-list');
        }
        if ($this->security->isGranted('@production_datasheet_templates.read')) {
            $templateNodes[] = (new TreeViewNode(
                $this->trans('production.navigation.datasheet_templates'),
                $this->urlGenerator->generate('production_datasheet_template_index'),
            ))->setIcon('fa-fw fa-treeview fa-solid fa-file-pdf');
        }
        if ([] !== $templateNodes) {
            $tree[] = (new TreeViewNode(
                $this->trans('production.navigation.template_group'),
                null,
                $templateNodes,
            ))->setIcon('fa-fw fa-treeview fa-solid fa-box-archive')->setExpanded(false);
        }

        $masterDataNodes = [];
        if ($this->security->isGranted('@production_customers.read')) {
            $masterDataNodes[] = (new TreeViewNode(
                $this->trans('production.customer.plural'),
                $this->urlGenerator->generate('production_customer_index'),
            ))->setIcon('fa-fw fa-treeview fa-solid fa-address-book');
        }
        if ($this->security->isGranted('@users.edit_permissions') || $this->security->isGranted('@groups.edit_permissions')) {
            $masterDataNodes[] = (new TreeViewNode(
                $this->trans('production.navigation.serial_number_ranges'),
                $this->urlGenerator->generate('production_serial_range_index'),
            ))->setIcon('fa-fw fa-treeview fa-solid fa-barcode');
        }
        if ($this->security->isGranted('@production_import_mappings.read')) {
            $masterDataNodes[] = (new TreeViewNode(
                $this->trans('production.navigation.import_mappings'),
                $this->urlGenerator->generate('production_order_import_mapping_index'),
            ))->setIcon('fa-fw fa-treeview fa-solid fa-table-list');
        }
        if ([] !== $masterDataNodes) {
            $tree[] = (new TreeViewNode(
                $this->trans('production.navigation.master_data'),
                null,
                $masterDataNodes,
            ))->setIcon('fa-fw fa-treeview fa-solid fa-gears')->setExpanded(false);
        }

        return $tree;
    }

    private function trans(string $key): string
    {
        return $this->translator->trans($key, domain: 'production');
    }
}
