(() => {
    if (window.productionTreeToggleInitialized) {
        return;
    }
    window.productionTreeToggleInitialized = true;

    const rowsForParent = (tree, rowSelector, parentAttribute, parentId) => Array.from(
        tree.querySelectorAll(rowSelector),
    ).filter((row) => row.getAttribute(parentAttribute) === String(parentId));

    const hideTemplateBranch = (tree, nodeKey) => {
        rowsForParent(tree, '[data-system-template-row]', 'data-template-parent', nodeKey).forEach((row) => {
            row.hidden = true;
            row.querySelector('[data-system-template-toggle]')?.setAttribute('aria-expanded', 'false');
            hideTemplateBranch(tree, row.dataset.templateNode);
        });
    };

    const hidePositionBranch = (tree, positionId) => {
        rowsForParent(tree, '[data-production-position-row]', 'data-parent-id', positionId).forEach((row) => {
            row.hidden = true;
            if (row.dataset.positionId) {
                hidePositionBranch(tree, row.dataset.positionId);
            }
        });
    };

    const showPositionBranch = (tree, positionId) => {
        rowsForParent(tree, '[data-production-position-row]', 'data-parent-id', positionId).forEach((row) => {
            row.hidden = false;
            if (!row.dataset.positionId) {
                return;
            }
            const childToggle = row.querySelector('[data-production-position-toggle]');
            if (childToggle?.getAttribute('aria-expanded') === 'true') {
                showPositionBranch(tree, row.dataset.positionId);
            } else {
                hidePositionBranch(tree, row.dataset.positionId);
            }
        });
    };

    document.addEventListener('click', (event) => {
        const templateToggle = event.target.closest('[data-system-template-toggle]');
        if (templateToggle) {
            const tree = templateToggle.closest('[data-system-template-tree]');
            if (!tree) {
                return;
            }
            const nodeKey = templateToggle.dataset.systemTemplateToggle;
            const expanded = templateToggle.getAttribute('aria-expanded') === 'true';
            templateToggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            if (expanded) {
                hideTemplateBranch(tree, nodeKey);
            } else {
                rowsForParent(tree, '[data-system-template-row]', 'data-template-parent', nodeKey).forEach((row) => {
                    row.hidden = false;
                });
            }
            return;
        }

        const positionToggle = event.target.closest('[data-production-position-toggle]');
        if (!positionToggle) {
            return;
        }
        const tree = positionToggle.closest('[data-production-position-tree]');
        if (!tree) {
            return;
        }
        const positionId = positionToggle.dataset.productionPositionToggle;
        const expanded = positionToggle.getAttribute('aria-expanded') === 'true';
        positionToggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        if (expanded) {
            hidePositionBranch(tree, positionId);
        } else {
            showPositionBranch(tree, positionId);
        }
    });
})();
