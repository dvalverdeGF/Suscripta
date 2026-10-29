import { Controller } from '@hotwired/stimulus';

/**
 * Ajusta el formulario al proveedor elegido en el desplegable.
 *
 * En el alta el proveedor viene fijado por la ruta y el servidor ya ha pintado
 * solo los campos que tocan, así que aquí no hay nada que hacer: sin
 * desplegable el controlador sale sin tocar el DOM. Quien sí lo necesita es la
 * pantalla de edición, donde el proveedor sigue siendo un desplegable y cambiar
 * a Gmail, Microsoft, Yahoo o iCloud tiene que esconder el servidor —lo pone el
 * catálogo— y enseñar dónde se genera la contraseña de aplicación.
 *
 * Sin JavaScript no se pierde nada: los campos del servidor están visibles y la
 * validación de verdad es del lado del servidor, que ignora lo que llegue para
 * un proveedor de catálogo.
 */
export default class extends Controller {
    static targets = ['provider', 'manual', 'appPasswordNote', 'appPasswordLink'];

    connect() {
        this.refresh();
    }

    refresh() {
        if (!this.hasProviderTarget) {
            return;
        }

        const option = this.providerTarget.selectedOptions[0];
        const isManual = option?.dataset.mailProviderManual === '1';
        const appPasswordUrl = option?.dataset.mailProviderAppPassword ?? '';

        this.manualTargets.forEach((element) => {
            element.hidden = !isManual;
        });

        this.appPasswordNoteTargets.forEach((element) => {
            element.hidden = '' === appPasswordUrl;
        });

        this.appPasswordLinkTargets.forEach((element) => {
            element.href = '' === appPasswordUrl ? '#' : appPasswordUrl;
        });
    }
}
