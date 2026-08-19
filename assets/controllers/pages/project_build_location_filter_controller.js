/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 */

import {Controller} from '@hotwired/stimulus';
import TristateCheckbox from '../../js/lib/TristateCheckbox';

export default class extends Controller {
    static targets = [
        'defaultCheckbox',
        'defaultInput',
        'defaultLabel',
        'row',
        'state',
        'toggleIcon',
        'unassignedRow',
    ];

    connect() {
        this.expanded = new Set();
        this.snapshot = null;
        this.applied = false;

        this.stateTargets.forEach((input) => {
            TristateCheckbox.getInstance(input);
            input.addEventListener('click', () => queueMicrotask(() => this.refreshEffectiveStates()));
        });

        this.element.addEventListener('show.bs.modal', () => this.rememberState());
        this.element.addEventListener('hidden.bs.modal', () => {
            if (!this.applied) {
                this.restoreState();
            }
            this.applied = false;
        });

        this.refreshTree();
        this.refreshEffectiveStates();
    }

    changeDefault() {
        this.defaultInputTarget.value = this.defaultCheckboxTarget.checked ? 'true' : 'false';
        this.refreshEffectiveStates();
    }

    toggle(event) {
        const locationId = event.currentTarget.dataset.locationId;
        if (this.expanded.has(locationId)) {
            this.expanded.delete(locationId);
        } else {
            this.expanded.add(locationId);
        }
        this.refreshTree();
    }

    expandAll() {
        this.toggleIconTargets.forEach((icon) => {
            const button = icon.closest('button[data-location-id]');
            if (button) {
                this.expanded.add(button.dataset.locationId);
            }
        });
        this.refreshTree();
    }

    collapseAll() {
        this.expanded.clear();
        this.refreshTree();
    }

    invert() {
        this.defaultCheckboxTarget.checked = !this.defaultCheckboxTarget.checked;
        this.defaultInputTarget.value = this.defaultCheckboxTarget.checked ? 'true' : 'false';

        this.stateTargets.forEach((input) => {
            const tristate = TristateCheckbox.getInstance(input);
            if (tristate.state !== null) {
                tristate.state = !tristate.state;
            }
        });

        this.refreshEffectiveStates();
    }

    apply(event) {
        event.preventDefault();
        this.applied = true;

        // Build a compact URL containing only explicit values. The unmodified
        // HTML form remains a functional (albeit verbose) fallback without JS.
        const form = event.currentTarget;
        const url = new URL(form.action, window.location.origin);
        const buildsInput = form.querySelector('input[name="n"]');
        url.searchParams.set('n', buildsInput.value);
        url.searchParams.set('location_filter[default]', this.defaultInputTarget.value);

        this.stateTargets.forEach((input) => {
            const tristate = TristateCheckbox.getInstance(input);
            if (tristate.state === null) {
                return;
            }

            const locationId = input.dataset.locationId;
            const parameter = locationId === 'unassigned'
                ? 'location_filter[unassigned]'
                : `location_filter[locations][${locationId}]`;
            url.searchParams.set(parameter, tristate.state ? 'true' : 'false');
        });

        if (window.Turbo) {
            window.Turbo.visit(url.toString());
        } else {
            window.location.assign(url.toString());
        }
    }

    rememberState() {
        this.applied = false;
        this.snapshot = {
            defaultAllowed: this.defaultCheckboxTarget.checked,
            states: this.stateTargets.map((input) => TristateCheckbox.getInstance(input).state),
        };
    }

    restoreState() {
        if (!this.snapshot) {
            return;
        }

        this.defaultCheckboxTarget.checked = this.snapshot.defaultAllowed;
        this.defaultInputTarget.value = this.snapshot.defaultAllowed ? 'true' : 'false';
        this.stateTargets.forEach((input, index) => {
            TristateCheckbox.getInstance(input).state = this.snapshot.states[index];
        });
        this.refreshEffectiveStates();
    }

    refreshTree() {
        const visible = new Map();

        this.rowTargets.forEach((row) => {
            const parentId = row.dataset.parentId;
            const isVisible = !parentId || (visible.get(parentId) === true && this.expanded.has(parentId));
            visible.set(row.dataset.locationId, isVisible);
            // Both Bootstrap utilities use !important. Keeping d-flex on a
            // hidden row can therefore override d-none depending on bundle
            // order, so make the classes mutually exclusive.
            row.classList.toggle('d-flex', isVisible);
            row.classList.toggle('d-none', !isVisible);
        });

        this.toggleIconTargets.forEach((icon) => {
            const button = icon.closest('button[data-location-id]');
            const isExpanded = button && this.expanded.has(button.dataset.locationId);
            icon.classList.toggle('fa-chevron-right', !isExpanded);
            icon.classList.toggle('fa-chevron-down', isExpanded);
            if (button) {
                button.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
            }
        });
    }

    refreshEffectiveStates() {
        const defaultAllowed = this.defaultCheckboxTarget.checked;
        const effectiveStates = new Map();

        this.defaultLabelTarget.textContent = defaultAllowed
            ? this.element.dataset.allowedLabel
            : this.element.dataset.deniedLabel;

        this.rowTargets.forEach((row) => {
            const parentId = row.dataset.parentId;
            const inherited = parentId && effectiveStates.has(parentId)
                ? effectiveStates.get(parentId)
                : defaultAllowed;
            const input = row.querySelector('input.tristate');
            const explicit = TristateCheckbox.getInstance(input).state;
            const effective = explicit === null ? inherited : explicit;
            effectiveStates.set(row.dataset.locationId, effective);
            this.markEffectiveState(row, effective);
        });

        const unassignedInput = this.unassignedRowTarget.querySelector('input.tristate');
        const unassignedState = TristateCheckbox.getInstance(unassignedInput).state;
        this.markEffectiveState(
            this.unassignedRowTarget,
            unassignedState === null ? defaultAllowed : unassignedState,
        );
    }

    markEffectiveState(row, allowed) {
        const icon = row.querySelector('[data-pages--project-build-location-filter-target="statusIcon"]');
        if (!icon) {
            return;
        }

        icon.classList.toggle('fa-circle-check', allowed);
        icon.classList.toggle('text-success', allowed);
        icon.classList.toggle('fa-circle-xmark', !allowed);
        icon.classList.toggle('text-danger', !allowed);
        row.dataset.effectiveState = allowed ? 'allowed' : 'denied';
    }
}
