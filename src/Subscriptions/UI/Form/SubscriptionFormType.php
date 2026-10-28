<?php

declare(strict_types=1);

namespace App\Subscriptions\UI\Form;

use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Alta y edición de una suscripción del catálogo.
 *
 * Los servicios enlazables llegan como opción (`service_choices`) porque viven
 * en el módulo `Services` y este formulario no debe consultar sus repositorios
 * (ARCHITECTURE.md §3). El plan no se elige todavía: el catálogo comercial es la
 * fase 22 y hasta entonces la suscripción se identifica por su nombre.
 *
 * @extends AbstractType<SubscriptionFormData>
 */
final class SubscriptionFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Qué se ha contratado',
                'attr' => ['autofocus' => true, 'placeholder' => 'Plan Pro, Soporte prioritario…'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Indica a qué corresponde la suscripción.'),
                    new Assert\Length(max: 160),
                ],
            ])
            ->add('currency', EnumType::class, [
                'label' => 'Moneda',
                'class' => Currency::class,
                'choice_label' => static fn (Currency $currency): string => $currency->value,
                'constraints' => [new Assert\NotNull(message: 'Selecciona una moneda.')],
            ])
            ->add('billingPeriod', EnumType::class, [
                'label' => 'Periodicidad',
                'class' => BillingPeriod::class,
                'choice_label' => static fn (BillingPeriod $period): string => $period->label(),
                // Una suscripción de pago único no se renueva, así que no es una
                // suscripción: se filtra también `UNKNOWN`, que no aporta nada.
                'choice_filter' => static fn (?BillingPeriod $period): bool => null !== $period && $period->isRecurring(),
                'constraints' => [new Assert\NotNull(message: 'Selecciona una periodicidad.')],
            ])
            ->add('billingIntervalCount', IntegerType::class, [
                'label' => 'Cada',
                'help' => 'Cada cuántos periodos se cobra. Normalmente 1.',
                'attr' => ['min' => 1, 'max' => 60, 'inputmode' => 'numeric'],
                'constraints' => [
                    new Assert\NotNull(message: 'Indica cada cuántos periodos se cobra.'),
                    new Assert\Positive(message: 'El intervalo debe ser al menos 1.'),
                    new Assert\LessThanOrEqual(value: 60),
                ],
            ])
            ->add('startedAt', DateType::class, [
                'label' => 'Fecha de alta',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'Desde cuándo está contratada. Vacío = hoy.',
            ])
            ->add('serviceId', ChoiceType::class, [
                'label' => 'Servicio del inventario',
                'required' => false,
                'placeholder' => 'Sin enlazar',
                'choices' => $options['service_choices'],
                'choice_value' => static fn (?Uuid $id): ?string => $id?->toRfc4122(),
                'choice_label' => static fn (string $label): string => $label,
                'help' => 'Opcional. Enlázala si esta suscripción ya aparece en tu inventario como servicio.',
            ])
            ->add('externalReference', TextType::class, [
                'label' => 'Referencia externa',
                'required' => false,
                'attr' => ['placeholder' => 'sub_1N…'],
                'help' => 'El identificador del cobrador. Lo rellena la pasarela cuando esté conectada.',
                'constraints' => [new Assert\Length(max: 120)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SubscriptionFormData::class,
            'service_choices' => [],
        ]);

        $resolver->setAllowedTypes('service_choices', 'array');
    }
}
