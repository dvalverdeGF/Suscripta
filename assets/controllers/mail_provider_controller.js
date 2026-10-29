import { Controller } from '@hotwired/stimulus';

/**
 * Enseña los campos que tocan según el proveedor de correo elegido.
 *
 * Gmail, Microsoft, Yahoo e iCloud ya tienen su servidor en el catálogo: pedir
 * el host, el puerto y el cifrado en esos casos solo invita a escribir algo
 * distinto que la aplicación va a ignorar. Con «IMAP genérico» es al revés: sin
 * servidor no hay conexión, y ahí es donde aparece el botón de detección.
 *
 * Sin JavaScript el formulario sigue funcionando: los campos manuales están
 * visibles siempre y la validación del servidor es del lado del servidor.
 */
export default class extends Controller {
    static targets = ['provider', 'manual', 'appPassword'];

    connect() {
        this.refresh();
    }

    refresh() {
        const option = this.providerTarget.selectedOptions[0];
        const isManual = option?.dataset.mailProviderManual === '1';
        const appPasswordUrl = option?.dataset.mailProviderAppPassword ?? '';

        this.manualTargets.forEach((element) => {
            element.hidden = !isManual;
        });

        if (this.hasAppPasswordTarget) {
            this.appPasswordTarget.hidden = '' === appPasswordUrl;
            this.appPasswordTarget.href = appPasswordUrl;
        }
    }
}
