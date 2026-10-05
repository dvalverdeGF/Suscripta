import { Controller } from '@hotwired/stimulus';

/**
 * Pide confirmación antes de enviar un formulario destructivo.
 * Uso: <form data-controller="confirm" data-action="submit->confirm#check"
 *            data-confirm-message="¿Seguro?">
 */
export default class extends Controller {
    static values = { message: { type: String, default: '¿Seguro que quieres continuar?' } };

    check(event) {
        if (!window.confirm(this.messageValue)) {
            event.preventDefault();
        }
    }
}
