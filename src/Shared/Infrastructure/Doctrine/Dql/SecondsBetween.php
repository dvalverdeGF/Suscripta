<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Dql;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\ORM\Query\AST\Functions\DateDiffFunction;
use Doctrine\ORM\Query\AST\Literal;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

use function sprintf;

/**
 * Función DQL `SECONDS_BETWEEN(desde, hasta)`.
 *
 * ## Por qué hace falta
 *
 * Doctrine trae `DATE_DIFF`, pero cada motor la implementa de una manera:
 *
 * - En MySQL devuelve **segundos**.
 * - En PostgreSQL devuelve un **intervalo**, y `AVG(intervalo)` no existe.
 *
 * Como el panel de observabilidad (Fase 14) necesita la media de tiempo en
 * milisegundos, hace falta una función que devuelva siempre el mismo tipo:
 * un número de segundos. Esta función lo traduce a lo que corresponda a cada
 * motor.
 *
 * Es un detalle de infraestructura, no de negocio: por eso vive en
 * `Shared\Infrastructure` y no en el módulo de IA.
 */
final class SecondsBetween extends DateDiffFunction
{
    public Node $from;

    public Node $to;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->from = self::node($parser->ArithmeticPrimary());
        $parser->match(TokenType::T_COMMA);
        $this->to = self::node($parser->ArithmeticPrimary());

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    /**
     * El parser de Doctrine devuelve un nodo o, en el caso raro de un literal
     * suelto, una cadena. Un literal se envuelve en su nodo para que el
     * recorrido de SQL sea siempre el mismo.
     */
    private static function node(Node|string $primary): Node
    {
        if ($primary instanceof Node) {
            return $primary;
        }

        return new Literal(Literal::STRING, $primary);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $from = $this->from->dispatch($sqlWalker);
        $to = $this->to->dispatch($sqlWalker);

        $platform = $sqlWalker->getConnection()->getDatabasePlatform();

        if ($platform instanceof MySQLPlatform) {
            return sprintf('TIMESTAMPDIFF(SECOND, %s, %s)', $from, $to);
        }

        // PostgreSQL y cualquier motor estándar: la resta en epoch devuelve
        // un número de segundos con decimales.
        return sprintf('EXTRACT(EPOCH FROM (%s - %s))', $to, $from);
    }
}
