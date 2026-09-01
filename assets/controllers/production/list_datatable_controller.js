import DatatablesController from '../elements/datatables/datatables_controller';

/**
 * Adds the production search/filter dropdown to Part-DB's regular DataTables toolbar.
 * The base controller remains untouched, so normal Part-DB tables are unaffected.
 */
export default class extends DatatablesController {
    static targets = ['dt', 'filterTemplate'];
    static values = {
        stateSaveTag: String,
    };

    _afterLoaded(dt) {
        super._afterLoaded(dt);

        // Use DataTables' own container. Its generated toolbar is not guaranteed
        // to remain inside the original Stimulus target.
        const tableContainer = dt.table().container();
        const lengthControl = tableContainer.querySelector('.dt-length')
            ?? tableContainer.querySelector('select[name="dt_length"]');
        const columnButtons = tableContainer.querySelector('.dt-buttons.btn-group');
        const filterButton = this.filterTemplateTarget.content.firstElementChild?.cloneNode(true);
        if (!lengthControl || !columnButtons || !filterButton) {
            console.error('Could not add the production filter to the DataTables toolbar.');
            return;
        }

        const filterToggle = filterButton.querySelector('.btn');
        const lengthSelect = lengthControl.matches('select')
            ? lengthControl
            : lengthControl.querySelector('select');

        // Keep the original DataTables toolbar untouched so its dimensions are
        // identical to the lower toolbar. Only insert one additional control.
        columnButtons.querySelectorAll('.btn').forEach((button) => {
            button.classList.remove('rounded');
        });
        lengthSelect?.classList.remove('rounded');
        filterButton.classList.add('btn-group', 'flex-shrink-0');
        filterToggle?.classList.add('rounded', 'mr-2', 'text-nowrap');
        lengthControl.before(filterButton);
    }
}
