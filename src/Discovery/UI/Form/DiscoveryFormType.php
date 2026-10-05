<?php

declare(strict_types=1);

namespace App\Discovery\UI\Form;

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
 * Formulario de revisión de una propuesta.
 *
 * Es deliberadamente más corto que el de alta manual: el usuario ya tiene los
 * datos delante y lo que necesita es confirmar o corregir, no rellenar una ficha
 * completa. Los campos que el pipeline no puede deducir (categoría, preaviso)
 * se dejan opcionales y se pueden completar después desde el servicio.
 *
 * @extends AbstractType<DiscoveryFormData>
 */
final class DiscoveryFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Servicio',
                'attr' => ['autofocus' => true],
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
                'constraints' => [new Assert\Length(max: 160)],
            ])
            ->add('amount', MoneyType::class, [
                'label' => 'Importe por cobro',
                'required' => false,
                'currency' => $options['currency'],
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
                'attr' => ['min' => 1, 'max' => 60, 'inputmode' => 'numeric'],
                'constraints' => [
                    new Assert\NotNull(message: 'Indica cada cuántos periodos se cobra.'),
                    new Assert\Positive(message: 'El intervalo debe ser al menos 1.'),
                    new Assert\LessThanOrEqual(value: 60),
                ],
            ])
            ->add('startedAt', DateType::class, [
                'label' => 'Fecha de la factura',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('nextChargeAt', DateType::class, [
                'label' => 'Próximo cobro',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('renewalAt', DateType::class, [
                'label' => 'Renovación',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('noticePeriodDays', IntegerType::class, [
                'label' => 'Preaviso (días)',
                'required' => false,
                'attr' => ['min' => 0, 'max' => 365, 'inputmode' => 'numeric'],
                'constraints' => [new Assert\PositiveOrZero()],
            ])
            ->add('autoRenews', CheckboxType::class, [
                'label' => 'Se renueva automáticamente',
                'required' => false,
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notas',
                'required' => false,
                'attr' => ['rows' => 2],
                'constraints' => [new Assert\Length(max: 2000)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DiscoveryFormData::class,
            'currency' => Currency::EUR,
            'provider_choices' => [],
            'category_choices' => [],
        ]);

        $resolver->setAllowedTypes('currency', Currency::class);
        $resolver->setAllowedTypes('provider_choices', 'array');
        $resolver->setAllowedTypes('category_choices', 'array');
    }
}
