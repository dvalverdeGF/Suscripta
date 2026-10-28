<?php

declare(strict_types=1);

namespace App\Api\UI\Form;

use App\Api\Domain\Enum\ApiScope;

use function sprintf;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Formulario de alta de una clave de API.
 *
 * ## Por qué los ámbitos son casillas y no un selector múltiple
 *
 * Un `<select multiple>` esconde las opciones detrás de un clic y obliga a
 * mantener `Ctrl` pulsado: es la forma de equivocarse. Con las casillas a la
 * vista, y con la descripción de cada ámbito al lado, se decide en un vistazo
 * y se entiende qué se está concediendo.
 *
 * Technically, `ChoiceType` con `expanded` y `multiple` es la forma de
 * Symfony de pintar un grupo de casillas: el navegador hace el agrupado y el
 * formulario sigue siendo un solo campo con una lista de valores.
 *
 * ## `read` sale marcado de fábrica
 *
 * Es el ámbito mínimo y el que casi siempre se quiere. Marcarlo evita el
 * error de crear una clave que no puede ni leer lo que acaba de crear.
 *
 * @extends AbstractType<ApiKeyFormData>
 */
final class ApiKeyFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Nombre de la clave',
            'required' => true,
            'attr' => [
                'maxlength' => 100,
                'placeholder' => 'Integración con el ERP',
                'autocapitalize' => 'none',
            ],
            'help' => 'Para qué sirve esta clave. Solo lo ves tú.',
            'constraints' => [
                new Assert\NotBlank(message: 'La clave necesita un nombre.'),
                new Assert\Length(max: 100, maxMessage: 'El nombre no puede pasar de 100 caracteres.'),
            ],
        ]);

        $builder->add('scopes', ChoiceType::class, [
            'label' => 'Permisos',
            'required' => true,
            'multiple' => true,
            'expanded' => true,
            // Los propios casos de la enumeración son las opciones: así la
            // etiqueta y el valor salen del mismo sitio y no hay dos listas
            // que se puedan desincronizar. El valor enviado es el `value` del
            // enum (`read`/`write`), que es lo que guarda `ApiKeyFormData`.
            'choices' => ApiScope::cases(),
            'choice_value' => static fn (?ApiScope $scope): ?string => $scope?->value,
            'choice_label' => static fn (ApiScope $scope): string => sprintf(
                '%s — %s',
                $scope->label(),
                $scope->description(),
            ),
            'help' => '«Escritura» incluye «Lectura»: quien puede corregir un servicio tiene que poder verlo antes.',
        ]);

        $builder->add('expiresInDays', ChoiceType::class, [
            'label' => 'Validez',
            'required' => false,
            // Con `required => false`, Symfony deja la opción vacía como
            // valor `null`: «sin caducidad» no necesita nada más.
            'placeholder' => 'Sin caducidad',
            'choices' => [
                '30 días' => 30,
                '90 días' => 90,
                'Un año' => 365,
                'Dos años' => 730,
            ],
            'help' => 'Una clave con caducidad es más segura: si se filtra, deja de funcionar sola. Sin caducidad, la única forma de cortarla es revocarla a mano.',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ApiKeyFormData::class,
        ]);
    }
}
