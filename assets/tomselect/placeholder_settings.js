/** Use the empty choice as a native input hint, never as a selected item. */
export default function placeholderSettings(element) {
    const emptyOption = element.querySelector('option[value=""]');

    return {
        allowEmptyOption: false,
        placeholder: element.getAttribute('placeholder')
            || element.getAttribute('data-placeholder')
            || emptyOption?.textContent?.trim()
            || element.getAttribute('data-empty-message')
            || element.getAttribute('title')
            || '',
    };
}
