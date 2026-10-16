<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventCollector;
use KoreUi\Calendar\Events\EventNormalizer;
use KoreUi\Calendar\Events\EventSource;
use KoreUi\Calendar\Events\Sources\EloquentSource;
use KoreUi\Calendar\Tests\Events\Fixtures\Cita;
use KoreUi\Calendar\Tests\Events\Fixtures\Recordatorio;
use KoreUi\Calendar\Tests\Events\Fixtures\Reserva;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\WallClock;

beforeEach(function () {
    Schema::create('citas', function ($table) {
        $table->id();
        $table->string('titulo');
        $table->dateTime('starts_at');
        $table->dateTime('ends_at')->nullable();
        $table->unsignedInteger('resource_id')->nullable();
    });

    Schema::create('reservas', function ($table) {
        $table->id();
        $table->string('nombre');
        $table->date('inicio');
        $table->date('fin')->nullable();
    });

    Schema::create('recordatorios', function ($table) {
        $table->id();
        $table->string('texto');
        $table->dateTime('starts_at');
    });
});

function evEntre(string $desde, string $hasta): array
{
    return [CarbonImmutable::parse($desde, 'UTC'), CarbonImmutable::parse($hasta, 'UTC')];
}

function evNuevaCita(string $titulo, string $start, ?string $end = null, ?int $recurso = null): Cita
{
    return Cita::create(['titulo' => $titulo, 'starts_at' => $start, 'ends_at' => $end, 'resource_id' => $recurso]);
}

// ── Valores por defecto ──────────────────────────────────────────────

it('las columnas por defecto son starts_at y ends_at', function () {
    $cita = new Cita;

    expect($cita->calendarStartColumn())->toBe('starts_at')
        ->and($cita->calendarEndColumn())->toBe('ends_at');
});

it('las columnas se pueden sobrescribir, incluida la de fin a null', function () {
    expect((new Reserva)->calendarStartColumn())->toBe('inicio')
        ->and((new Reserva)->calendarEndColumn())->toBe('fin')
        ->and((new Recordatorio)->calendarEndColumn())->toBeNull();
});

// ── scopeOverlapping ─────────────────────────────────────────────────

it('el scope overlapping trae las filas que pueden intersecar el rango', function () {
    evNuevaCita('dentro', '2026-10-14 10:00:00', '2026-10-14 11:00:00');
    evNuevaCita('antes', '2026-10-01 10:00:00', '2026-10-01 11:00:00');
    evNuevaCita('despues', '2026-11-01 10:00:00', '2026-11-01 11:00:00');
    evNuevaCita('cruza el inicio', '2026-10-11 20:00:00', '2026-10-12 08:00:00');
    evNuevaCita('acaba justo en el inicio', '2026-10-11 23:00:00', '2026-10-12 00:00:00');
    evNuevaCita('empieza justo en el fin', '2026-10-19 00:00:00', '2026-10-19 01:00:00');
    evNuevaCita('abierto', '2026-10-15 09:00:00');
    evNuevaCita('abierto hace dos días', '2026-10-10 09:00:00');

    [$from, $to] = evEntre('2026-10-12', '2026-10-19');

    expect(Cita::overlapping($from, $to)->orderBy('id')->pluck('titulo')->all())
        ->toBe(['dentro', 'cruza el inicio', 'abierto']);
});

it('el scope acepta cualquier DateTimeInterface', function () {
    evNuevaCita('dentro', '2026-10-14 10:00:00', '2026-10-14 11:00:00');

    $from = new DateTime('2026-10-12 00:00:00', new DateTimeZone('UTC'));
    $to = new DateTimeImmutable('2026-10-19 00:00:00', new DateTimeZone('UTC'));

    expect(Cita::overlapping($from, $to)->count())->toBe(1);
});

