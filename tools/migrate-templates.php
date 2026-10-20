<?php

declare(strict_types=1);

/**
 * Migracion de las plantillas del backoffice al vocabulario del estilo nuevo.
 *
 * El backoffice paso de un sistema de diseno propio (clases `btn--primary`,
 * `stack`, `field__label`...) al del backoffice de referencia: Bootstrap 5 mas
 * unas clases `bo-*` propias del armazon (sidebar, topbar, metrics...).
 *
 * Este script hace el trabajo mecanico de renombrar clases. El trabajo
 * **semantico** (reordenar bloques, decidir que vive en la cabecera y que en el
 * pie) se ha hecho a mano en cada plantilla: un script no puede decidir donde
 * pertenece un boton, y el motivo de que el pie de acciones exista es
 * precisamente ese.
 *
 * Uso: php tools/migrate-templates.php [--dry-run]
 */

$root = dirname(__DIR__);
$dryRun = in_array('--dry-run', $argv, true);

/**
 * Sustituciones de clase, por coincidencia exacta de token.
 *
 * Se ordenan de mas largo a mas corto para que `card__body--flush` se sustituya
 * antes que `card__body` (si no, el resto del token se queda pegado y produce
 * `card-body--flush`, que no existe).
 */
$map = [
    // ── Estructura de pagina ──────────────────────────────────────────────
    'page-header__title' => 'bo-page-header__title',
    'page-header__subtitle' => 'bo-page-header__subtitle',
    'page-header__actions' => 'bo-page-header__actions',
    'page-header' => 'bo-page-header',

    // ── Botones ───────────────────────────────────────────────────────────
    'btn--primary' => 'btn-primary',
    'btn--secondary' => 'btn-outline-secondary',
    'btn--ghost' => 'btn-outline-secondary',
    'btn--danger' => 'btn-danger',
    'btn--sm' => 'btn-sm',
    'btn--block' => 'w-100',

    // ── Tarjetas ──────────────────────────────────────────────────────────
    'card__header' => 'card-header',
    'card__body--flush' => 'p-0',
    'card__body' => 'card-body',
    'card__footer' => 'card-footer',
    'card__title' => 'card-title',

    // ── Composicion ───────────────────────────────────────────────────────
    'stack--loose' => 'd-flex flex-column gap-4',
    'stack--tight' => 'd-flex flex-column gap-2',
    'stack' => 'd-flex flex-column gap-3',
    'cluster--between' => 'd-flex flex-wrap align-items-center justify-content-between gap-2',
    'cluster--end' => 'd-flex flex-wrap align-items-center justify-content-end gap-2',
    'cluster' => 'd-flex flex-wrap align-items-center gap-2',
    'grid--4' => 'row g-3',
    'grid--2' => 'row g-3',
    'grid' => 'row g-3',

    // ── Tipografia ────────────────────────────────────────────────────────
    'text-muted' => 'text-muted',
    'text-subtle' => 'text-secondary',
    'text-sm' => 'small',
    'text-xs' => 'small text-secondary',
    'text-right' => 'text-end',
    'mono' => 'font-monospace',
    'nowrap' => 'text-nowrap',
    'truncate' => 'text-truncate',

    // ── Formularios ───────────────────────────────────────────────────────
    'field--inline' => 'd-flex align-items-center gap-2',
    'field__label' => 'form-label',
    'field__hint' => 'form-text',
    'field__error' => 'invalid-feedback d-block',
    'field' => 'mb-3',
    'textarea' => 'form-control',
    'select--sm' => 'form-select-sm',
    'select' => 'form-select',
    'input' => 'form-control',
    'checkbox' => 'form-check',
    'radio' => 'form-check',
    'form-actions' => 'd-flex flex-wrap gap-2 mt-3',

    // ── Distintivos y avisos ──────────────────────────────────────────────
    'badge--primary' => 'text-bg-primary',
    'badge--success' => 'text-bg-success',
    'badge--warning' => 'text-bg-warning',
    'badge--danger' => 'text-bg-danger',
    'badge--info' => 'text-bg-info',
    'badge' => 'badge',
    'alert--success' => 'alert-success',
    'alert--warning' => 'alert-warning',
    'alert--danger' => 'alert-danger',
    'alert--info' => 'alert-info',
    'alert' => 'alert',

    // ── Estados vacios y tablas ───────────────────────────────────────────
    'empty-state__title' => 'bo-empty__title',
    'empty-state__text' => 'text-secondary small mb-3',
    'empty-state' => 'bo-empty',
    'table--responsive' => 'bo-table align-middle',
    'table-wrapper' => 'table-responsive',
    'table' => 'table',

    // ── Cifras ────────────────────────────────────────────────────────────
    'stat__label' => 'bo-metric__label',
    'stat__value' => 'bo-metric__value',
    'stat__hint' => 'bo-metric__hint',
    'stat' => 'bo-metric',

    // ── Varios ────────────────────────────────────────────────────────────
    'timeline__date' => 'bo-timeline__date',
    'timeline__item' => 'bo-timeline__item',
    'timeline__label' => 'bo-timeline__label',
    'timeline' => 'bo-timeline',
    'definition-list' => 'bo-definition-list',
    'hidden' => 'd-none',
    'num' => 'text-end',

    // ── Espaciado ─────────────────────────────────────────────────────────
    //
    // La escala de espaciado de Bootstrap **no** es la de Suscripta:
    //
    //   Suscripta            rem      Bootstrap
    //   --space-2  (8px)    0.5      mb-2  = 0.5rem
    //   --space-4  (16px)   1.0      mb-3  = 1rem
    //   --space-6  (24px)   1.5      mb-4  = 1.5rem
    //
    // Traducir el numero "6" a "mb-6" habria roto el ritmo visual (mb-6 no
    // existe en Bootstrap y mb-5 son 48px, el doble). Se traduce el **valor**.
    'mb-6' => 'mb-4',
    'mt-6' => 'mt-4',
    'mb-4' => 'mb-3',
    'mt-4' => 'mt-3',
];

