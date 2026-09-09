import {Controller} from '@hotwired/stimulus';

const states = {
    pass: {
        buttonClass: 'btn-success',
        iconClass: 'fa-solid fa-check',
    },
    not_applicable: {
        buttonClass: 'btn-warning',
        iconClass: 'fa-solid fa-minus',
    },
    fail: {
        buttonClass: 'btn-danger',
        iconClass: 'fa-solid fa-xmark',
    },
};

const stateOrder = Object.keys(states);

/**
 * Replaces a regular test-result select with one keyboard-accessible button.
 * The original select remains the submitted form control and is restored if
 * Stimulus disconnects, so the form still works without JavaScript.
 */
export default class extends Controller {
    connect() {
        if (!(this.element instanceof HTMLSelectElement)) {
            return;
        }

        this.button = document.createElement('button');
        this.button.type = 'button';
        this.button.className = 'btn btn-sm w-100 text-start';
        this.button.addEventListener('click', this.cycle);
        this.element.before(this.button);
        this.element.hidden = true;
        this.render();
    }

    disconnect() {
        this.button?.removeEventListener('click', this.cycle);
        this.button?.remove();
        this.element.hidden = false;
    }

    cycle = () => {
        const currentIndex = stateOrder.indexOf(this.element.value);
        this.element.value = stateOrder[(currentIndex + 1) % stateOrder.length];
        this.element.dispatchEvent(new Event('change', {bubbles: true}));
        this.render();
    };

    render() {
        const state = states[this.element.value];
        const selectedOption = this.element.selectedOptions.item(0);
        const label = selectedOption?.textContent?.trim() || '–';
        const icon = document.createElement('i');
        const text = document.createElement('span');

        this.button.classList.remove('btn-outline-secondary', ...Object.values(states).map((item) => item.buttonClass));
        this.button.classList.add(state?.buttonClass ?? 'btn-outline-secondary');
        icon.className = `${state?.iconClass ?? 'fa-regular fa-circle'} fa-fw me-2`;
        text.textContent = label;
        this.button.replaceChildren(icon, text);
        this.button.setAttribute('aria-label', label);
        this.button.title = label;
    }
}
