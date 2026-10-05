import { Controller } from '@hotwired/stimulus';

/**
 * Envía el formulario al cambiar un control.
 *
 * Se usa en el selector de organización activa: elegir otra organización es una
 * acción completa, no un campo que se guarda con un botón aparte. Sin JavaScript
 * el formulario sigue funcionando porque el `<noscript>` deja un botón visible.
 */
export default class extends Controller {
    submit() {
        if (this.element instanceof HTMLFormElement) {
            this.element.requestSubmit();
        }
    }
}
