<?php

use KoreUi\Calendar\Tests\TestCase;

// La suite entera (más de 1 600 tests con Livewire y Testbench, que arrancan una aplicación cada uno) pasa
// de los 128 MB con los que PHP viene por defecto y acababa con un error fatal en el último archivo.
// La CI ya usa `memory_limit=-1`; esto es lo mismo para quien lanza `./vendor/bin/pest` a pelo.
ini_set('memory_limit', '512M');

uses(TestCase::class)->in(__DIR__);

// Los tests del lector iCal comprueban los mensajes en español, que es como se escribieron.
// Desde que esos mensajes salen de `lang/` siguen el idioma de la aplicación, y el de
// Testbench es el inglés: aquí se fija el español. Que también salen en inglés lo
// comprueba tests/ICal/ICalIdiomasTest.php, que lo cambia por su cuenta.
uses()->beforeEach(fn () => app()->setLocale('es'))->in('ICal');

require_once __DIR__.'/Layout/helpers.php';
