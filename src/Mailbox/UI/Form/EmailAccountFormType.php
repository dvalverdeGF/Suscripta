<?php

declare(strict_types=1);

namespace App\Mailbox\UI\Form;

use App\Mailbox\Domain\Enum\EmailAccountProvider;
use App\Mailbox\Domain\Enum\ImapEncryption;

use function sprintf;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Formulario de conexión de un buzón IMAP.
 *
 * El proveedor determina todo lo demás: con Gmail, Microsoft, Yahoo o iCloud el
 * servidor, el puerto y el cifrado vienen del catálogo y no se preguntan
 * (D-93). Solo con «IMAP genérico» hay que rellenarlos, y ahí es donde entra la
 * detección automática.
 *
 * El alta es un asistente de dos pasos: el primero elige el proveedor y el
 * segundo pide solo lo que ese proveedor necesita. Por eso el tipo acepta la
 * opción `provider`: cuando viene fijada desde la ruta, el proveedor **no** es
 * un campo del formulario y los datos de servidor ni siquiera existen. La
 * edición de un buzón ya conectado sigue ofreciendo el desplegable.
 *
 * La validación del servidor es de objeto y no de campo: con un proveedor de
 * catálogo el campo no existe y un `NotBlank` sobre él rechazaría un formulario
 * perfectamente válido.
 *
 * @extends AbstractType<EmailAccountFormData>
 */
final class EmailAccountFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isNew = $options['is_new'];
        /** @var EmailAccountProvider|null $provider */
        $provider = $options['provider'];
        // Con el proveedor fijado por la ruta, el servidor lo pone el catálogo.
        $asksForServer = null === $provider || null === $provider->preset();

        if (null === $provider) {
            $builder->add('provider', EnumType::class, [
                'label' => 'Proveedor',
                'class' => EmailAccountProvider::class,
                'choice_label' => static fn (EmailAccountProvider $candidate): string => $candidate->label(),
                'choice_filter' => static fn (?EmailAccountProvider $candidate): bool => null !== $candidate && $candidate->isImplemented(),
                'help' => 'Si no es ninguno de la lista, elige «IMAP genérico».',
                // Cada opción dice si el servidor lo pone el catálogo (`0`) o
                // lo tiene que escribir el usuario (`1`), y dónde se genera la
                // contraseña de aplicación. Lo lee el controlador de Stimulus
                // para enseñar u ocultar los campos y el enlace.
                'choice_attr' => static fn (EmailAccountProvider $candidate): array => [
                    'data-mail-provider-manual' => null === $candidate->preset() ? '1' : '0',
                    'data-mail-provider-app-password' => $candidate->preset()->appPasswordUrl ?? '',
                ],
                'attr' => [
                    'data-mail-provider-target' => 'provider',
                    'data-action' => 'change->mail-provider#refresh',
                ],
                'constraints' => [new Assert\NotNull(message: 'Selecciona un proveedor.')],
            ]);
        }

        $builder
            ->add('emailAddress', EmailType::class, [
                'label' => 'Dirección de correo',
                'attr' => ['placeholder' => 'facturas@miempresa.com', 'autocomplete' => 'email'],
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
            ]);

        if ($asksForServer) {
            $builder
                ->add('imapHost', TextType::class, [
                    'label' => 'Servidor IMAP',
                    'required' => false,
                    'attr' => ['placeholder' => 'imap.miproveedor.com', 'autocapitalize' => 'none'],
                    'help' => 'El nombre del servidor de correo entrante. Si no lo sabes, usa «Detectar servidor».',
                    'constraints' => [new Assert\Length(max: 180)],
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
                // Botón que no guarda: solo rellena el servidor a partir de la
                // dirección. Lleva `formnovalidate` para que el navegador no
                // exija la contraseña antes de dejar detectar el servidor, y el
                // controlador lo atiende **antes** de validar.
                ->add('discover', SubmitType::class, [
                    'label' => 'Detectar servidor',
                    'attr' => ['formnovalidate' => 'formnovalidate', 'class' => 'btn btn-outline-secondary'],
                ]);
        }

        $builder
            ->add('imapUsername', TextType::class, [
                'label' => 'Usuario',
                'required' => false,
                'attr' => ['autocomplete' => 'off', 'autocapitalize' => 'none'],
                'help' => 'Solo si es distinto de la dirección de correo.',
                'constraints' => [new Assert\Length(max: 180)],
            ])
            ->add('password', PasswordType::class, [
                'label' => 'Contraseña',
                'required' => false,
                'always_empty' => true,
                'attr' => ['autocomplete' => 'new-password'],
                'help' => 'Se guarda cifrada y solo se usa para leer. Nunca escribimos, movemos ni borramos nada de tu buzón.',
                'constraints' => [new Assert\Length(max: 4096)],
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
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EmailAccountFormData::class,
            'is_new' => true,
            // Proveedor fijado por la ruta del asistente. `null` = se elige en
            // el propio formulario (edición de un buzón ya conectado).
            'provider' => null,
            // Depende de `is_new`, así que se resuelve de forma perezosa.
            'constraints' => static fn (Options $options): array => [
                new Assert\Callback(static function (EmailAccountFormData $data, ExecutionContextInterface $context) use ($options): void {
                    /** @var EmailAccountProvider|null $provider */
                    $provider = $options['provider'] ?? $data->provider;

                    if (null === $provider) {
                        return;
                    }

                    if (null === $provider->preset() && (null === $data->imapHost || '' === trim($data->imapHost))) {
                        $context->buildViolation('Indica el servidor IMAP o usa «Detectar servidor».')
                            ->atPath('imapHost')
                            ->addViolation();

                        return;
                    }

                    if ($provider->requiresOauth()) {
                        $context->buildViolation(sprintf(
                            '%s ya no acepta contraseñas. Cierra este formulario y usa el botón «Conectar con Microsoft».',
                            $provider->label(),
                        ))
                            ->atPath('provider')
                            ->addViolation();

                        return;
                    }

                    if ($options['is_new'] && (null === $data->password || '' === $data->password)) {
                        $context->buildViolation('Indica la contraseña de aplicación del buzón.')
                            ->atPath('password')
                            ->addViolation();
                    }
                }),
            ],
        ]);

        $resolver->setAllowedTypes('is_new', 'bool');
        $resolver->setAllowedTypes('provider', [EmailAccountProvider::class, 'null']);
    }
}
