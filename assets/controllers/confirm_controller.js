import { Controller } from '@hotwired/stimulus';

/**
 * Pide confirmación antes de enviar un formulario destructivo.
 *
 * Se engancha solo al conectar, sin `data-action` en la plantilla: es más
 * difícil olvidarlo y el formulario queda legible.
 *
 * Uso: <form data-controller="confirm" data-confirm-message-value="¿Seguro?">
 */
export default class extends Controller {
    static values = { message: { type: String, default: '¿Seguro que quieres continuar?' } };

    connect() {
        this.onSubmit = (event) => {
            if (!window.confirm(this.messageValue)) {
                event.preventDefault();
            }
        };

        this.element.addEventListener('submit', this.onSubmit);
    }

    disconnect() {
        this.element.removeEventListener('submit', this.onSubmit);
    }
}
