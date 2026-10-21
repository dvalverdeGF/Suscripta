<?php

declare(strict_types=1);

namespace App\Integrations\Application;

use function in_array;
use function is_numeric;
use function sprintf;
use function trim;

/**
 * Un campo de configuración de una integración.
 *
 * ## Los tipos existen por lo que se configura, no por completar
 *
 * Empezaron siendo `text` y `secret` y se quedaban cortos para lo que viene:
 * elegir el **modelo** de un proveedor de IA necesita un desplegable (hay que
 * ver las opciones, no recordarlas) y editar **umbrales** (confianza mínima,
 * presupuesto) necesita un número con su mínimo y su máximo. Un `input` de
 * texto para un modelo obliga a escribirlo de memoria y a equivocarse en una
 * letra; para un umbral, permite guardar `2000` donde el máximo es `100`.
 *
 * `secret` es el dato que más importa: decide si el valor **se puede devolver
 * al navegador**. Un secreto guardado no se enseña nunca —ni al
 * administrador—, así que el campo se pinta vacío y solo se escribe si el
 * operador teclea uno nuevo.
 *
 * `choices` acompaña a `select`; `min`/`max`/`step`, a `number`. La validación
 * vive en la **definición** y no en la plantilla, para que un campo añadido al
 * registro salga ya validado.
 */
final readonly class IntegrationField
{
    public const string TYPE_TEXT = 'text';
    public const string TYPE_SECRET = 'secret';
    public const string TYPE_NUMBER = 'number';
    public const string TYPE_SELECT = 'select';
    public const string TYPE_BOOLEAN = 'boolean';

    /**
     * @param list<array{value: string, label: string}> $choices
     */
    private function __construct(
        public string $name,
        public string $label,
        public string $type = self::TYPE_TEXT,
        public bool $required = false,
        public ?string $hint = null,
        public ?string $placeholder = null,
        public array $choices = [],
        public int|float|null $min = null,
        public int|float|null $max = null,
        public int|float|null $step = null,
        /**
         * Valor que usa la aplicación si el campo se deja vacío.
         *
         * Se declara para poder **decirlo** en la pantalla: un umbral sin valor
         * guardado no está "sin definir", tiene el de por defecto, y no decirlo
         * deja al operador sin saber qué pasa si lo deja en blanco.
         */
        public ?string $default = null,
    ) {
    }

    public static function text(
        string $name,
        string $label,
        bool $required = false,
        ?string $hint = null,
        ?string $placeholder = null,
    ): self {
        return new self($name, $label, self::TYPE_TEXT, $required, $hint, $placeholder);
    }

    public static function secret(
        string $name,
        string $label,
        bool $required = false,
        ?string $hint = null,
    ): self {
        return new self($name, $label, self::TYPE_SECRET, $required, $hint);
    }

    public static function number(
        string $name,
        string $label,
        ?int $min = null,
        ?int $max = null,
        int|float|null $step = null,
        bool $required = false,
        ?string $hint = null,
        ?string $default = null,
    ): self {
        return new self(
            $name,
            $label,
            self::TYPE_NUMBER,
            $required,
            $hint,
            null,
            [],
            $min,
            $max,
            $step,
            $default,
        );
    }

    /**
     * Desplegable de opciones.
     *
     * @param list<array{value: string, label: string}> $choices
     */
    public static function select(
        string $name,
        string $label,
        array $choices,
        bool $required = false,
        ?string $hint = null,
        ?string $default = null,
    ): self {
        return new self($name, $label, self::TYPE_SELECT, $required, $hint, null, $choices, null, null, null, $default);
    }

    public static function boolean(
        string $name,
        string $label,
        ?string $hint = null,
        ?string $default = null,
    ): self {
        return new self($name, $label, self::TYPE_BOOLEAN, false, $hint, null, [], null, null, null, $default);
    }

    public function isSecret(): bool
    {
        return self::TYPE_SECRET === $this->type;
    }

    public function isSelect(): bool
    {
        return self::TYPE_SELECT === $this->type;
    }

    public function isNumber(): bool
    {
        return self::TYPE_NUMBER === $this->type;
    }

    public function isBoolean(): bool
    {
        return self::TYPE_BOOLEAN === $this->type;
    }

    /**
     * Atributo HTML del control, para no repartir esta correspondencia por la vista.
     */
    public function inputType(): string
    {
        return match ($this->type) {
            self::TYPE_SECRET => 'password',
            self::TYPE_NUMBER => 'number',
            default => 'text',
        };
    }

    /**
     * Indica si el valor tecleado es válido.
     *
     * Se valida aquí y no en el controlador para que un campo añadido al
     * registro quede validado sin tocar el controlador: si la regla no vive con
     * el campo, cada campo nuevo es un sitio más donde olvidarla.
     *
     * @return string|null motivo del rechazo, o `null` si es válido
     */
    public function validate(mixed $value): ?string
    {
        $isBlank = null === $value || '' === trim((string) $value);

        // Vacío se acepta **siempre**: en blanco significa "no lo he tocado" y el
        // escritor conserva lo que hubiera. Rechazarlo impediría guardar
        // cualquier otro cambio de la misma pantalla, que es peor que no
        // validar.
        if ($isBlank) {
            return null;
        }

        if ($this->isNumber()) {
            if (!is_numeric((string) $value)) {
                return sprintf('El campo "%s" tiene que ser un número.', $this->name);
            }

            $number = (float) $value;

            if (null !== $this->min && $number < $this->min) {
                return sprintf('El campo "%s" no puede ser menor que %s.', $this->name, $this->min);
            }

            if (null !== $this->max && $number > $this->max) {
                return sprintf('El campo "%s" no puede ser mayor que %s.', $this->name, $this->max);
            }

            return null;
        }

        if ($this->isSelect()) {
            foreach ($this->choices as $choice) {
                if ($choice['value'] === (string) $value) {
                    return null;
                }
            }

            return sprintf('El campo "%s" tiene un valor que no está entre los permitidos.', $this->name);
        }

        if ($this->isBoolean()) {
            return in_array((string) $value, ['0', '1', 'true', 'false'], true)
                ? null
                : sprintf('El campo "%s" tiene que ser verdadero o falso.', $this->name);
        }

        return null;
    }
}
