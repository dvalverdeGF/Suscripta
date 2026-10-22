<?php

declare(strict_types=1);

namespace App\Mailbox\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Borra lo que el buzón ha aprendido (D-58).
 *
 * El conocimiento aprendido vive en el módulo de procesamiento, que es quien
 * sabe qué es y cómo se poda. El módulo de buzón **necesita** borrarlo al
 * desconectar o eliminar una cuenta, así que declara este puerto; el módulo
 * de procesamiento lo implementa.
 *
 * La dependencia va en un solo sentido: `Processing → Mailbox`. El buzón no
 * sabe nada de tablas de conocimiento: solo pide que se limpien.
 */
interface MailboxKnowledgeCleanerInterface
{
    /**
     * Borra el conocimiento aprendido de un buzón.
     *
     * @return int número de entradas eliminadas
     */
    public function removeForAccount(Uuid $emailAccountId): int;
}
