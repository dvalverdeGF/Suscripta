<?php

declare(strict_types=1);

namespace App\Subscriptions\UI\Form;

use App\Subscriptions\Domain\Entity\PlanFeature;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Una línea de la lista de características de un plan.
 *
 * Es un formulario de un solo campo de texto más un interruptor, y se usa tanto
 * para añadir como para corregir: separar los dos casos obligaría a dos
 * plantillas y dos validaciones para la misma fila.
 *
 * @extends AbstractType<array{label: string, included: bool}>
 */
final class PlanFeatureFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'Característica',
                'attr' => ['placeholder' => 'Copias de seguridad diarias'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Escribe la característica.'),
                    new Assert\Length(max: PlanFeature::MAX_LABEL_LENGTH),
                ],
            ])
            ->add('included', CheckboxType::class, [
                'label' => 'Entra en el plan',
                'required' => false,
                'help' => 'Desmárcalo para enseñarla tachada: «no incluido» también ayuda a decidir.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
