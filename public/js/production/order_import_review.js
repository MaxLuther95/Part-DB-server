(() => {
    if (window.productionOrderImportReviewInitialized) {
        return;
    }
    window.productionOrderImportReviewInitialized = true;

    const findRoot = (element) => element?.closest?.('[data-order-import-review]') ?? null;

    const updateLineCount = (root) => {
        const body = root.querySelector('[data-import-lines]');
        const badge = root.querySelector('[data-import-line-count]');
        if (body && badge) {
            badge.textContent = String(body.querySelectorAll(':scope > tr').length);
        }
    };

    const parsedData = (root, key) => {
        try {
            return JSON.parse(root.dataset[key] || '[]');
        } catch (_error) {
            return [];
        }
    };

    const replaceUnitInput = (root, row) => {
        const input = row.querySelector('input[name$="[unit]"]');
        if (!input) {
            return;
        }
        const select = document.createElement('select');
        select.className = 'form-select form-select-sm';
        select.name = input.name;
        select.dataset.importUnit = '';
        for (const unit of parsedData(root, 'orderUnits')) {
            const option = document.createElement('option');
            option.value = unit.value;
            option.textContent = unit.label;
            select.append(option);
        }
        input.replaceWith(select);
    };

    document.addEventListener('change', (event) => {
        const target = event.target;
        const root = findRoot(target);
        if (!root || !(target instanceof HTMLSelectElement)) {
            return;
        }

        if (target.id === 'import-customer' || target.id === 'import-project') {
            const kind = target.id === 'import-customer' ? 'customer' : 'project';
            const option = target.options[target.selectedIndex];
            const number = root.querySelector(`#import-${kind}-number`);
            const name = root.querySelector(`#import-${kind}-name`);
            if (option?.dataset.number && number) {
                number.value = option.dataset.number;
            }
            if (option?.dataset.name && name) {
                name.value = option.dataset.name;
            }
            if (target.value === '0' && name) {
                name.value = '';
            }
            return;
        }

        if (target.matches('select[name$="[mapping_id]"]')) {
            const mappingUnits = parsedData(root, 'mappingUnits');
            const expectedUnit = mappingUnits[target.value];
            const unitSelect = target.closest('tr')?.querySelector('select[name$="[unit]"]');
            if (expectedUnit && unitSelect) {
                unitSelect.value = expectedUnit;
            }
        }
    });

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-add-import-line], [data-remove-import-line], .remove-import-line');
        const root = findRoot(button);
        if (!button || !root) {
            return;
        }

        if (button.matches('[data-remove-import-line], .remove-import-line')) {
            button.closest('tr')?.remove();
            updateLineCount(root);
            return;
        }

        const body = root.querySelector('[data-import-lines]');
        const template = document.getElementById('import-line-template');
        if (!body || !(template instanceof HTMLTemplateElement)) {
            return;
        }
        const existingNumbers = Array.from(body.querySelectorAll('input[name$="[number]"]'))
            .map((input) => Number.parseInt(input.value, 10))
            .filter(Number.isFinite);
        const number = (existingNumbers.length > 0 ? Math.max(...existingNumbers) : 0) + 1;
        const index = Number.parseInt(root.dataset.nextLineIndex || '0', 10);
        root.dataset.nextLineIndex = String(index + 1);
        body.insertAdjacentHTML(
            'beforeend',
            template.innerHTML.replaceAll('__INDEX__', String(index)).replaceAll('__NUMBER__', String(number)),
        );
        const row = body.lastElementChild;
        if (row) {
            replaceUnitInput(root, row);
            row.querySelector('input[name$="[description]"]')?.focus();
        }
        updateLineCount(root);
    });
})();
