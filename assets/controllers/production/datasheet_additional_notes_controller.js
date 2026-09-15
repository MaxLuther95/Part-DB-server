import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['list', 'template']

    connect() {
        this.nextIndex = 0
        this.listTarget.querySelectorAll('[name]').forEach((field) => {
            const match = field.name.match(/^additional_notes\[(\d+)\]/)
            if (match) this.nextIndex = Math.max(this.nextIndex, Number(match[1]) + 1)
        })
    }

    add() {
        const fragment = this.templateTarget.content.cloneNode(true)
        const row = fragment.querySelector('[data-note-row]')

        row.querySelectorAll('[data-field]').forEach((field) => {
            field.name = `additional_notes[${this.nextIndex}][${field.dataset.field}]`
        })
        this.nextIndex += 1
        this.listTarget.appendChild(fragment)
    }

    remove(event) {
        event.currentTarget.closest('[data-note-row]')?.remove()
    }
}
