import { Controller } from '@hotwired/stimulus';

/**
 * Alterna la visibilidad de la barra lateral en pantallas pequeñas.
 * En escritorio (>= 64rem) el CSS la muestra siempre.
 */
export default class extends Controller {
    static targets = ['panel', 'toggle'];

    connect() {
        this.close();
    }

    toggle() {
        this.panelTarget.dataset.open = this.panelTarget.dataset.open === 'true' ? 'false' : 'true';
        this.toggleTarget.setAttribute(
            'aria-expanded',
            this.panelTarget.dataset.open === 'true' ? 'true' : 'false',
        );
    }

    close() {
        this.panelTarget.dataset.open = 'false';
        this.toggleTarget.setAttribute('aria-expanded', 'false');
    }
}
