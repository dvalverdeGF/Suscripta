import { Controller } from '@hotwired/stimulus';

/**
 * Copia un valor al portapapeles y lo dice.
 *
 * ## Por qué hace falta
 *
 * Una clave de API son 43 caracteres. Copiarla a mano es casi garantía de un
 * error tipográfico, y una clave mal copiada produce un `401` que no explica
 * dónde está el fallo. Copiar con un clic lo evita.
 *
 * ## El plan B
 *
 * `navigator.clipboard` solo existe en contexto seguro (HTTPS o localhost).
 * En un entorno de desarrollo en HTTP plano no está, así que se recurre al
 * método antiguo —campo temporal y `execCommand('copy')`— que funciona en
 * todas partes. No es elegante, pero es mejor que un botón que no hace nada.
 *
 * Uso:
 * <button data-controller="copy" data-copy-target-value="#campo"
 *         data-action="copy#copy">Copiar</button>
 */
export default class extends Controller {
    static values = { target: { type: String, default: '' } };

    async copy(event) {
        event.preventDefault();

        const element = this.targetValue ? document.querySelector(this.targetValue) : null;

        if (!(element instanceof HTMLInputElement) && !(element instanceof HTMLTextAreaElement)) {
            return;
        }

        const copied = await this.write(element.value);

        this.feedback(copied);
    }

    async write(value) {
        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(value);
                return true;
            } catch (error) {
                // Cae al plan B: el portapapeles puede estar bloqueado por
                // permisos y eso no debe dejar al operador sin poder copiar.
            }
        }

        return this.writeLegacy(value);
    }

    writeLegacy(value) {
        const helper = document.createElement('textarea');
        helper.value = value;
        helper.setAttribute('readonly', '');
        helper.style.position = 'fixed';
        helper.style.opacity = '0';
        document.body.appendChild(helper);
        helper.select();

        let copied = false;

        try {
            copied = document.execCommand('copy');
        } catch (error) {
            copied = false;
        }

        document.body.removeChild(helper);

        return copied;
    }

    /**
     * Confirma con un cambio en el propio botón: un aviso que desaparece
     * deprisa no deja claro si la copia se hizo o no.
     */
    feedback(copied) {
        const label = this.element.querySelector('[data-copy-label]') ?? this.element;
        const original = label.innerHTML;

        label.innerHTML = copied
            ? '<i class="fa-solid fa-check me-1" aria-hidden="true"></i> Copiado'
            : '<i class="fa-solid fa-xmark me-1" aria-hidden="true"></i> No se pudo copiar';

        this.element.classList.toggle('btn-success', copied);
        this.element.classList.toggle('btn-outline-secondary', !copied);

        window.setTimeout(() => {
            label.innerHTML = original;
            this.element.classList.remove('btn-success');
            this.element.classList.add('btn-outline-secondary');
        }, 2000);
    }
}
