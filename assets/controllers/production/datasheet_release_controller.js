import {Controller} from '@hotwired/stimulus';
import {ConfirmSwal} from '../../helpers/swal';

export default class extends Controller
{
    confirm(event)
    {
        event.preventDefault();
        event.stopPropagation();

        const button = event.currentTarget;
        ConfirmSwal.fire({
            titleText: this.element.dataset.confirmTitle,
            text: this.element.dataset.confirmMessage,
        }).then(({isConfirmed}) => {
            if (isConfirmed) {
                this.element.requestSubmit(button);
            }
        });
    }
}
