<?php

declare(strict_types=1);

namespace App\Website\UI\Form;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Lo que rellena quien escribe desde la web corporativa.
 *
 * Es un DTO y no una entidad: un mensaje de contacto no se guarda en la base
 * de datos, se manda por correo. Guardarlo obligaría a decidir cuánto tiempo
 * se conserva, quién puede leerlo y cómo se borra — tres preguntas de RGPD
 * para algo que el buzón ya resuelve.
 */
final class ContactMessageData
{
    #[Assert\NotBlank(message: 'Dinos cómo te llamas.')]
    #[Assert\Length(max: 120, maxMessage: 'El nombre no puede pasar de {{ limit }} caracteres.')]
    public string $name = '';

    #[Assert\NotBlank(message: 'Necesitamos un correo para contestarte.')]
    #[Assert\Email(message: 'Ese correo no parece válido.')]
    #[Assert\Length(max: 180)]
    public string $email = '';

    #[Assert\Length(max: 180)]
    public ?string $company = null;

    #[Assert\NotBlank(message: 'Elige un motivo.')]
    #[Assert\Choice(choices: self::TOPICS, message: 'Elige un motivo de la lista.')]
    public string $topic = 'producto';

    #[Assert\NotBlank(message: 'Cuéntanos qué necesitas.')]
    #[Assert\Length(
        min: 20,
        max: 4000,
        minMessage: 'Cuéntanos un poco más: con menos de {{ limit }} caracteres no hay por dónde empezar.',
        maxMessage: 'El mensaje no puede pasar de {{ limit }} caracteres.',
    )]
    public string $message = '';

    #[Assert\IsTrue(message: 'Necesitamos tu permiso para contestarte por correo.')]
    public bool $consent = false;

    /** @var list<string> */
    public const array TOPICS = ['producto', 'precios', 'integraciones', 'seguridad', 'otro'];

    public static function topicLabel(string $topic): string
    {
        return match ($topic) {
            'producto' => 'El producto',
            'precios' => 'Precios y planes',
            'integraciones' => 'Integraciones y API',
            'seguridad' => 'Seguridad y datos',
            default => 'Otra cosa',
        };
    }
}
