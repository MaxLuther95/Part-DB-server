import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.prefix = this.element.querySelector('[data-serial-prefix]');
        this.number = this.element.querySelector('[data-serial-number]');
        this.confirmed = this.element.querySelector('[data-serial-confirmed]');
        this.content = this.element.closest('form')?.querySelector('[data-serial-content]');
        this.reset = () => { this.confirmed.checked = false; };
        this.changed = () => this.suggest();
        this.prefix.addEventListener('input', this.reset);
        this.number.addEventListener('input', this.reset);
        this.content?.addEventListener('change', this.changed);
        if (!this.number.value) this.suggest();
    }

    async suggest() {
        const option = this.content?.selectedOptions[0];
        const url = option?.dataset.serialSuggestion;
        if (!url) return;
        this.abort?.abort();
        this.abort = new AbortController();
        this.reset();
        try {
            const response = await fetch(url, { signal: this.abort.signal, headers: { Accept: 'application/json' } });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Der Vorschlag konnte nicht geladen werden.');
            this.prefix.value = data.prefix;
            // Never overwrite a manually entered number on a build-type change.
            if (!this.number.value || this.number.value === this.lastSuggestion) this.number.value = data.number;
            this.lastSuggestion = data.number;
            this.reset();
            this.element.querySelector('[data-serial-hint]').textContent = data.prefix
                ? 'Vorschlag ist nicht reserviert. Nummer prüfen und bestätigen.'
                : 'Kein Nummernkreis zugeordnet. Ohne Präfix manuell eintragen oder zuerst einen Kreis zuordnen.';
        } catch (error) {
            if (error.name !== 'AbortError') this.element.querySelector('[data-serial-hint]').textContent = error.message;
        }
    }

    disconnect() {
        this.abort?.abort();
        this.prefix.removeEventListener('input', this.reset);
        this.number.removeEventListener('input', this.reset);
        this.content?.removeEventListener('change', this.changed);
    }
}
