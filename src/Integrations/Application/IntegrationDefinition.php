<?php

declare(strict_types=1);

namespace App\Integrations\Application;

/**
 * Descripción de una integración: qué necesita para configurarse.
 *
 * ## Por qué una definición y no campos sueltos por integración
 *
 * Con un formulario escrito a mano por integración, añadir la tercera o la
 * décima significa copiar una plantilla y un controlador cada vez, y esas
 * copias se desincronizan (una pedirá el token como `password`, otra no lo
 * ocultará). Aquí la integración **declara** lo que necesita —`api_key`,
 * `model`…— y la pantalla se construye con esa declaración. Añadir una
 * integración nueva es añadir una entrada al registro, no un formulario.
 *
 * ## Los campos se declaran con su forma
 *
 * `credentialFields` lleva la etiqueta, si es secreto y si es obligatorio. Con
 * eso la vista sabe que `api_key` se pinta como `password` y nunca se
 * devuelve al navegador —el secreto guardado no se enseña, ni siquiera al
 * administrador—, y que `base_url` sí se puede mostrar.
 *
 * ## Nada de esto es genérico porque sí
 *
 * No se admite cualquier campo: `IntegrationRegistry` solo tiene definiciones
 * para integraciones que **el código sabe usar**. Una clave inventada en la
 * base de datos no se configura desde la pantalla, para que no parezca
 * operativa.
 */
final readonly class IntegrationDefinition
{
    /**
     * @param list<IntegrationField> $credentialFields campos que van cifrados
     * @param list<IntegrationField> $settingsFields   ajustes que van en claro (no son secretos)
     */
    private function __construct(
        public string $key,
        public string $label,
        public string $help,
        public array $credentialFields = [],
        public array $settingsFields = [],
        /**
         * Indica si la integración necesita una URL base propia.
         */
        public bool $requiresBaseUrl = false,
        /**
         * Icono FontAwesome de la integración, para identificarla de un vistazo.
         *
         * @var array{0: string, 1: string}
         */
        public array $icon = ['fa-solid', 'fa-plug'],
        /**
         * Si la integración es un proveedor de IA, se indica aquí para que el
         * panel pueda ofrecerla como opción al elegir IA para una necesidad.
         */
        public bool $isAiProvider = false,
    ) {
    }

    /**
     * @param list<IntegrationField>      $credentialFields
     * @param list<IntegrationField>      $settingsFields
     * @param array{0: string, 1: string} $icon
     */
    public static function of(
        string $key,
        string $label,
        string $help,
        array $credentialFields = [],
        array $settingsFields = [],
        bool $requiresBaseUrl = false,
        array $icon = ['fa-solid', 'fa-plug'],
        bool $isAiProvider = false,
    ): self {
        return new self($key, $label, $help, $credentialFields, $settingsFields, $requiresBaseUrl, $icon, $isAiProvider);
    }

    /**
     * Nombres de las credenciales, para poder pedirlas y guardarlas por nombre.
     *
     * @return list<string>
     */
    public function credentialNames(): array
    {
        return array_map(static fn (IntegrationField $f): string => $f->name, $this->credentialFields);
    }

    /**
     * @return list<string>
     */
    public function settingNames(): array
    {
        return array_map(static fn (IntegrationField $f): string => $f->name, $this->settingsFields);
    }

    /**
     * Credenciales obligatorias.
     *
     * Solo estas bloquean guardar: una credencial opcional vacía es válida, y
     * exigirlas todas obligaría a rellenar campos que muchas integraciones no
     * usan.
     *
     * @return list<string>
     */
    public function requiredCredentialNames(): array
    {
        return array_values(array_map(
            static fn (IntegrationField $f): string => $f->name,
            array_filter($this->credentialFields, static fn (IntegrationField $f): bool => $f->required),
        ));
    }

    /**
     * Todos los campos, credenciales y ajustes juntos.
     *
     * @return list<IntegrationField>
     */
    public function allFields(): array
    {
        return [...$this->credentialFields, ...$this->settingsFields];
    }
}
