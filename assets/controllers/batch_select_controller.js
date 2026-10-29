import { Controller } from '@hotwired/stimulus';

/**
 * Revisión por lotes de la bandeja de propuestas.
 *
 * Tres cosas, y ninguna es imprescindible: marcar todas las casillas con una,
 * contar cuántas hay marcadas y reflejarlo en la barra de acciones. Sin
 * JavaScript la barra sigue visible y el formulario se envía con lo que el
 * usuario haya marcado a mano, así que la función no depende del script.
 */
export default class extends Controller {
    static targets = ['toggle', 'checkbox', 'toolbar', 'counter'];

    connect() {
        this.checkboxTargets.forEach((checkbox) => {
            checkbox.addEventListener('change', () => this.refresh());
        });

        this.toggleTarget?.addEventListener('change', (event) => this.toggleAll(event));

        this.refresh();
    }

    /** Marca o desmarca todas las casillas según el estado del interruptor. */
    toggleAll(event) {
        this.checkboxTargets.forEach((checkbox) => {
            checkbox.checked = event.target.checked;
        });

        this.refresh();
    }

    refresh() {
        const selected = this.selectedCount;

        if (this.hasCounterTarget) {
            this.counterTarget.textContent =
                selected === 1
                    ? '1 propuesta seleccionada'
                    : `${selected} propuestas seleccionadas`;
        }

        if (this.hasToolbarTarget) {
            this.toolbarTarget.classList.toggle('bo-batch-toolbar--active', selected > 0);
        }

        if (this.hasToggleTarget) {
            // El interruptor queda a medias cuando solo hay una parte marcada:
            // es la única forma de decir "todas" sin mentir.
            this.toggleTarget.indeterminate =
                selected > 0 && selected < this.checkboxTargets.length;
        }
    }

    get selectedCount() {
        return this.checkboxTargets.filter((checkbox) => checkbox.checked).length;
    }
}
