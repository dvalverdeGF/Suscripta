<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documents\Infrastructure\Storage;

use App\Documents\Domain\Exception\DocumentStorageException;
use App\Documents\Infrastructure\Storage\LocalDocumentStorage;

use function basename;
use function file_exists;
use function is_dir;
use function mkdir;

use PHPUnit\Framework\TestCase;

use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * El almacenamiento local es el único sitio donde el sistema escribe ficheros
 * que vienen de fuera. La prueba que de verdad importa aquí es la de **travesía
 * de rutas**: un adjunto llamado `../../.env` no puede escapar del directorio.
 */
final class LocalDocumentStorageTest extends TestCase
{
    private string $directory;
    private LocalDocumentStorage $storage;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/suscripta-storage-'.uniqid();
        mkdir($this->directory, 0o770, true);
        $this->storage = new LocalDocumentStorage($this->directory);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testItReportsItsDriver(): void
    {
        self::assertSame('local', $this->storage->driver());
    }

    public function testItWritesAndReadsBackTheContents(): void
    {
        $this->storage->write('org/ab/cd/hash.pdf', 'contenido de la factura');

        self::assertTrue($this->storage->exists('org/ab/cd/hash.pdf'));
        self::assertSame('contenido de la factura', $this->storage->read('org/ab/cd/hash.pdf'));
    }

    public function testItCreatesTheIntermediateDirectories(): void
    {
        $this->storage->write('a/b/c/d/e/f.pdf', 'x');

        self::assertTrue(is_dir($this->directory.'/a/b/c/d/e'));
    }

    public function testItOverwritesAnExistingKey(): void
    {
        $this->storage->write('same.pdf', 'primero');
        $this->storage->write('same.pdf', 'segundo');

        self::assertSame('segundo', $this->storage->read('same.pdf'));
    }

    public function testItReportsAMissingKeyAsAbsent(): void
    {
        self::assertFalse($this->storage->exists('no/existe.pdf'));
    }

    public function testReadingAMissingKeyThrows(): void
    {
        $this->expectException(DocumentStorageException::class);

        $this->storage->read('no/existe.pdf');
    }

    public function testItDeletesAStoredFile(): void
    {
        $this->storage->write('borrame.pdf', 'x');

        $this->storage->delete('borrame.pdf');

        self::assertFalse($this->storage->exists('borrame.pdf'));
    }

    public function testDeletingAMissingFileIsSilent(): void
    {
        $this->storage->delete('no/existe.pdf');

        self::assertFalse($this->storage->exists('no/existe.pdf'));
    }

    public function testItRejectsAnEmptyKey(): void
    {
        $this->expectException(DocumentStorageException::class);

        $this->storage->write('', 'x');
    }

    public function testItRejectsAParentDirectoryTraversal(): void
    {
        $this->expectException(DocumentStorageException::class);

        $this->storage->write('../../.env', 'x');
    }

    public function testItRejectsATraversalHiddenInTheMiddleOfTheKey(): void
    {
        $this->expectException(DocumentStorageException::class);

        $this->storage->write('org/../../etc/passwd', 'x');
    }

    public function testItRejectsANullByte(): void
    {
        $this->expectException(DocumentStorageException::class);

        $this->storage->write("org/factura\0.pdf", 'x');
    }

    public function testItRejectsATraversalOnRead(): void
    {
        $this->expectException(DocumentStorageException::class);

        $this->storage->read('../secret');
    }

    public function testItRejectsATraversalOnDelete(): void
    {
        $this->expectException(DocumentStorageException::class);

        $this->storage->delete('../secret');
    }

    public function testItRejectsATraversalOnExists(): void
    {
        $this->expectException(DocumentStorageException::class);

        $this->storage->exists('../secret');
    }

    public function testItDoesNotWriteOutsideTheRootDirectory(): void
    {
        $outside = $this->directory.'-escaped.txt';

        try {
            $this->storage->write('../'.basename($outside), 'x');
            self::fail('La travesía de rutas debería haber sido rechazada.');
        } catch (DocumentStorageException) {
            self::assertFalse(file_exists($outside));
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        if (false === $entries) {
            return;
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);

                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
