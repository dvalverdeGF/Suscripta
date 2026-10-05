<?php

declare(strict_types=1);

namespace App\Shared\UI\Form\Type;

use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use App\Shared\UI\Form\DataTransformer\MoneyToDecimalStringTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Campo de importe monetario.
 *
 * Se apoya en `TextType` y no en `NumberType` a propósito: `NumberType` pasa por
 * `float` y el dominio no admite coma flotante para dinero (D-09). El navegador
 * recibe `inputmode="decimal"` para que en móvil salga el teclado numérico.
 *
 * @extends AbstractType<Money>
 */
final class MoneyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addViewTransformer(new MoneyToDecimalStringTransformer($options['currency']));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'currency' => Currency::EUR,
            'invalid_message' => 'El importe no tiene un formato válido.',
            'attr' => [
                'inputmode' => 'decimal',
                'autocomplete' => 'off',
                'placeholder' => '0,00',
            ],
        ]);

        $resolver->setAllowedTypes('currency', Currency::class);
    }

    public function getParent(): string
    {
        return TextType::class;
    }

    /**
     * El prefijo por defecto sería `money`, y el tema de formularios de Symfony
     * tiene un bloque `money_widget` que espera la variable `money_pattern` de
     * su propio `MoneyType`. Al renombrar el prefijo se hereda `text_widget`, que
     * es lo que este campo realmente es.
     */
    public function getBlockPrefix(): string
    {
        return 'app_money';
    }
}