/**
 * Prefijos que aparecen **dentro** de una interpolacion de Twig.
 *
 * `class="badge badge--{{ ... }}"` no se puede resolver con el mapa de tokens
 * exactos: el sufijo lo decide la expresion. Se reescribe el prefijo y la
 * expresion decides el color (`text-bg-{{ ... 'success' ... }}`).
 */
$dynamicPrefixes = [
    'badge--' => 'text-bg-',
    'alert--' => 'alert-',
];

// Ordena por longitud descendente: los tokens mas largos se sustituyen antes.
uksort($map, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

/**
 * Sustituye tokens de clase exactos dentro de un atributo `class="..."`.
 *
 * Se trabaja sobre el valor entrecomillado y no sobre el documento entero: los
 * mismos nombres aparecen en texto (por ejemplo "table" en una frase) y en
 * codigo, y cambiarlos ahi seria un destrozo silencioso.
 *
 * Un token puede contener una interpolacion de Twig (`badge--{{ cond ? 'a' : 'b' }}`),
 * y ahi el nombre de la clase se parte en dos: el prefijo literal, que decide la
 * familia (`text-bg-`, `alert-`...), y el sufijo, que decide el color y lo
 * aporta la expresion. Por eso el token se parte en prefijo y expresion, y la
 * expresion se reescribe por separado.
 */
function replaceClassTokens(string $classes, array $map, array $dynamicPrefixes): string
{
    // La interpolacion `{{ ... }}` es **una unidad**: partirla por espacios
    // romperia la expresion. Se parte primero por interpolaciones (guardando
    // los separadores) y despues, dentro de cada trozo de texto, por espacios.
    $segments = preg_split('/(\{\{.*?\}\})/', $classes, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (false === $segments) {
        return $classes;
    }

    $out = [];
    $last = count($segments) - 1;

    foreach ($segments as $index => $segment) {
        // ── Interpolacion ───────────────────────────────────────────────
        if (0 !== $index % 2) {
            $prefix = '';

            // El nombre de la clase puede venir **pegado** a la expresion
            // (`badge--{{ ... }}`): ese prefijo literal decide la familia y se
            // renombra. Si hay un espacio de por medio, el prefijo ya es otra
            // clase y no se toca.
            $previous = $segments[$index - 1] ?? '';
            if ('' !== $previous && 1 !== preg_match('/\s$/', $previous)) {
                $prefix = (string) array_pop($out);
            }

            foreach ($dynamicPrefixes as $old => $new) {
                if (str_starts_with($prefix, $old)) {
                    $prefix = $new.substr($prefix, strlen($old));

                    break;
                }
            }
            $prefix = $map[$prefix] ?? $prefix;

            // Dentro de la expresion, los literales de cadena son los que
            // llevan el nombre de la clase: `'btn--primary'` -> `'btn-primary'`.
            $expression = preg_replace_callback(
                '/\'([^\']*)\'/',
                static fn (array $m): string => "'".($map[$m[1]] ?? $m[1])."'",
                $segment,
            ) ?? $segment;

            $out[] = $prefix.$expression;

            continue;
        }

        // ── Texto: clases sueltas ──────────────────────────────────────
        $pieces = preg_split('/(\s+)/', $segment, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (false === $pieces) {
            continue;
        }

        foreach ($pieces as $piece) {
            if ('' === $piece || '' === trim($piece)) {
                continue;
            }
            $out[] = $map[$piece] ?? $piece;
        }
    }

    // Duplicados: varias clases viejas se traducen a la misma nueva (`grid` y
    // `grid--2` van a `row g-3`). Repetir una clase no cambia nada en CSS, pero
    // ensucia el HTML y hace la plantilla mas dificil de leer de lo que va a
    // ser. Se eliminan conservando el orden.
    return implode(' ', array_values(array_unique($out)));
}

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/templates', FilesystemIterator::SKIP_DOTS),
);

$changed = 0;
foreach ($files as $file) {
    if (!$file instanceof SplFileInfo || 'twig' !== $file->getExtension()) {
        continue;
    }

    $path = $file->getPathname();
    $original = file_get_contents($path);
    if (false === $original) {
        fwrite(STDERR, "No se pudo leer {$path}\n");

        continue;
    }

    $updated = preg_replace_callback(
        '/class="([^"]*)"/',
        static fn (array $m): string => 'class="'.replaceClassTokens($m[1], $map, $dynamicPrefixes).'"',
        $original,
    );

    if ($updated === $original) {
        continue;
    }

    ++$changed;
    echo '  ' . str_replace($root . '/', '', $path) . "\n";

    if (!$dryRun) {
        file_put_contents($path, $updated);
    }
}

echo $dryRun
    ? "\n[simulacion] {$changed} plantilla(s) cambiarian\n"
    : "\n{$changed} plantilla(s) actualizadas\n";
