import {Controller} from '@hotwired/stimulus';
import Sortable from 'sortablejs';
import {AlertSwal, ConfirmSwal} from '../../helpers/swal';

const TYPE_META = {
    heading: {label: 'Überschrift', icon: 'fa-heading'},
    static_text: {label: 'Fester Text', icon: 'fa-align-left'},
    editable_note: {label: 'Freie Notiz', icon: 'fa-note-sticky'},
    value: {label: 'Zugeordneter Wert', icon: 'fa-database'},
    child_table: {label: 'Komponententabelle', icon: 'fa-table'},
    separator: {label: 'Trennlinie', icon: 'fa-minus'},
    spacer: {label: 'Leerraum', icon: 'fa-arrows-up-down'},
    page_break: {label: 'Seitenumbruch', icon: 'fa-file-arrow-down'},
};

const VISIBLE_SETTINGS = {
    heading: ['label', 'typography', 'layout'],
    static_text: ['label', 'text', 'typography', 'layout'],
    editable_note: ['label', 'text', 'typography', 'layout', 'behavior', 'required'],
    value: ['label', 'source', 'typography', 'layout', 'behavior', 'required', 'hide'],
    child_table: ['label', 'typography', 'layout', 'behavior', 'required', 'hide', 'table'],
    separator: ['layout'],
    spacer: ['spacing'],
    page_break: [],
};

const WIDTH_PERCENT = {3: 25, 6: 50, 9: 75, 12: 100};

/**
 * Purpose-built, schema-backed datasheet designer.
 *
 * The canvas never accepts user-supplied HTML. All visible nodes are created
 * here and all persisted properties are validated again by the server.
 */
export default class extends Controller {
    static values = {previewUrl: String};
    static targets = [
        'blockInspector',
        'canvas',
        'catalog',
        'documentInspector',
        'documentLayer',
        'footerTitle',
        'inspectorTitle',
        'layers',
        'pageTitle',
        'payload',
        'rootSource',
        'headerSource',
        'status',
        'tableRows',
        'previewButton', 'previewPanel', 'previewFrame', 'previewLink', 'workspace',
    ];

    connect() {
        try {
            this.state = JSON.parse(this.payloadTarget.value);
            this.catalogData = JSON.parse(this.catalogTarget.value);
        } catch (error) {
            AlertSwal.fire({text: 'Der Datenblatteditor konnte nicht geladen werden. Bitte lade die Seite neu.'});
            throw error;
        }

        this.selectedId = null;
        this.submitting = false;
        // Turbo may restore a cached DOM whose old blob URL has been revoked.
        this.previewButtonTarget.disabled = false;
        this.previewButtonTarget.removeAttribute('aria-busy');
        this.previewFrameTarget.removeAttribute('src');
        this.previewLinkTarget.removeAttribute('href');
        this.assignEditorIds();
        this.populateSourceSelect(this.rootSourceTarget, this.catalogData.root, 'Datenquelle auswählen');
        this.populateSourceSelect(this.headerSourceTarget, this.catalogData.child, 'Automatisch: Einbauplatzbezeichnung');
        this.savedPayload = this.serializeState();
        this.boundBeforeUnload = this.beforeUnload.bind(this);
        window.addEventListener('beforeunload', this.boundBeforeUnload);

        this.initializeSortables();
        this.renderAll();
        this.selectDocument();
    }

    disconnect() {
        this.previewRequest?.abort();
        if (this.previewObjectUrl) URL.revokeObjectURL(this.previewObjectUrl);
        window.removeEventListener('beforeunload', this.boundBeforeUnload);
        this.layerSortable?.destroy();
        this.canvasSortable?.destroy();
        this.tableRowsSortable?.destroy();
    }