it('el scope se encadena con otras condiciones sin romperlas', function () {
    evNuevaCita('sala 1', '2026-10-14 10:00:00', '2026-10-14 11:00:00', 1);
    evNuevaCita('sala 2', '2026-10-14 10:00:00', '2026-10-14 11:00:00', 2);
    evNuevaCita('sala 1 fuera', '2026-12-14 10:00:00', '2026-12-14 11:00:00', 1);

    [$from, $to] = evEntre('2026-10-12', '2026-10-19');

    expect(Cita::where('resource_id', 1)->overlapping($from, $to)->pluck('titulo')->all())->toBe(['sala 1'])
        ->and(Cita::overlapping($from, $to)->where('resource_id', 2)->pluck('titulo')->all())->toBe(['sala 2']);
});

it('el scope devuelve un Builder encadenable', function () {
    [$from, $to] = evEntre('2026-10-12', '2026-10-19');

    expect(Cita::overlapping($from, $to))->toBeInstanceOf(Builder::class);
});

it('el scope usa las columnas del modelo y las cualifica con su tabla', function () {
    [$from, $to] = evEntre('2026-10-12', '2026-10-19');

    $sql = Reserva::overlapping($from, $to)->toSql();

    expect($sql)->toContain('"reservas"."inicio"')->toContain('"reservas"."fin"')->not->toContain('starts_at');
});

it('el scope respeta que el modelo no tenga columna de fin', function () {
    DB::table('recordatorios')->insert([
        ['texto' => 'dentro', 'starts_at' => '2026-10-14 10:00:00'],
        ['texto' => 'la víspera', 'starts_at' => '2026-10-11 12:00:00'],
        ['texto' => 'dos días antes', 'starts_at' => '2026-10-10 12:00:00'],
        ['texto' => 'después', 'starts_at' => '2026-10-19 00:00:00'],
    ]);

    [$from, $to] = evEntre('2026-10-12', '2026-10-19');

    expect(Recordatorio::overlapping($from, $to)->orderBy('id')->pluck('texto')->all())->toBe(['dentro', 'la víspera'])
        ->and(Recordatorio::overlapping($from, $to)->toSql())->not->toContain('IS NULL');
});

// ── calendarSource ───────────────────────────────────────────────────

it('calendarSource() devuelve una EloquentSource que mapea con toCalendarEvent', function () {
    evNuevaCita('dentro', '2026-10-14 10:00:00', '2026-10-14 11:00:00', 3);
    evNuevaCita('fuera', '2026-12-14 10:00:00', '2026-12-14 11:00:00');

    [$from, $to] = evEntre('2026-10-12', '2026-10-19');
    $source = Cita::calendarSource();
    $eventos = $source->events($from, $to);

    expect($source)->toBeInstanceOf(EloquentSource::class)->toBeInstanceOf(EventSource::class)
        ->and($eventos)->toHaveCount(1)
        ->and($eventos[0])->toBeInstanceOf(CalendarEvent::class)
        ->and($eventos[0]->title)->toBe('dentro')
        ->and($eventos[0]->resourceIds)->toBe([3]);
});

it('calendarSource() usa las columnas sobrescritas del modelo', function () {
    DB::table('reservas')->insert([
        ['nombre' => 'dentro', 'inicio' => '2026-10-14', 'fin' => '2026-10-16'],
        ['nombre' => 'fuera', 'inicio' => '2026-12-14', 'fin' => '2026-12-16'],
        ['nombre' => 'sin fin dentro', 'inicio' => '2026-10-15', 'fin' => null],
    ]);

    [$from, $to] = evEntre('2026-10-12', '2026-10-19');

    $nombres = array_map(fn ($e) => $e->title, Reserva::calendarSource()->events($from, $to));
    sort($nombres);

    expect($nombres)->toBe(['dentro', 'sin fin dentro']);
});

it('calendarSource() sin columna de fin consulta solo por inicio', function () {
    DB::table('recordatorios')->insert([
        ['texto' => 'dentro', 'starts_at' => '2026-10-14 10:00:00'],
        ['texto' => 'fuera', 'starts_at' => '2026-12-14 10:00:00'],
    ]);

    [$from, $to] = evEntre('2026-10-12', '2026-10-19');

    expect(array_map(fn ($e) => $e->title, Recordatorio::calendarSource()->events($from, $to)))->toBe(['dentro']);
});

