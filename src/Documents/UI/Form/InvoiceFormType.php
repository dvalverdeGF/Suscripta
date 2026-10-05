<?php

declare(strict_types=1);

namespace App\Documents\UI\Form;

use App\Documents\Domain\Enum\InvoiceSource;
use App\Documents\Domain\Enum\InvoiceStatus;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\UI\Form\Type\MoneyType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Alta y edición de un cobro documentado.
 *
 * @extends AbstractType<InvoiceFormData>
 */
final class InvoiceFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('issuedAt', DateType::class, [
                'label' => 'Fecha de emisión',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new Assert\NotNull(message: 'Indica la fecha de la factura.')],
            ])
            ->add('total', MoneyType::class, [
                'label' => 'Importe',
                'currency' => $options['currency'],
                'constraints' => [new Assert\NotNull(message: 'Indica el importe.')],
            ])
            ->add('currency', EnumType::class, [
                'label' => 'Moneda',
                'class' => Currency::class,
                'choice_label' => static fn (Currency $currency): string => $currency->value,
                'constraints' => [new Assert\NotNull(message: 'Selecciona una moneda.')],
            ])
            ->add('number', TextType::class, [
                'label' => 'Número de factura',
                'required' => false,
                'constraints' => [new Assert\Length(max: 120)],
            ])
            ->add('serviceId', ChoiceType::class, [
                'label' => 'Servicio',
                'required' => false,
                'placeholder' => 'Sin asignar',
                'choices' => $options['service_choices'],
                'choice_value' => static fn (?Uuid $id): ?string => $id?->toRfc4122(),
                'choice_label' => static fn (string $label): string => $label,
            ])
            ->add('providerId', ChoiceType::class, [
                'label' => 'Proveedor',
                'required' => false,
                'placeholder' => 'Sin proveedor',
                'choices' => $options['provider_choices'],
                'choice_value' => static fn (?Uuid $id): ?string => $id?->toRfc4122(),
                'choice_label' => static fn (string $label): string => $label,
            ])
            ->add('periodStart', DateType::class, [
                'label' => 'Inicio del periodo',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('periodEnd', DateType::class, [
                'label' => 'Fin del periodo',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('paidAt', DateType::class, [
                'label' => 'Fecha de cobro',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'Si la rellenas, la factura queda como pagada.',
            ])
            ->add('status', EnumType::class, [
                'label' => 'Estado',
                'class' => InvoiceStatus::class,
                'choice_label' => static fn (InvoiceStatus $status): string => $status->label(),
                'constraints' => [new Assert\NotNull(message: 'Selecciona un estado.')],
            ])
            ->add('source', EnumType::class, [
                'label' => 'Origen',
                'class' => InvoiceSource::class,
                'choice_label' => static fn (InvoiceSource $source): string => $source->label(),
                'constraints' => [new Assert\NotNull(message: 'Selecciona un origen.')],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => InvoiceFormData::class,
            'currency' => Currency::EUR,
            'service_choices' => [],
            'provider_choices' => [],
        ]);

        $resolver->setAllowedTypes('currency', Currency::class);
        $resolver->setAllowedTypes('service_choices', 'array');
        $resolver->setAllowedTypes('provider_choices', 'array');
    }
}