    addBlock(event) {
        if (this.state.blocks.length >= 100) {
            AlertSwal.fire({text: 'Eine Vorlage darf höchstens 100 Bausteine enthalten.'});
            return;
        }

        const type = event.currentTarget.dataset.blockType;
        if (!TYPE_META[type]) {
            return;
        }
        const block = this.createBlock(type);
        this.state.blocks.push(block);
        this.selectedId = block.editorId;
        this.changed();
        this.renderAll();
        this.findCanvasBlock(block.editorId)?.scrollIntoView({behavior: 'smooth', block: 'center'});
    }

    selectDocument(event) {
        event?.stopPropagation();
        this.closePreview();
        this.selectedId = null;
        this.renderSelection();
        this.renderInspector();
        if (event) {
            this.documentInspectorTarget.scrollIntoView({behavior: 'smooth', block: 'nearest'});
            this.documentInspectorTarget.querySelector('input')?.focus({preventScroll: true});
        }
    }

    beforePublish(event) {
        if (this.serializeState() !== this.savedPayload) {
            event.preventDefault();
            AlertSwal.fire({icon: 'warning', title: 'Entwurf zuerst speichern', text: 'Bitte speichere und prüfe deine Änderungen vor der Veröffentlichung.'});
        }
    }

    selectBlock(event) {
        event.stopPropagation();
        this.selectedId = event.currentTarget.dataset.editorId;
        this.renderSelection();
        this.renderInspector();
    }

    canvasBackgroundClicked(event) {
        if (!event.target.closest('[data-editor-id]')) {
            this.selectDocument(event);
        }
    }

    documentChanged(event) {
        const property = event.currentTarget.dataset.documentProperty;
        if (!['productTitle', 'changeNote'].includes(property)) {
            return;
        }
        this.state[property] = event.currentTarget.value;
        this.pageTitleTarget.textContent = this.state.productTitle || 'Product Data Sheet';
        this.footerTitleTarget.textContent = this.state.productTitle || 'Product Data Sheet';
        this.changed();
    }

    blockChanged(event) {
        const block = this.selectedBlock();
        const property = event.currentTarget.dataset.blockProperty;
        if (!block || !property) {
            return;
        }

        let value;
        if (event.currentTarget.type === 'checkbox') {
            value = event.currentTarget.checked;
        } else if (event.currentTarget.type === 'number') {
            const parsedValue = Number.parseInt(event.currentTarget.value, 10);
            if (event.currentTarget.hasAttribute('data-nullable-number') && event.currentTarget.value === '') {
                value = null;
            } else {
                value = Number.isNaN(parsedValue) ? 0 : parsedValue;
            }
        } else {
            value = event.currentTarget.value || (['sourcePath', 'headerSourcePath'].includes(property) ? null : '');
        }
        block[property] = value;
        if (property === 'type' && value === 'spacer') {
            block.layoutColumns = 12;
            block.startNewRow = true;
            block.required = false;
            block.hideIfEmpty = false;
        }
        this.changed();
        this.renderCanvas();
        this.renderLayers();

        if (property === 'type') {
            this.renderInspector();
        }
    }

    toggleBlockProperty(event) {
        const block = this.selectedBlock();
        const property = event.currentTarget.dataset.toggleProperty;
        if (!block || !property) {
            return;
        }
        block[property] = !block[property];
        this.changed();
        this.renderCanvas();
        this.renderInspectorButtons(block);
    }

    chooseBlockProperty(event) {
        const block = this.selectedBlock();
        const property = event.currentTarget.dataset.choiceProperty;
        if (!block || !property) {
            return;
        }
        const rawValue = event.currentTarget.dataset.choiceValue;
        block[property] = property === 'layoutColumns' ? Number.parseInt(rawValue, 10) : rawValue;
        this.changed();
        this.renderCanvas();
        this.renderInspectorButtons(block);
    }

