import {Controller} from '@hotwired/stimulus';

/**
 * Presents the four constrained layout widths as a compact button group while
 * retaining the native select as a no-JavaScript fallback and form value.
 */
export default class extends Controller {
    connect() {
        if (!(this.element instanceof HTMLSelectElement)) {
            return;
        }

        this.group = document.createElement('div');
        this.group.className = 'btn-group w-100';
        this.group.setAttribute('role', 'group');
        this.group.setAttribute('aria-label', this.element.labels.item(0)?.textContent?.trim() || 'Layout width');

        for (const option of this.element.options) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-outline-primary';
            button.textContent = option.textContent.trim();
            button.dataset.value = option.value;
            button.addEventListener('click', this.select);
            this.group.append(button);
        }

        this.element.addEventListener('change', this.render);
        this.element.before(this.group);
        this.element.hidden = true;
        this.render();
    }

    disconnect() {
        this.element.removeEventListener('change', this.render);
        for (const button of this.group?.querySelectorAll('button') ?? []) {
            button.removeEventListener('click', this.select);
        }
        this.group?.remove();
        this.element.hidden = false;
    }

    select = (event) => {
        this.element.value = event.currentTarget.dataset.value;
        this.element.dispatchEvent(new Event('change', {bubbles: true}));
    };

    render = () => {
        for (const button of this.group.querySelectorAll('button')) {
            const selected = button.dataset.value === this.element.value;
            button.classList.toggle('active', selected);
            button.setAttribute('aria-pressed', selected ? 'true' : 'false');
        }
    };
}
