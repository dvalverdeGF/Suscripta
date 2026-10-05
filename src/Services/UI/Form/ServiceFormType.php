<?php

declare(strict_types=1);

namespace App\Services\UI\Form;

use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\UI\Form\Type\MoneyType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Formulario de alta y edición de un servicio.
 *
 * Los proveedores y categorías llegan como opciones (`provider_choices`,
 * `category_choices`) porque viven en el módulo `Catalog` y este formulario no
 * debe consultar sus repositorios (ARCHITECTURE.md §3).
 *
 * @extends AbstractType<ServiceFormData>
 */
final class ServiceFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre del servicio',
                'attr' => ['autofocus' => true, 'placeholder' => 'OVH, Adobe, Seguro de responsabilidad…'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Indica el nombre del servicio.'),
                    new Assert\Length(max: 160),
                ],
            ])
            ->add('providerId', ChoiceType::class, [
                'label' => 'Proveedor',
                'required' => false,
                'placeholder' => 'Sin proveedor',
                'choices' => $options['provider_choices'],
                'choice_value' => static fn (?Uuid $id): ?string => $id?->toRfc4122(),
                'choice_label' => static fn (string $label): string => $label,
            ])
            ->add('categoryId', ChoiceType::class, [
                'label' => 'Categoría',
                'required' => false,
                'placeholder' => 'Sin categoría',
                'choices' => $options['category_choices'],
                'choice_value' => static fn (?Uuid $id): ?string => $id?->toRfc4122(),
                'choice_label' => static fn (string $label): string => $label,
            ])
            ->add('planName', TextType::class, [
                'label' => 'Plan',
                'required' => false,
                'attr' => ['placeholder' => 'Plan Pro, 2 TB, Cobertura ampliada…'],
                'constraints' => [new Assert\Length(max: 160)],
            ])
            ->add('amount', MoneyType::class, [
                'label' => 'Importe',
                'required' => false,
                'currency' => $options['currency'],
                'help' => 'El importe que pagas en cada cobro, no el total anual.',
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
                'choice_filter' => static fn (?BillingPeriod $period): bool => null !== $period && BillingPeriod::UNKNOWN !== $period,
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
                'help' => 'Desde cuándo pagas este servicio.',
            ])
            ->add('nextChargeAt', DateType::class, [
                'label' => 'Próximo cobro',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'Déjalo vacío para que se calcule a partir de la fecha de alta.',
            ])
            ->add('renewalAt', DateType::class, [
                'label' => 'Renovación',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'Solo si el contrato se renueva en una fecha distinta del cobro.',
            ])
            ->add('noticePeriodDays', IntegerType::class, [
                'label' => 'Preaviso (días)',
                'required' => false,
                'attr' => ['min' => 0, 'max' => 365, 'inputmode' => 'numeric'],
                'help' => 'Días de antelación con los que hay que avisar para no renovar.',
                'constraints' => [new Assert\PositiveOrZero()],
            ])
            ->add('autoRenews', CheckboxType::class, [
                'label' => 'Se renueva automáticamente',
                'required' => false,
            ])
            ->add('commitmentEndAt', DateType::class, [
                'label' => 'Fin de compromiso',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'Fecha en la que termina la permanencia, si la hay.',
            ])
            ->add('paymentMethodLabel', TextType::class, [
                'label' => 'Método de pago',
                'required' => false,
                'attr' => ['placeholder' => 'Visa •••• 4242'],
                'help' => 'Solo una etiqueta para que reconozcas la tarjeta. No guardes el número completo.',
                'constraints' => [new Assert\Length(max: 80)],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notas',
                'required' => false,
                'attr' => ['rows' => 3],
                'constraints' => [new Assert\Length(max: 2000)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ServiceFormData::class,
            'currency' => Currency::EUR,
            'provider_choices' => [],
            'category_choices' => [],
        ]);

        $resolver->setAllowedTypes('currency', Currency::class);
        $resolver->setAllowedTypes('provider_choices', 'array');
        $resolver->setAllowedTypes('category_choices', 'array');
    }
}