it('calendarSource($constrain) añade condiciones modificando la consulta en sitio', function () {
    evNuevaCita('sala 1', '2026-10-14 10:00:00', '2026-10-14 11:00:00', 1);
    evNuevaCita('sala 2', '2026-10-14 10:00:00', '2026-10-14 11:00:00', 2);

    [$from, $to] = evEntre('2026-10-12', '2026-10-19');
    $source = Cita::calendarSource(function (Builder $q) {
        $q->where('resource_id', 2);
    });

    expect(array_map(fn ($e) => $e->title, $source->events($from, $to)))->toBe(['sala 2']);
});

it('calendarSource($constrain) también admite devolver la consulta', function () {
    evNuevaCita('sala 1', '2026-10-14 10:00:00', '2026-10-14 11:00:00', 1);
    evNuevaCita('sala 2', '2026-10-14 10:00:00', '2026-10-14 11:00:00', 2);

    [$from, $to] = evEntre('2026-10-12', '2026-10-19');
    $source = Cita::calendarSource(fn (Builder $q) => $q->where('resource_id', 1));

    expect(array_map(fn ($e) => $e->title, $source->events($from, $to)))->toBe(['sala 1']);
});

it('calendarSource($constrain) se evalúa en cada consulta, no una vez al crear la fuente', function () {
    evNuevaCita('sala 1', '2026-10-14 10:00:00', '2026-10-14 11:00:00', 1);
    evNuevaCita('sala 2', '2026-10-14 10:00:00', '2026-10-14 11:00:00', 2);

    [$from, $to] = evEntre('2026-10-12', '2026-10-19');
    $sala = 1;
    $source = Cita::calendarSource(function (Builder $q) use (&$sala) {
        $q->where('resource_id', $sala);
    });

    $primera = array_map(fn ($e) => $e->title, $source->events($from, $to));
    $sala = 2;
    $segunda = array_map(fn ($e) => $e->title, $source->events($from, $to));

    expect($primera)->toBe(['sala 1'])->and($segunda)->toBe(['sala 2']);
});

it('calendarSource() no acumula condiciones entre consultas', function () {
    evNuevaCita('octubre', '2026-10-14 10:00:00', '2026-10-14 11:00:00');
    evNuevaCita('noviembre', '2026-11-14 10:00:00', '2026-11-14 11:00:00');

    $source = Cita::calendarSource();

    expect(array_map(fn ($e) => $e->title, $source->events(...evEntre('2026-10-12', '2026-10-19'))))->toBe(['octubre'])
        ->and(array_map(fn ($e) => $e->title, $source->events(...evEntre('2026-11-09', '2026-11-16'))))->toBe(['noviembre']);
});

it('calendarSource() funciona de punta a punta con el colector (modelo con fechas de día completo)', function () {
    DB::table('reservas')->insert([
        ['nombre' => 'dos días', 'inicio' => '2026-10-14', 'fin' => '2026-10-16'],
        ['nombre' => 'acaba el 12', 'inicio' => '2026-10-10', 'fin' => '2026-10-12'],
        ['nombre' => 'sin fin', 'inicio' => '2026-10-18', 'fin' => null],
    ]);

    $reloj = new WallClock('Europe/Madrid');
    $colector = new EventCollector($reloj, new EventNormalizer($reloj));
    $semana = new DateRange(Marker::of(2026, 10, 12), Marker::of(2026, 10, 19));

    $out = $colector->collect([Reserva::calendarSource()], $semana);
    usort($out, fn ($a, $b) => $a->range->start->ts <=> $b->range->start->ts);

    expect(array_map(fn ($i) => $i->event->title, $out))->toBe(['dos días', 'sin fin'])
        ->and($out[0]->allDay)->toBeTrue()
        ->and((string) $out[0]->range->end)->toBe('2026-10-16 00:00:00')
        ->and($out[1]->hasEnd)->toBeFalse();
});
