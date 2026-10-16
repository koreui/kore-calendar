<?php

use KoreUi\Calendar\Tests\TestCase;

// La suite entera (más de 1 600 tests con Livewire y Testbench, que arrancan una aplicación cada uno) pasa
// de los 128 MB con los que PHP viene por defecto y acababa con un error fatal en el último archivo.
// La CI ya usa `memory_limit=-1`; esto es lo mismo para quien lanza `./vendor/bin/pest` a pelo.
ini_set('memory_limit', '512M');

uses(TestCase::class)->in(__DIR__);

require_once __DIR__.'/Layout/helpers.php';