    removeSelectedBlock() {
        const block = this.selectedBlock();
        if (!block) {
            return;
        }
        ConfirmSwal.fire({
            titleText: 'Baustein löschen',
            text: 'Diesen Baustein mit seinen Einstellungen aus dem Entwurf entfernen?',
        }).then(({isConfirmed}) => {
            if (!isConfirmed) {
                return;
            }
            this.state.blocks = this.state.blocks.filter((candidate) => candidate.editorId !== block.editorId);
            this.selectedId = null;
            this.changed();
            this.renderAll();
            this.selectDocument();
        });
    }

    addTableRow() {
        const block = this.selectedBlock();
        if (!block || block.type !== 'child_table') {
            return;
        }
        if (block.columns.length >= 100) {
            AlertSwal.fire({text: 'Eine Tabelle darf höchstens 100 Datenzeilen enthalten.'});
            return;
        }
        block.columns.push({
            key: null,
            editorId: this.uid(),
            label: 'Value',
            sourcePath: null,
            unit: '',
            required: false,
        });
        this.changed();
        this.renderCanvas();
        this.renderTableRows(block);
    }

    prepareSubmit(event) {
        this.payloadTarget.value = this.serializeState();
        if (!this.state.productTitle.trim()) {
            event.preventDefault();
            this.selectDocument();
            AlertSwal.fire({text: 'Die englische Produktüberschrift darf nicht leer sein.'});
            return;
        }
        this.submitting = true;
    }

