<?php

declare(strict_types=1);

namespace App\Website\Application;

/**
 * Las preguntas frecuentes, en un solo sitio.
 *
 * La misma lista alimenta la página visible y el `FAQPage` de datos
 * estructurados. Escribirlas dos veces —una en la plantilla y otra en el JSON-LD—
 * es la forma segura de que un día digan cosas distintas, y Google penaliza el
 * marcado que no coincide con lo que se ve.
 */
final readonly class Faq
{
    /**
     * @return list<array{question: string, answer: string}>
     */
    public function items(): array
    {
        return [
            [
                'question' => '¿Suscripta lee todos mis correos?',
                'answer' => 'No. Solo se procesan los mensajes que superan los filtros de la ingesta: remitentes conocidos '
                    .'o con pinta de factura. El resto se descarta sin extraer nada, y puedes ver en el panel cuántos '
                    .'mensajes ha mirado el sistema y cuántos ha descartado. El texto que se manda a un modelo de IA va '
                    .'antes por un redactado que quita los datos que no hacen falta para leer un importe.',
            ],
            [
                'question' => '¿Cómo llegan las facturas si mi proveedor no manda avisos?',
                'answer' => 'Con la dirección de reenvío. Cada organización tiene una dirección propia: una regla en tu '
                    .'correo reenvía ahí lo que quieras vigilar, sin dar acceso al buzón completo. También puedes '
                    .'conectar la cuenta por IMAP si prefieres que Suscripta la lea directamente.',
            ],
            [
                'question' => '¿Qué pasa si detecta algo que no es una suscripción?',
                'answer' => 'Nada entra en tu inventario sin confirmación. Lo detectado llega como propuesta con su '
                    .'confianza, y tú confirmas o descartas. El sistema aprende de lo que decides: descartar un '
                    .'remitente dos veces hace que la próxima ni aparezca.',
            ],
            [
                'question' => '¿Puedo usar la API desde mi propio sistema?',
                'answer' => 'Sí. La API pública permite leer servicios, facturas, documentos y suscripciones, y crear o '
                    .'actualizar servicios. Se autentica con claves por organización que se crean y revocan desde el '
                    .'panel, y admite webhooks firmados para que tu sistema se entere de los cambios sin preguntar.',
            ],
            [
                'question' => '¿Qué pasa con mis datos si dejo de pagar?',
                'answer' => 'Se pueden exportar en cualquier momento, también desde el plan gratuito. Al cancelar, la '
                    .'cuenta queda en modo lectura durante el periodo de gracia y después se borra con el mismo '
                    .'procedimiento que el derecho de supresión: no hay copias escondidas en otro sitio.',
            ],
            [
                'question' => '¿La inteligencia artificial es obligatoria?',
                'answer' => 'No. La extracción empieza por reglas deterministas y solo escala a un modelo cuando las '
                    .'reglas no bastan. En el plan gratuito la IA está apagada y el producto funciona igual, con menos '
                    .'cobertura en facturas raras. Cuando está encendida, hay un presupuesto mensual que no se puede '
                    .'rebasear.',
            ],
        ];
    }
}
