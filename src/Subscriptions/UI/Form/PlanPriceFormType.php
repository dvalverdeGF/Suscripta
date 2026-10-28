<?php

declare(strict_types=1);

namespace App\Subscriptions\UI\Form;

use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\UI\Form\Type\MoneyType;
use App\Subscriptions\Domain\Entity\PlanPrice;

use function is_array;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Formulario de un precio del catálogo.
 *
 * ## Por qué el importe se reconstruye en cada envío
 *
 * `MoneyType` convierte el texto a la unidad mínima con los decimales de una
 * moneda concreta, y esa moneda se decide al **construir** el campo, antes de
 * saber qué ha elegido el usuario. Con el euro y el dólar da igual (dos
 * decimales), pero con el yen no: «1000 ¥» son mil yenes, no cien mil
 * céntimos. Por eso en `PRE_SUBMIT` se vuelve a añadir el campo con la moneda
 * que viene en el propio envío.
 *
 * @extends AbstractType<PlanPriceFormData>
 */
final class PlanPriceFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currency', EnumType::class, [
                'label' => 'Moneda',
                'class' => Currency::class,
                'choice_label' => static fn (Currency $currency): string => $currency->value,
                'constraints' => [new Assert\NotNull(message: 'Selecciona una moneda.')],
            ])
            ->add('amount', MoneyType::class, [
                'label' => 'Importe',
                'currency' => Currency::EUR,
                'help' => 'Lo que se cobra en cada periodo, no el total del año.',
                'constraints' => [new Assert\NotNull(message: 'Indica el importe.')],
            ])
            ->add('billingPeriod', ChoiceType::class, [
                'label' => 'Periodicidad',
                'choices' => PlanPrice::SELLABLE_PERIODS,
                'choice_label' => static fn (BillingPeriod $period): string => $period->label(),
                // El valor es el del enum, no el índice del array: un
                // `<option value="0">` funciona pero convierte el HTML en algo
                // que no se puede leer ni enviar desde fuera del formulario.
                'choice_value' => static fn (?BillingPeriod $period): ?string => $period?->value,
                'constraints' => [new Assert\NotNull(message: 'Selecciona una periodicidad.')],
            ])
            ->add('externalPriceId', TextType::class, [
                'label' => 'Identificador en el cobrador',
                'required' => false,
                'attr' => ['placeholder' => 'price_1N…'],
                'help' => 'Opcional. El precio de Stripe al que corresponde, cuando esté conectado.',
                'constraints' => [new Assert\Length(max: 120)],
            ])
        ;

        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();

            if (!is_array($data)) {
                return;
            }

            $currency = Currency::tryFrom((string) ($data['currency'] ?? '')) ?? Currency::EUR;

            $event->getForm()->add('amount', MoneyType::class, [
                'label' => 'Importe',
                'currency' => $currency,
                'help' => 'Lo que se cobra en cada periodo, no el total del año.',
                'constraints' => [new Assert\NotNull(message: 'Indica el importe.')],
            ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PlanPriceFormData::class,
        ]);
    }
}
