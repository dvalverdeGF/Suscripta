<?php

declare(strict_types=1);

namespace App\Notifications\UI\Form;

use App\Notifications\Application\Dto\NotificationPreferenceFormData;
use App\Notifications\Domain\Enum\AlertType;
use App\Notifications\Domain\Enum\NotificationChannel;

use function sprintf;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * La matriz de preferencias de avisos.
 *
 * Las casillas se generan a partir de los enums, no se escriben a mano: añadir
 * un tipo de aviso o un canal nuevo aparece aquí solo. Cada casilla escribe en
 * `enabled[tipo][canal]` mediante `property_path`, así que el DTO no necesita
 * una propiedad por combinación.
 *
 * @extends AbstractType<NotificationPreferenceFormData>
 */
final class NotificationPreferenceFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (AlertType::cases() as $type) {
            foreach (NotificationChannel::cases() as $channel) {
                $builder->add(self::fieldName($type, $channel), CheckboxType::class, [
                    'label' => $channel->label(),
                    'required' => false,
                    'property_path' => sprintf('enabled[%s][%s]', $type->value, $channel->value),
                ]);
            }
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => NotificationPreferenceFormData::class]);
    }

    /**
     * Nombre del campo en el formulario. Se usa también en la plantilla para
     * poder pintar la casilla dentro de su fila de la tabla.
     */
    public static function fieldName(AlertType $type, NotificationChannel $channel): string
    {
        return sprintf('%s_%s', $type->value, $channel->value);
    }
}
