<?php

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Interaction\Bounds;
use KoreUi\Calendar\Interaction\MutationValidator;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;

require_once __DIR__.'/helpers.php';

/*
| Los límites de fecha que el servidor acepta de un navegador: los duros (años 1-9999)
| y la ventana por defecto, 200 años a cada lado de hoy, que `valid_range` sustituye.
*/

beforeEach(function () {
    app()->setLocale('es');
    CarbonImmutable::setTestNow('2026-10-16 10:00:00 UTC');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function bnRango(?string $inicio, ?string $fin): DateRange
{
    // Como lo construye CalendarSettings: un extremo sin escribir cae en el límite duro; el fin incluye ese día.
    return new DateRange(
        $inicio === null ? Marker::fromTimestamp(Bounds::MIN) : Marker::parse($inicio),
        $fin === null ? Marker::fromTimestamp(Bounds::MAX) : Marker::parse($fin)->addDays(1),
    );
}

// ── La ventana ─────────────────────────────────────────────────────────

it('sin valid_range la ventana son 200 años a cada lado de hoy', function () {
    $ventana = Bounds::window();

    expect($ventana->start->toDateString())->toBe('1826-01-01')
        ->and($ventana->end->toDateString())->toBe('2227-01-01')
        ->and(Bounds::WINDOW_YEARS)->toBe(200);
});

it('la ventana sigue a «hoy»', function () {
    CarbonImmutable::setTestNow('2040-06-01 00:00:00 UTC');

    expect(Bounds::window()->start->toDateString())->toBe('1840-01-01')
        ->and(Bounds::window()->end->toDateString())->toBe('2241-01-01');
});

it('los límites duros siguen siendo los años 1 a 9999', function () {
    expect(Bounds::contains(Marker::parse('0001-01-01')->ts, Marker::parse('9999-12-31 23:59:59')->ts))->toBeTrue()
        ->and(Bounds::contains(Bounds::MAX))->toBeFalse()
        ->and(Bounds::contains(Bounds::MIN - 1))->toBeFalse();
});

it('valid_range sustituye a la ventana: la amplía o la reduce', function () {
    // Ampliar: una agenda histórica.
    $ampliada = Bounds::window(bnRango('1500-01-01', '2100-12-31'));
    // Reducir: este año.
    $reducida = Bounds::window(bnRango('2026-01-01', '2026-12-31'));

    expect($ampliada->start->toDateString())->toBe('1500-01-01')
        ->and($ampliada->end->toDateString())->toBe('2101-01-01')
        ->and($reducida->start->toDateString())->toBe('2026-01-01')
        ->and($reducida->end->toDateString())->toBe('2027-01-01');
});

it('un extremo sin escribir cae a la ventana, no al año 9999', function () {
    $desde = Bounds::window(bnRango('2026-01-01', null));
    $hasta = Bounds::window(bnRango(null, '2026-12-31'));

    expect($desde->start->toDateString())->toBe('2026-01-01')
        ->and($desde->end->toDateString())->toBe('2227-01-01')
        ->and($hasta->start->toDateString())->toBe('1826-01-01')
        ->and($hasta->end->toDateString())->toBe('2027-01-01');
});

it('un extremo abierto no puede quedar al revés que el escrito: se abre hasta el límite duro', function () {
    $lejano = Bounds::window(bnRango('2300-01-01', null));
    $remoto = Bounds::window(bnRango(null, '1500-12-31'));

    expect($lejano->start->toDateString())->toBe('2300-01-01')
        ->and($lejano->end->ts)->toBe(Bounds::MAX)
        ->and($remoto->start->ts)->toBe(Bounds::MIN)
        ->and($remoto->end->toDateString())->toBe('1501-01-01');
});

// ── Fechas que llegan de fuera ─────────────────────────────────────────

it('acepta una fecha real dentro de la ventana', function (string $fecha) {
    expect(Bounds::acceptsDate($fecha))->toBeTrue();
})->with(['hoy' => ['2026-10-16'], 'bisiesto' => ['2028-02-29'], 'borde bajo' => ['1826-01-01'], 'borde alto' => ['2226-12-31']]);

it('rechaza lo que no es una fecha real o cae fuera de la ventana', function (mixed $fecha) {
    expect(Bounds::acceptsDate($fecha))->toBeFalse();
})->with([
    'texto' => ['foo'],
    'etiqueta' => ['<script>alert(1)</script>'],
    'mes imposible' => ['2026-13-45'],
    '31 de febrero' => ['2026-02-31'],
    '29 de febrero sin bisiesto' => ['2027-02-29'],
    'año cero' => ['0000-00-00'],
    'año 1' => ['0001-01-01'],
    'año 50' => ['0050-05-05'],
    'año 9999' => ['9999-12-31'],
    'año 10000' => ['10000-01-01'],
    'justo tras la ventana' => ['2227-01-01'],
    'justo antes de la ventana' => ['1825-12-31'],
    'relativa' => ['+999999 years'],
    'timestamp' => ['@999999999999'],
    'con hora' => ['2026-10-16 10:00:00'],
    'con salto de línea' => ["2026-10-16\n"],
    'año negativo' => ['-0001-01-01'],
    'larga' => [str_repeat('2', 100_000)],
    'vacía' => [''],
    'no es un string' => [20261016],
    'lista' => [['2026-10-16']],
    'null' => [null],
]);

it('una fecha acepta la ventana que pone valid_range', function () {
    expect(Bounds::acceptsDate('1600-03-01'))->toBeFalse()
        ->and(Bounds::acceptsDate('1600-03-01', bnRango('1500-01-01', '1700-12-31')))->toBeTrue()
        ->and(Bounds::acceptsDate('2026-10-16', bnRango('1500-01-01', '1700-12-31')))->toBeFalse();
});

// ── Lo que valida el servidor al mover ─────────────────────────────────

it('mover o redimensionar a siglos de distancia se rechaza sin valid_range', function (string $destino) {
    $vista = [ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00')];

    expect(ixValidateMove($vista, 1, $destino))->toBe('Fuera del rango permitido');
})->with(['año 3000' => ['3000-01-01 10:00'], 'año 9999' => ['9999-06-01 10:00'], 'año 1500' => ['1500-01-01 10:00']]);

it('dentro de la ventana se mueve sin valid_range', function () {
    $vista = [ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00')];

    expect(ixValidateMove($vista, 1, '2100-01-01 10:00'))->toBeNull()
        ->and(ixValidateMove($vista, 1, '1900-01-01 10:00'))->toBeNull();
});

it('valid_range amplía la ventana para un calendario histórico o de ciencia ficción', function () {
    $vista = [ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00')];

    expect(ixValidateMove($vista, 1, '1500-01-01 10:00', ['interaction.valid_range' => ['start' => '1400-01-01', 'end' => '2100-12-31']]))->toBeNull()
        ->and(ixValidateMove($vista, 1, '3000-01-01 10:00', ['interaction.valid_range' => ['start' => '2000-01-01', 'end' => '3100-12-31']]))->toBeNull()
        // Y solo hasta donde ella dice.
        ->and(ixValidateMove($vista, 1, '3200-01-01 10:00', ['interaction.valid_range' => ['start' => '2000-01-01', 'end' => '3100-12-31']]))->toBe('Fuera del rango permitido');
});

it('valid_range reduce la ventana', function () {
    $vista = [ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00')];
    $rango = ['interaction.valid_range' => ['start' => '2026-10-12', 'end' => '2026-10-18']];

    expect(ixValidateMove($vista, 1, '2026-10-15 10:00', $rango))->toBeNull()
        ->and(ixValidateMove($vista, 1, '2100-01-01 10:00', $rango))->toBe('Fuera del rango permitido');
});

it('un valid_range con un solo extremo no abre el otro hasta el año 9999', function () {
    $vista = [ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00')];
    $desde = ['interaction.valid_range' => ['start' => '2026-01-01', 'end' => null]];

    expect(ixValidateMove($vista, 1, '2100-01-01 10:00', $desde))->toBeNull()
        ->and(ixValidateMove($vista, 1, '5000-01-01 10:00', $desde))->toBe('Fuera del rango permitido');
});

it('el rango de una selección también se acota a la ventana', function () {
    $validador = new MutationValidator(ixSettings());
    $seleccion = ixBuilder()->select(ixMarker('3000-01-01 10:00'), ixMarker('3000-01-01 11:00'), false);

    expect($validador->validateSelection($seleccion))->toBe('Fuera del rango permitido');
});
