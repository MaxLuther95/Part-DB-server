<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Helpers\Trees\TreeViewNode;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ProductionTreeBuilder
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /** @return list<TreeViewNode> */
    public function getTree(): array
    {
        $tree = [
            (new TreeViewNode(
                $this->trans('production.navigation.customers_projects'),
                null,
                [
                    (new TreeViewNode(
                        $this->trans('production.customer_project.plural'),
                        $this->urlGenerator->generate('production_customer_project_index'),
                    ))->setIcon('fa-fw fa-treeview fa-solid fa-folder-open'),
                    (new TreeViewNode(
                        $this->trans('production.customer_project.my_projects'),
                        $this->urlGenerator->generate('production_customer_project_mine', ['scope' => 'active']),
                    ))->setIcon('fa-fw fa-treeview fa-solid fa-user-check'),
                    (new TreeViewNode(
                        $this->trans('production.navigation.required_parts'),
                        $this->urlGenerator->generate('production_required_parts', ['missing' => 1]),
                    ))->setIcon('fa-fw fa-treeview fa-solid fa-cart-flatbed'),
                    (new TreeViewNode(
                        $this->trans('production.customer.plural'),
                        $this->urlGenerator->generate('production_customer_index'),
                    ))->setIcon('fa-fw fa-treeview fa-solid fa-address-book'),
                    (new TreeViewNode(
                        $this->trans('production.navigation.templates'),
                        $this->urlGenerator->generate('production_template_index'),
                    ))->setIcon('fa-fw fa-treeview fa-solid fa-box-archive'),
                ],
            ))->setIcon('fa-fw fa-treeview fa-solid fa-users')->setExpanded(),
            (new TreeViewNode(
                $this->trans('production.navigation.build'),
                $this->urlGenerator->generate('production_build'),
            ))->setIcon('fa-fw fa-treeview fa-solid fa-wrench')->setExpanded(),
            (new TreeViewNode(
                $this->trans('production.build_instance.plural'),
                $this->urlGenerator->generate('production_build_instance_index'),
            ))->setIcon('fa-fw fa-treeview fa-solid fa-layer-group'),
        ];

        return $tree;
    }

    private function trans(string $key): string
    {
        return $this->translator->trans($key, domain: 'production');
    }
}