    async previewPdf() {
        if (this.previewButtonTarget.disabled) return;
        this.previewButtonTarget.disabled = true;
        this.previewButtonTarget.setAttribute('aria-busy', 'true');
        this.previewRequest = new AbortController();
        try {
            const data = new FormData();
            data.set('_token', this.element.querySelector('input[name="_token"]').value);
            data.set('editor_payload', this.serializeState());
            const response = await fetch(this.previewUrlValue, {
                method: 'POST', body: data, credentials: 'same-origin',
                signal: this.previewRequest.signal,
            });
            if (!response.ok || !response.headers.get('content-type')?.includes('application/pdf')) {
                const error = response.headers.get('content-type')?.includes('application/json') ? await response.json() : null;
                throw new Error(error?.error || 'Die PDF-Vorschau konnte nicht erstellt werden. Bitte prüfe deine Anmeldung und versuche es erneut.');
            }
            const blob = await response.blob();
            // The existing CSP permits data: PDF frames, not blob: frames.
            // Keep that policy intact; the blob URL is only used for opening
            // the PDF in a separate tab, never for embedded HTML.
            const frameUrl = await new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = () => resolve(reader.result);
                reader.onerror = () => reject(new Error('Die PDF-Vorschau konnte nicht geladen werden.'));
                reader.readAsDataURL(blob);
            });
            if (this.previewRequest.signal.aborted) return;
            if (this.previewObjectUrl) URL.revokeObjectURL(this.previewObjectUrl);
            this.previewObjectUrl = URL.createObjectURL(blob);
            this.previewFrameTarget.src = `${frameUrl}#view=FitH`;
            this.previewLinkTarget.href = this.previewObjectUrl;
            this.previewPanelTarget.hidden = false;
            this.workspaceTarget.hidden = true;
        } catch (error) {
            if (error.name !== 'AbortError') await AlertSwal.fire({text: error.message});
        } finally {
            if (this.element.isConnected) {
                this.previewButtonTarget.disabled = false;
                this.previewButtonTarget.removeAttribute('aria-busy');
            }
        }
    }

    closePreview() {
        this.previewPanelTarget.hidden = true;
        this.workspaceTarget.hidden = false;
    }

    beforeUnload(event) {
        if (!this.submitting && this.serializeState() !== this.savedPayload) {
            event.preventDefault();
            event.returnValue = '';
        }
    }

    initializeSortables() {
        const options = {
            animation: 150,
            ghostClass: 'datasheet-designer-sortable-ghost',
            handle: '.datasheet-designer-drag-handle',
        };
        this.layerSortable = Sortable.create(this.layersTarget, {
            ...options,
            onEnd: () => this.reorderBlocksFrom(this.layersTarget),
        });
        this.canvasSortable = Sortable.create(this.canvasTarget, {
            ...options,
            draggable: '[data-editor-id]',
            handle: '.datasheet-designer-canvas-handle',
            onEnd: () => this.reorderBlocksFrom(this.canvasTarget),
        });
    }

    reorderBlocksFrom(container) {
        const ids = [...container.querySelectorAll(':scope > [data-editor-id]')]
            .map((element) => element.dataset.editorId);
        if (ids.length !== this.state.blocks.length) {
            this.renderAll();
            return;
        }
        const blocks = new Map(this.state.blocks.map((block) => [block.editorId, block]));
        this.state.blocks = ids.map((id) => blocks.get(id));
        this.changed();
        this.renderAll();
    }

    renderAll() {
        this.pageTitleTarget.textContent = this.state.productTitle || 'Product Data Sheet';
        this.footerTitleTarget.textContent = this.state.productTitle || 'Product Data Sheet';
        this.renderLayers();
        this.renderCanvas();
        this.renderInspector();
        this.updateStatus();
    }

    renderLayers() {
        const fragment = document.createDocumentFragment();
        this.state.blocks.forEach((block, index) => {
            const item = document.createElement('li');
            item.className = 'datasheet-designer-layer';
            item.dataset.editorId = block.editorId;
            item.addEventListener('click', (event) => {
                if (event.target.closest('button')) {
                    return;
                }
                this.selectedId = block.editorId;
                this.renderSelection();
                this.renderInspector();
            });

            const handle = document.createElement('i');
            handle.className = 'fa-solid fa-grip-vertical datasheet-designer-drag-handle';
            handle.title = 'Ziehen, um anzuordnen';
            item.append(handle);

            const icon = document.createElement('i');
            icon.className = `fa-solid ${TYPE_META[block.type]?.icon || 'fa-shapes'} fa-fw`;
            item.append(icon);

            const label = document.createElement('span');
            label.className = 'datasheet-designer-layer-label flex-grow-1';
            label.textContent = this.blockDisplayName(block, index);
            item.append(label);

            const moveUp = this.iconButton('fa-arrow-up', 'Nach oben', () => this.moveBlock(block.editorId, -1));
            const moveDown = this.iconButton('fa-arrow-down', 'Nach unten', () => this.moveBlock(block.editorId, 1));
            item.append(moveUp, moveDown);
            fragment.append(item);
        });
        this.layersTarget.replaceChildren(fragment);
        this.renderSelection();
    }

    renderCanvas() {
        const fragment = document.createDocumentFragment();
        if (this.state.blocks.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'datasheet-designer-empty';
            empty.textContent = 'Die Vorlage ist leer. Wähle links einen Baustein aus.';
            fragment.append(empty);
        }

        this.state.blocks.forEach((block) => {
            if (block.startNewRow) {
                const clear = document.createElement('div');
                clear.className = 'datasheet-clear';
                fragment.append(clear);
            }
            fragment.append(this.createCanvasBlock(block));
        });
        const clear = document.createElement('div');
        clear.className = 'datasheet-clear';
        fragment.append(clear);
        this.canvasTarget.replaceChildren(fragment);
        this.renderSelection();
    }

    createCanvasBlock(block) {
        const container = document.createElement(block.type === 'page_break' ? 'div' : 'section');
        container.dataset.editorId = block.editorId;
        container.addEventListener('click', (event) => {
            event.stopPropagation();
            this.selectedId = block.editorId;
            this.renderSelection();
            this.renderInspector();
        });

        if (block.type === 'page_break') {
            container.className = 'datasheet-designer-page-break-preview datasheet-designer-canvas-block';
            const handle = document.createElement('span');
            handle.className = 'datasheet-designer-canvas-handle';
            handle.title = 'Ziehen, um anzuordnen';
            const handleIcon = document.createElement('i');
            handleIcon.className = 'fa-solid fa-grip-vertical';
            handle.append(handleIcon);
            container.append(handle, document.createTextNode('Page break'));
            return container;
        }

        container.className = [
            'datasheet-block',
            block.layoutColumns === 12 ? 'datasheet-block-full' : '',
            'datasheet-designer-canvas-block',
            `datasheet-block-${block.type}`,
            `datasheet-text-size-${block.textSize}`,
            `datasheet-font-${block.fontFamily}`,
            `datasheet-align-${block.textAlignment}`,
            block.textBold ? 'datasheet-text-bold' : '',
            block.textItalic ? 'datasheet-text-italic' : '',
            block.textUnderlined ? 'datasheet-text-underlined' : '',
        ].filter(Boolean).join(' ');
        container.style.width = `${WIDTH_PERCENT[block.layoutColumns] || 100}%`;

        const inner = document.createElement('div');
        inner.className = 'datasheet-block-inner';
        const handle = document.createElement('span');
        handle.className = 'datasheet-designer-canvas-handle';
        handle.title = 'Ziehen, um anzuordnen';
        const handleIcon = document.createElement('i');
        handleIcon.className = 'fa-solid fa-grip-vertical';
        handle.append(handleIcon);
        inner.append(handle);

        switch (block.type) {
            case 'heading':
                inner.append(this.textElement('h2', block.label || 'New heading'));
                break;
            case 'static_text':
                this.appendOptionalHeading(inner, block.label);
                inner.append(this.textElement('div', block.text || 'Text', 'datasheet-text'));
                break;
            case 'editable_note':
                this.appendOptionalHeading(inner, block.label);
                inner.append(this.textElement('div', block.text || 'This note can be completed when the data sheet is generated.', 'datasheet-note'));
                break;
            case 'value':
                inner.append(this.textElement('div', block.label || 'Value', 'datasheet-value-label'));
                inner.append(this.textElement('div', block.sourcePath ? `{${this.describeSource(block.sourcePath)}}` : '—', 'datasheet-value'));
                break;
            case 'child_table':
                this.appendOptionalHeading(inner, block.label);
                inner.append(this.createPreviewTable(block));
                break;
            case 'separator': {
                const line = document.createElement('hr');
                inner.append(line);
                break;
            }
            case 'spacer': {
                const lines = {small: 1, normal: 2, large: 3, extra_large: 4}[block.textSize] || 1;
                inner.append(this.textElement('div', `Leerraum · ${lines} ${lines === 1 ? 'Zeile' : 'Zeilen'}`, 'datasheet-spacer'));
                break;
            }
        }
        container.append(inner);
        return container;
    }

    createPreviewTable(block) {
        const table = document.createElement('table');
        table.className = 'datasheet-table';
        const head = document.createElement('thead');
        const headerRow = document.createElement('tr');
        headerRow.append(document.createElement('th'));
        const previewColumns = Math.max(1, Math.min(12, block.minimumRows));
        for (let index = 1; index <= previewColumns; index++) {
            const value = block.headerSourcePath ? `{${this.describeSource(block.headerSourcePath)}}` : `Installation slot ${index}`;
            headerRow.append(this.textElement('th', (block.headerFormat ?? '{value}').replace('{value}', value)));
        }
        head.append(headerRow);
        table.append(head);

        const body = document.createElement('tbody');
        if (block.columns.length === 0) {
            const row = document.createElement('tr');
            const cell = this.textElement('td', 'No table rows configured.');
            cell.colSpan = previewColumns + 1;
            row.append(cell);
            body.append(row);
        } else {
            block.columns.forEach((definition) => {
                const row = document.createElement('tr');
                const label = definition.unit ? `${definition.label || 'Value'} [${definition.unit}]` : (definition.label || 'Value');
                row.append(this.textElement('th', label));
                for (let index = 0; index < previewColumns; index++) {
                    row.append(this.textElement('td', definition.sourcePath ? `{${this.describeSource(definition.sourcePath)}}` : '—'));
                }
                body.append(row);
            });
        }
        table.append(body);
        return table;
    }

    renderInspector() {
        const block = this.selectedBlock();
        this.documentInspectorTarget.hidden = Boolean(block);
        this.blockInspectorTarget.hidden = !block;

        if (!block) {
            this.element.querySelectorAll('[data-document-property]').forEach((field) => {
                field.value = this.state[field.dataset.documentProperty] ?? '';
            });
            return;
        }

        this.inspectorTitleTarget.textContent = TYPE_META[block.type]?.label || 'Baustein';
        this.blockInspectorTarget.querySelectorAll('[data-block-property]').forEach((field) => {
            const property = field.dataset.blockProperty;
            if (field.type === 'checkbox') {
                field.checked = Boolean(block[property]);
            } else {
                field.value = block[property] ?? '';
            }
        });
        const visible = VISIBLE_SETTINGS[block.type] || [];
        this.blockInspectorTarget.querySelectorAll('[data-designer-setting]').forEach((element) => {
            element.hidden = !visible.includes(element.dataset.designerSetting);
        });
        this.renderInspectorButtons(block);
        this.renderTableRows(block);
    }

    renderInspectorButtons(block) {
        this.blockInspectorTarget.querySelectorAll('[data-toggle-property]').forEach((button) => {
            const active = Boolean(block[button.dataset.toggleProperty]);
            button.classList.toggle('active', active);
            button.setAttribute('aria-pressed', String(active));
        });
        this.blockInspectorTarget.querySelectorAll('[data-choice-property]').forEach((button) => {
            const active = String(block[button.dataset.choiceProperty]) === button.dataset.choiceValue;
            button.classList.toggle('active', active);
            button.setAttribute('aria-pressed', String(active));
        });
    }

    renderTableRows(block) {
        this.tableRowsSortable?.destroy();
        this.tableRowsSortable = null;
        if (block.type !== 'child_table') {
            this.tableRowsTarget.replaceChildren();
            return;
        }

        const fragment = document.createDocumentFragment();
        block.columns.forEach((row, index) => fragment.append(this.createTableRowEditor(block, row, index)));
        this.tableRowsTarget.replaceChildren(fragment);
        this.tableRowsSortable = Sortable.create(this.tableRowsTarget, {
            animation: 150,
            ghostClass: 'datasheet-designer-sortable-ghost',
            handle: '.datasheet-designer-drag-handle',
            onEnd: () => {
                const ids = [...this.tableRowsTarget.querySelectorAll(':scope > [data-row-editor-id]')]
                    .map((element) => element.dataset.rowEditorId);
                const rows = new Map(block.columns.map((row) => [row.editorId, row]));
                block.columns = ids.map((id) => rows.get(id));
                this.changed();
                this.renderCanvas();
                this.renderTableRows(block);
            },
        });
    }

    createTableRowEditor(block, row, index) {
        const container = document.createElement('div');
        container.className = 'datasheet-designer-table-row';
        container.dataset.rowEditorId = row.editorId;

        const header = document.createElement('div');
        header.className = 'datasheet-designer-table-row-header';
        const handle = document.createElement('i');
        handle.className = 'fa-solid fa-grip-vertical datasheet-designer-drag-handle';
        const title = document.createElement('strong');
        title.className = 'small me-auto';
        title.textContent = `Datenzeile ${index + 1}`;
        const remove = this.iconButton('fa-trash', 'Datenzeile löschen', () => {
            ConfirmSwal.fire({titleText: 'Datenzeile löschen', text: 'Diese Datenzeile aus der Tabelle entfernen?'}).then(({isConfirmed}) => {
                if (!isConfirmed) {
                    return;
                }
                block.columns = block.columns.filter((candidate) => candidate.editorId !== row.editorId);
                this.changed();
                this.renderCanvas();
                this.renderTableRows(block);
            });
        }, 'btn-outline-danger');
        header.append(handle, title, remove);
        container.append(header);

        container.append(this.rowTextField('Englische Zeilenbezeichnung', row.label, 255, (value) => {
            row.label = value;
            this.changed();
            this.renderCanvas();
        }));

        const sourceField = document.createElement('div');
        sourceField.className = 'datasheet-designer-field';
        sourceField.append(this.textElement('label', 'Wert je eingebauter Instanz', 'form-label'));
        const source = document.createElement('select');
        source.className = 'form-select';
        this.populateSourceSelect(source, this.catalogData.child, 'Datenquelle auswählen');
        source.value = row.sourcePath ?? '';
        source.addEventListener('change', () => {
            row.sourcePath = source.value || null;
            this.changed();
            this.renderCanvas();
        });
        sourceField.append(source);
        container.append(sourceField);

        container.append(this.rowTextField('Einheit', row.unit, 32, (value) => {
            row.unit = value;
            this.changed();
            this.renderCanvas();
        }));

        const check = document.createElement('div');
        check.className = 'form-check';
        const input = document.createElement('input');
        input.className = 'form-check-input';
        input.type = 'checkbox';
        input.id = `datasheet-row-required-${row.editorId}`;
        input.checked = row.required;
        input.addEventListener('change', () => {
            row.required = input.checked;
            this.changed();
        });
        const label = this.textElement('label', 'Für die Freigabe erforderlich', 'form-check-label');
        label.htmlFor = input.id;
        check.append(input, label);
        container.append(check);
        return container;
    }

    rowTextField(labelText, value, maxLength, changeHandler) {
        const wrapper = document.createElement('div');
        wrapper.className = 'datasheet-designer-field';
        const label = this.textElement('label', labelText, 'form-label');
        const input = document.createElement('input');
        input.className = 'form-control';
        input.maxLength = maxLength;
        input.type = 'text';
        input.value = value ?? '';
        input.addEventListener('input', () => changeHandler(input.value));
        wrapper.append(label, input);
        return wrapper;
    }

    renderSelection() {
        this.documentLayerTarget.classList.toggle('active', this.selectedId === null);
        this.layersTarget.querySelectorAll('[data-editor-id]').forEach((element) => {
            element.classList.toggle('active', element.dataset.editorId === this.selectedId);
        });
        this.canvasTarget.querySelectorAll('[data-editor-id]').forEach((element) => {
            element.classList.toggle('is-selected', element.dataset.editorId === this.selectedId);
        });
    }

    moveBlock(editorId, direction) {
        const index = this.state.blocks.findIndex((block) => block.editorId === editorId);
        const target = index + direction;
        if (index < 0 || target < 0 || target >= this.state.blocks.length) {
            return;
        }
        [this.state.blocks[index], this.state.blocks[target]] = [this.state.blocks[target], this.state.blocks[index]];
        this.changed();
        this.renderAll();
    }

    selectedBlock() {
        return this.state.blocks.find((block) => block.editorId === this.selectedId) ?? null;
    }

    findCanvasBlock(editorId) {
        return [...this.canvasTarget.querySelectorAll('[data-editor-id]')]
            .find((element) => element.dataset.editorId === editorId);
    }

    createBlock(type) {
        const defaults = {
            heading: {label: 'New heading'},
            static_text: {label: '', text: 'Text'},
            editable_note: {label: 'Additional information', text: ''},
            value: {label: 'Value'},
            child_table: {label: 'Installed components'},
            separator: {},
            spacer: {},
            page_break: {},
        }[type];

        return {
            key: null,
            editorId: this.uid(),
            type,
            label: defaults.label ?? '',
            text: defaults.text ?? '',
            sourcePath: null,
            headerSourcePath: null,
            headerFormat: '{value}',
            textSize: type === 'spacer' ? 'small' : 'normal',
            fontFamily: 'sans_serif',
            textAlignment: 'left',
            textBold: false,
            textItalic: false,
            textUnderlined: false,
            layoutColumns: 12,
            startNewRow: type === 'spacer',
            required: false,
            hideIfEmpty: false,
            minimumRows: 0,
            maximumRows: null,
            columns: [],
        };
    }

    assignEditorIds() {
        this.state.blocks.forEach((block) => {
            block.editorId = block.key || this.uid();
            block.columns.forEach((row) => {
                row.editorId = row.key || this.uid();
            });
        });
    }

    serializeState() {
        return JSON.stringify({
            schemaVersion: 1,
            baseRevisionHash: this.state.baseRevisionHash,
            productTitle: this.state.productTitle,
            changeNote: this.state.changeNote,
            blocks: this.state.blocks.map((block) => ({
                key: block.key,
                type: block.type,
                label: block.label,
                text: block.text,
                sourcePath: block.sourcePath,
                headerSourcePath: block.headerSourcePath,
                headerFormat: block.headerFormat,
                textSize: block.textSize,
                fontFamily: block.fontFamily,
                textAlignment: block.textAlignment,
                textBold: block.textBold,
                textItalic: block.textItalic,
                textUnderlined: block.textUnderlined,
                layoutColumns: block.layoutColumns,
                startNewRow: block.startNewRow,
                required: block.required,
                hideIfEmpty: block.hideIfEmpty,
                minimumRows: block.minimumRows,
                maximumRows: block.maximumRows,
                columns: block.columns.map((row) => ({
                    key: row.key,
                    label: row.label,
                    sourcePath: row.sourcePath,
                    unit: row.unit,
                    required: row.required,
                })),
            })),
        });
    }

    populateSourceSelect(select, groups, placeholder) {
        select.replaceChildren();
        const empty = document.createElement('option');
        empty.value = '';
        empty.textContent = placeholder;
        select.append(empty);
        Object.entries(groups).forEach(([groupLabel, choices]) => {
            const group = document.createElement('optgroup');
            group.label = groupLabel;
            Object.entries(choices).forEach(([label, value]) => {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = label;
                group.append(option);
            });
            select.append(group);
        });
    }

    describeSource(path) {
        for (const groups of [this.catalogData.root, this.catalogData.child]) {
            for (const [group, choices] of Object.entries(groups)) {
                for (const [label, value] of Object.entries(choices)) {
                    if (value === path) {
                        return `${group} · ${label}`;
                    }
                }
            }
        }
        return 'Unknown source';
    }

    blockDisplayName(block, index) {
        const type = TYPE_META[block.type]?.label || 'Baustein';
        const content = block.label || (block.type === 'static_text' ? block.text : '');
        return content ? `${type}: ${content}` : `${index + 1}. ${type}`;
    }

    appendOptionalHeading(parent, text) {
        if (text) {
            parent.append(this.textElement('h3', text));
        }
    }

    textElement(tagName, text, className = '') {
        const element = document.createElement(tagName);
        element.textContent = text;
        if (className) {
            element.className = className;
        }
        return element;
    }

    iconButton(icon, title, handler, variant = 'btn-outline-secondary') {
        const button = document.createElement('button');
        button.className = `btn btn-sm ${variant}`;
        button.type = 'button';
        button.title = title;
        const image = document.createElement('i');
        image.className = `fa-solid ${icon}`;
        button.append(image);
        button.addEventListener('click', (event) => {
            event.stopPropagation();
            handler();
        });
        return button;
    }

    changed() {
        this.payloadTarget.value = this.serializeState();
        this.updateStatus();
    }

    updateStatus() {
        const dirty = this.serializeState() !== this.savedPayload;
        this.statusTarget.textContent = dirty ? 'Nicht gespeicherte Änderungen' : 'Alle Änderungen gespeichert';
        this.statusTarget.classList.toggle('is-dirty', dirty);
        this.statusTarget.classList.toggle('is-clean', !dirty);
    }

    uid() {
        if (globalThis.crypto?.randomUUID) {
            return `new-${globalThis.crypto.randomUUID()}`;
        }
        return `new-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
    }
}
