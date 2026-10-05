<?php

declare(strict_types=1);

namespace App\Mailbox\UI\Form;

use App\Mailbox\Domain\Enum\EmailAccountProvider;
use App\Mailbox\Domain\Enum\ImapEncryption;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Formulario de conexión de un buzón IMAP.
 *
 * No se pide el proveedor de correo como «Gmail / Outlook / Otro»: se piden
 * servidor, puerto y cifrado, que es lo que de verdad determina si la conexión
 * funciona. Los valores por defecto (993 + SSL) aciertan en la mayoría de
 * proveedores, y el usuario que no lo sepa puede dejar el puerto vacío
 * (D-23: nada de diseñar alrededor de Gmail).
 *
 * @extends AbstractType<EmailAccountFormData>
 */
final class EmailAccountFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isNew = $options['is_new'];

        $builder
            ->add('emailAddress', EmailType::class, [
                'label' => 'Dirección de correo',
                'attr' => ['autofocus' => true, 'placeholder' => 'facturas@miempresa.com', 'autocomplete' => 'email'],
                'disabled' => !$isNew,
                'constraints' => [
                    new Assert\NotBlank(message: 'Indica la dirección del buzón.'),
                    new Assert\Email(message: 'Esa dirección de correo no es válida.'),
                    new Assert\Length(max: 180),
                ],
            ])
            ->add('displayName', TextType::class, [
                'label' => 'Nombre para identificarlo',
                'required' => false,
                'attr' => ['placeholder' => 'Correo de facturación'],
                'help' => 'Solo para que lo reconozcas si conectas varios buzones.',
                'constraints' => [new Assert\Length(max: 120)],
            ])
            ->add('imapHost', TextType::class, [
                'label' => 'Servidor IMAP',
                'attr' => ['placeholder' => 'imap.miproveedor.com', 'autocapitalize' => 'none'],
                'help' => 'El nombre del servidor de correo entrante. Suele empezar por «imap.».',
                'constraints' => [
                    new Assert\NotBlank(message: 'Indica el servidor IMAP.'),
                    new Assert\Length(max: 180),
                ],
            ])
            ->add('imapPort', IntegerType::class, [
                'label' => 'Puerto',
                'required' => false,
                'attr' => ['placeholder' => '993', 'inputmode' => 'numeric'],
                'help' => 'Déjalo vacío para usar el puerto estándar del cifrado elegido.',
                'constraints' => [
                    new Assert\Positive(),
                    new Assert\LessThanOrEqual(value: 65535),
                ],
            ])
            ->add('imapEncryption', EnumType::class, [
                'label' => 'Cifrado',
                'class' => ImapEncryption::class,
                'choice_label' => static fn (ImapEncryption $encryption): string => $encryption->label(),
                'constraints' => [new Assert\NotNull(message: 'Selecciona un tipo de cifrado.')],
            ])
            ->add('imapUsername', TextType::class, [
                'label' => 'Usuario',
                'required' => false,
                'attr' => ['autocomplete' => 'off', 'autocapitalize' => 'none'],
                'help' => 'Solo si es distinto de la dirección de correo.',
                'constraints' => [new Assert\Length(max: 180)],
            ])
            ->add('password', PasswordType::class, [
                'label' => 'Contraseña',
                'required' => $isNew,
                'always_empty' => true,
                'attr' => ['autocomplete' => 'new-password'],
                'help' => 'Si tu proveedor tiene verificación en dos pasos, necesitas una contraseña de aplicación. Se guarda cifrada y solo se usa para leer.',
                'constraints' => $isNew ? [new Assert\NotBlank(message: 'Indica la contraseña o el token de aplicación.')] : [],
            ])
            ->add('imapFolder', TextType::class, [
                'label' => 'Carpeta',
                'attr' => ['placeholder' => 'INBOX'],
                'help' => 'La carpeta donde recibes las facturas. Normalmente la bandeja de entrada.',
                'constraints' => [
                    new Assert\NotBlank(message: 'Indica la carpeta que se va a leer.'),
                    new Assert\Length(max: 120),
                ],
            ])
            ->add('provider', EnumType::class, [
                'label' => 'Tipo de conexión',
                'class' => EmailAccountProvider::class,
                'choice_label' => static fn (EmailAccountProvider $provider): string => $provider->label(),
                'choice_filter' => static fn (?EmailAccountProvider $provider): bool => null !== $provider && $provider->isImplemented(),
                'constraints' => [new Assert\NotNull(message: 'Selecciona un tipo de conexión.')],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EmailAccountFormData::class,
            'is_new' => true,
        ]);

        $resolver->setAllowedTypes('is_new', 'bool');
    }
}
