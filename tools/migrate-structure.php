<?php

declare(strict_types=1);

/**
 * Migracion **estructural** de las plantillas del backoffice.
 *
 * El script de clases (`migrate-templates.php`) renombra las clases CSS. Este
 * hace el resto: cambiar el layout del que heredan las pantallas, renombrar los
 * bloques y reordenar la cabecera de pagina.
 *
 * Lo que cambia:
 *
 *   1. `{% extends 'layout/app.html.twig' %}` -> `{% extends 'backoffice/base.html.twig' %}`
 *   2. `{% block main %}`                    -> `{% block content %}`
 *   3. La cabecera de pagina pasa de:
 *
 *          <div class="bo-page-header">
 *              <div class="bo-page-header__title">
 *                  <h1>Titulo</h1>
 *                  <p class="bo-page-header__subtitle">Subtitulo</p>
 *              </div>
 *              <div class="bo-page-header__actions">…</div>
 *          </div>
 *
 *      a:
 *
 *          <div class="bo-page-header">
 *              <h1 class="bo-page-header__title">Titulo</h1>
 *              <p class="bo-page-header__subtitle">Subtitulo</p>
 *              <div class="bo-page-header__actions">…</div>
 *          </div>
 *
 *      porque en el estilo de referencia `.bo-page-header__title` **es** el
 *      `<h1>`, no un contenedor: el subtitulo va como hermano.
 *
 * Uso: php tools/migrate-structure.php [--dry-run]
 */

$root = dirname(__DIR__);
$dryRun = in_array('--dry-run', $argv, true);

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root.'/templates', FilesystemIterator::SKIP_DOTS),
);

$changed = 0;

foreach ($files as $file) {
    if (!$file instanceof SplFileInfo || 'twig' !== $file->getExtension()) {
        continue;
    }

    $path = $file->getPathname();
    $original = file_get_contents($path);
    if (false === $original) {
        continue;
    }

    $updated = $original;

    // ── 1. Layout ───────────────────────────────────────────────────────
    $updated = str_replace(
        "{% extends 'layout/app.html.twig' %}",
        "{% extends 'backoffice/base.html.twig' %}",
        $updated,
    );

    // ── 2. Bloque de contenido ──────────────────────────────────────────
    $updated = str_replace('{% block main %}', '{% block content %}', $updated);

    // ── 2.b. Barra de acciones ─────────────────────────────────────────
    //
    // El layout viejo tenia `topbar_actions` (acciones en la barra superior).
    // El nuevo las pone en `page_actions`, la barra pegada al pie: las
    // acciones de una ficha se ejecutan **al terminar de recorrerla**.
    $updated = str_replace('{% block topbar_actions %}', '{% block page_actions %}', $updated);

    // ── 3. Cabecera de pagina ───────────────────────────────────────────
    //
    // El contenedor `bo-page-header__title` desaparece: en el estilo de
    // referencia el `<h1>` **es** la clase. El titulo y el subtitulo se agrupan
    // en `bo-page-header__heading`, porque la cabecera es un flex de tres
    // zonas (titulo | acciones) y el subtitulo pertenece al titulo: suelto
    // entre los dos, el `space-between` lo colocaria en el centro de la
    // pantalla, a media pagina del titular al que explica.
    $updated = preg_replace_callback(
        '/<div class="bo-page-header__title">\s*(<h1[^>]*>.*?<\/h1>)\s*(<p class="bo-page-header__subtitle">.*?<\/p>)?\s*<\/div>/s',
        static function (array $m): string {
            $title = $m[1];
            $subtitle = $m[2] ?? '';

            if ('' === trim($subtitle)) {
                return $title;
            }

            return '<div class="bo-page-header__heading">'.$title.$subtitle.'</div>';
        },
        $updated,
    ) ?? $updated;

    // El `<h1>` que queda sin clase propia recibe la del estilo.
    $updated = preg_replace(
        '/<h1>(.*?)<\/h1>/s',
        '<h1 class="bo-page-header__title">$1</h1>',
        $updated,
    ) ?? $updated;

    if ($updated === $original) {
        continue;
    }

    ++$changed;
    echo '  '.str_replace($root.'/', '', $path)."\n";

    if (!$dryRun) {
        file_put_contents($path, $updated);
    }
}

echo $dryRun
    ? "\n[simulacion] {$changed} plantilla(s) cambiarian\n"
    : "\n{$changed} plantilla(s) actualizadas\n";
