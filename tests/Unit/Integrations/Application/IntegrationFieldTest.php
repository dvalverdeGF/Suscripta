<?php

declare(strict_types=1);

namespace App\Tests\Unit\Integrations\Application;

use App\Integrations\Application\IntegrationField;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Un campo de configuración se valida **en su definición**, no en la plantilla.
 *
 * La razón práctica: si la regla vive en la vista, cada campo nuevo es un
 * sitio más donde olvidarla, y el fallo aparece como «el panel acepta
 * cualquier cosa». Aquí la validación viaja con el campo.
 *
 * Y la regla de fondo: **vacío siempre es válido**. Un campo en blanco
 * significa «no lo he tocado», y rechazarlo impediría guardar el resto de los
 * cambios de la misma pantalla.
 */
#[CoversClass(IntegrationField::class)]
final class IntegrationFieldTest extends TestCase
{
    public function testAnEmptyValueIsAlwaysValid(): void
    {
        $field = IntegrationField::number('max_tokens', 'Tokens', min: 256, max: 32768, required: true);

        self::assertNull($field->validate(''));
        self::assertNull($field->validate('   '));
        self::assertNull($field->validate(null));
    }

    public function testANumberFieldRejectsANonNumericValue(): void
    {
        $field = IntegrationField::number('max_tokens', 'Tokens', min: 256, max: 32768);

        $error = $field->validate('muchos');

        self::assertNotNull($error);
        self::assertStringContainsString('max_tokens', $error);
        self::assertStringContainsString('número', $error);
    }

    public function testANumberFieldRejectsAValueBelowTheMinimum(): void
    {
        $field = IntegrationField::number('max_tokens', 'Tokens', min: 256, max: 32768);

        $error = $field->validate('128');

        self::assertNotNull($error);
        self::assertStringContainsString('menor que 256', $error);
    }

    public function testANumberFieldRejectsAValueAboveTheMaximum(): void
    {
        $field = IntegrationField::number('max_tokens', 'Tokens', min: 256, max: 32768);

        $error = $field->validate('65536');

        self::assertNotNull($error);
        self::assertStringContainsString('mayor que 32768', $error);
    }

    public function testANumberFieldAcceptsAValueInsideTheRange(): void
    {
        $field = IntegrationField::number('max_tokens', 'Tokens', min: 256, max: 32768);

        self::assertNull($field->validate('2048'));
    }

    public function testANumberFieldWithoutLimitsAcceptsAnyNumber(): void
    {
        $field = IntegrationField::number('temperature', 'Temperatura');

        self::assertNull($field->validate('0'));
        self::assertNull($field->validate('99'));
    }

    public function testASelectFieldAcceptsOneOfItsChoices(): void
    {
        $field = IntegrationField::select('model', 'Modelo', [
            ['value' => 'llama-3.3-70b-versatile', 'label' => 'Llama 3.3 70B'],
            ['value' => 'gemma2-9b-it', 'label' => 'Gemma 2 9B'],
        ]);

        self::assertNull($field->validate('gemma2-9b-it'));
    }

    public function testASelectFieldRejectsAValueThatIsNotAmongItsChoices(): void
    {
        $field = IntegrationField::select('model', 'Modelo', [
            ['value' => 'llama-3.3-70b-versatile', 'label' => 'Llama 3.3 70B'],
        ]);

        $error = $field->validate('modelo-inventado');

        self::assertNotNull($error);
        self::assertStringContainsString('no está entre los permitidos', $error);
    }

    #[DataProvider('booleanValues')]
    public function testABooleanFieldAcceptsTheUsualRepresentations(string $value): void
    {
        $field = IntegrationField::boolean('enabled', 'Activado');

        self::assertNull($field->validate($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function booleanValues(): iterable
    {
        yield 'uno' => ['1'];
        yield 'cero' => ['0'];
        yield 'true' => ['true'];
        yield 'false' => ['false'];
    }

    public function testABooleanFieldRejectsAnythingElse(): void
    {
        $field = IntegrationField::boolean('enabled', 'Activado');

        $error = $field->validate('quizá');

        self::assertNotNull($error);
        self::assertStringContainsString('verdadero o falso', $error);
    }

    public function testATextFieldAcceptsAnything(): void
    {
        $field = IntegrationField::text('organization', 'Organización');

        self::assertNull($field->validate('org-123'));
        self::assertNull($field->validate('lo que sea'));
    }

    public function testASecretFieldIsRecognisedAsASecret(): void
    {
        $field = IntegrationField::secret('api_key', 'Clave API');

        self::assertTrue($field->isSecret());
        self::assertSame('password', $field->inputType());
    }

    public function testTheInputTypeMatchesTheKindOfField(): void
    {
        self::assertSame('number', IntegrationField::number('n', 'N')->inputType());
        self::assertSame('text', IntegrationField::text('t', 'T')->inputType());
        self::assertSame('text', IntegrationField::select('s', 'S', [])->inputType());
        self::assertSame('text', IntegrationField::boolean('b', 'B')->inputType());
    }
}
