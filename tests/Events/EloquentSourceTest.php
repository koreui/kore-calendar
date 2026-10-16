<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventCollector;
use KoreUi\Calendar\Events\EventNormalizer;
use KoreUi\Calendar\Events\EventSource;
use KoreUi\Calendar\Events\Sources\ArraySource;
use KoreUi\Calendar\Events\Sources\EloquentSource;
use KoreUi\Calendar\Tests\Events\Fixtures\Cita;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\WallClock;

/*
 * SQLite en memoria. Las fechas se guardan como 'Y-m-d H:i:s' en UTC (la zona de
 * la aplicación en Testbench), que es lo que hace Eloquent en una instalación
 * normal.
 */

beforeEach(function () {
    Schema::create('citas', function ($table) {
        $table->id();
        $table->string('titulo');
        $table->dateTime('starts_at');
        $table->dateTime('ends_at')->nullable();
        $table->unsignedInteger('resource_id')->nullable();
    });
});

function evCita(string $titulo, string $start, ?string $end = null, ?int $recurso = null): Cita
{
    return Cita::create(['titulo' => $titulo, 'starts_at' => $start, 'ends_at' => $end, 'resource_id' => $recurso]);
}

function evTitulos(iterable $eventos): array
{
    $t = array_map(fn ($e) => $e instanceof CalendarEvent ? $e->title : $e['title'], [...$eventos]);
    sort($t);

    return $t;
}

function evMapa(): Closure
{
    return fn (Model $m) => $m->toCalendarEvent();
}

/** El rango del 12 al 19 de octubre de 2026 en UTC. */
function evDesde(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-12 00:00:00', 'UTC');
}

function evHasta(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-19 00:00:00', 'UTC');
}

/** Filas de todo tipo alrededor del rango [12-oct, 19-oct). */
function evSembrar(): void
{
    // Con fin
    evCita('dentro', '2026-10-14 10:00:00', '2026-10-14 11:00:00');
    evCita('antes', '2026-10-01 10:00:00', '2026-10-01 11:00:00');
    evCita('despues', '2026-11-01 10:00:00', '2026-11-01 11:00:00');
    evCita('cruza el inicio', '2026-10-11 20:00:00', '2026-10-12 08:00:00');
    evCita('cruza el fin', '2026-10-18 20:00:00', '2026-10-19 08:00:00');
    evCita('contiene todo', '2026-10-01 00:00:00', '2026-11-30 00:00:00');
    evCita('acaba justo en el inicio', '2026-10-11 23:00:00', '2026-10-12 00:00:00');
    evCita('empieza justo en el fin', '2026-10-19 00:00:00', '2026-10-19 01:00:00');
    evCita('empieza al inicio', '2026-10-12 00:00:00', '2026-10-12 01:00:00');
    evCita('acaba al final', '2026-10-18 23:00:00', '2026-10-19 00:00:00');

    // Sin fin (NULL)
    evCita('abierto dentro', '2026-10-15 09:00:00');
    evCita('abierto 30 min antes', '2026-10-11 23:30:00');
    evCita('abierto 2 días antes', '2026-10-10 00:00:00');
    evCita('abierto justo en el fin', '2026-10-19 00:00:00');
    evCita('abierto después', '2026-10-25 00:00:00');
}

// ── Qué filas trae ───────────────────────────────────────────────────

it('trae las filas que pueden intersecar el rango y deja fuera el resto', function () {
    evSembrar();

    $source = new EloquentSource(Cita::query(), evMapa());
    $titulos = evTitulos($source->events(evDesde(), evHasta()));

    expect($source)->toBeInstanceOf(EventSource::class)
        ->and($titulos)->toBe([
            'abierto 30 min antes',
            'abierto dentro',
            'acaba al final',
            'contiene todo',
            'cruza el fin',
            'cruza el inicio',
            'dentro',
            'empieza al inicio',
        ]);
});

it('el pipeline completo (fuente + normalizador + colector) da el mismo resultado que mirar todas las filas', function () {
    evSembrar();

    $reloj = new WallClock('UTC');
    $colector = new EventCollector($reloj, new EventNormalizer($reloj));
    $rango = new DateRange(Marker::of(2026, 10, 12), Marker::of(2026, 10, 19));

    $conFiltro = $colector->collect([new EloquentSource(Cita::query(), evMapa())], $rango);
    $sinFiltro = $colector->collect(
        [new ArraySource(Cita::all()->map(fn ($m) => $m->toCalendarEvent())->all())],
        $rango,
    );

    $titulos = fn (array $instancias) => evTitulos(array_map(fn ($i) => $i->event, $instancias));

    expect($titulos($conFiltro))->toBe($titulos($sinFiltro))
        ->and($titulos($conFiltro))->not->toBe([]);
});

it('conserva las condiciones de la consulta original', function () {
    evCita('de la sala 1', '2026-10-14 10:00:00', '2026-10-14 11:00:00', 1);
    evCita('de la sala 2', '2026-10-14 10:00:00', '2026-10-14 11:00:00', 2);
    evCita('de la sala 1 fuera', '2026-12-14 10:00:00', '2026-12-14 11:00:00', 1);

    $source = new EloquentSource(Cita::query()->where('resource_id', 1), evMapa());

    expect(evTitulos($source->events(evDesde(), evHasta())))->toBe(['de la sala 1']);
});

it('un orWhere en la consulta original no se escapa del filtro de rango', function () {
    evCita('dentro', '2026-10-14 10:00:00', '2026-10-14 11:00:00', 1);
    evCita('fuera del rango y de la sala 9', '2026-12-14 10:00:00', '2026-12-14 11:00:00', 9);

    // Sin agrupar, el OR de aquí dentro anularía la condición de rango de las filas de la sala 1.
    $source = new EloquentSource(Cita::query()->where(fn ($q) => $q->where('resource_id', 1)->orWhere('resource_id', 9)), evMapa());

    expect(evTitulos($source->events(evDesde(), evHasta())))->toBe(['dentro']);
});

it('no muta el Builder que se le pasa: una segunda consulta no arrastra el rango de la primera', function () {
    evCita('octubre', '2026-10-14 10:00:00', '2026-10-14 11:00:00');
    evCita('noviembre', '2026-11-14 10:00:00', '2026-11-14 11:00:00');

    $base = Cita::query();
    $source = new EloquentSource($base, evMapa());

    $octubre = evTitulos($source->events(evDesde(), evHasta()));
    $noviembre = evTitulos($source->events(CarbonImmutable::parse('2026-11-09', 'UTC'), CarbonImmutable::parse('2026-11-16', 'UTC')));

    expect($octubre)->toBe(['octubre'])
        ->and($noviembre)->toBe(['noviembre'])
        ->and($base->toBase()->wheres)->toBe([]);
});

it('un Closure de consulta se llama en cada consulta y da una query nueva', function () {
    evCita('dentro', '2026-10-14 10:00:00', '2026-10-14 11:00:00');

    $llamadas = 0;
    $source = new EloquentSource(function () use (&$llamadas) {
        $llamadas++;

        return Cita::query();
    }, evMapa());

    $source->events(evDesde(), evHasta());
    $source->events(evDesde(), evHasta());
    $source->events(evDesde(), evHasta());

    expect($llamadas)->toBe(3);
});

it('un Closure de consulta puede depender de algo que cambia entre llamadas', function () {
    evCita('de la sala 1', '2026-10-14 10:00:00', '2026-10-14 11:00:00', 1);
    evCita('de la sala 2', '2026-10-14 10:00:00', '2026-10-14 11:00:00', 2);

    $sala = 1;
    $source = new EloquentSource(function () use (&$sala) {
        return Cita::query()->where('resource_id', $sala);
    }, evMapa());

    expect(evTitulos($source->events(evDesde(), evHasta())))->toBe(['de la sala 1']);

    $sala = 2;

    expect(evTitulos($source->events(evDesde(), evHasta())))->toBe(['de la sala 2']);
});

it('un Closure de consulta que no devuelve un Builder lanza', function () {
    $source = new EloquentSource(fn () => 'no soy una consulta', evMapa());

    expect(fn () => $source->events(evDesde(), evHasta()))
        ->toThrow(UnexpectedValueException::class, 'debe devolver un Illuminate\Database\Eloquent\Builder y devolvió string');
});

// ── Mapeo ────────────────────────────────────────────────────────────

it('$map convierte cada modelo en un CalendarEvent', function () {
    evCita('dentro', '2026-10-14 10:00:00', '2026-10-14 11:00:00', 4);

    $eventos = (new EloquentSource(Cita::query(), evMapa()))->events(evDesde(), evHasta());

    expect($eventos)->toHaveCount(1)
        ->and($eventos[0])->toBeInstanceOf(CalendarEvent::class)
        ->and($eventos[0]->title)->toBe('dentro')
        ->and($eventos[0]->resourceIds)->toBe([4])
        ->and($eventos[0]->start)->toBeInstanceOf(DateTimeInterface::class)
        ->and($eventos[0]->start->format('Y-m-d H:i:s'))->toBe('2026-10-14 10:00:00');
});

it('$map puede devolver arrays, que el colector pasa por fromArray', function () {
    evCita('dentro', '2026-10-14 10:00:00', '2026-10-14 11:00:00', 4);

    $source = new EloquentSource(
        Cita::query(),
        fn (Cita $c) => ['id' => $c->id, 'title' => $c->titulo, 'start' => $c->starts_at, 'end' => $c->ends_at, 'resourceId' => $c->resource_id],
    );

    $reloj = new WallClock('UTC');
    $out = (new EventCollector($reloj, new EventNormalizer($reloj)))
        ->collect([$source], new DateRange(Marker::of(2026, 10, 12), Marker::of(2026, 10, 19)));

    expect($out)->toHaveCount(1)->and($out[0]->event->resourceIds)->toBe([4]);
});

it('sin filas devuelve una lista vacía', function () {
    expect((new EloquentSource(Cita::query(), evMapa()))->events(evDesde(), evHasta()))->toBe([]);
});

// ── Sin columna de fin ───────────────────────────────────────────────

it('sin columna de fin: start >= from − 1 día y start < to', function () {
    Schema::create('avisos', function ($table) {
        $table->id();
        $table->string('titulo');
        $table->dateTime('cuando');
    });

    $aviso = fn (string $titulo, string $cuando) => DB::table('avisos')->insert(['titulo' => $titulo, 'cuando' => $cuando]);
    $aviso('dentro', '2026-10-14 10:00:00');
    $aviso('justo al inicio', '2026-10-12 00:00:00');
    $aviso('la víspera, dentro del margen', '2026-10-11 12:00:00');
    $aviso('justo un día antes', '2026-10-11 00:00:00');
    $aviso('dos días antes', '2026-10-10 00:00:00');
    $aviso('justo al fin', '2026-10-19 00:00:00');
    $aviso('último segundo', '2026-10-18 23:59:59');

    $modelo = new class extends Model
    {
        protected $table = 'avisos';

        public $timestamps = false;
    };

    $source = new EloquentSource($modelo::query(), fn ($m) => ['id' => $m->id, 'title' => $m->titulo, 'start' => $m->cuando], 'cuando', null);

    // El margen de un día es deliberado: lo que no interseca lo descarta el colector.
    expect(evTitulos($source->events(evDesde(), evHasta())))->toBe([
        'dentro', 'justo al inicio', 'justo un día antes', 'la víspera, dentro del margen', 'último segundo',
    ]);
});

// ── Zona horaria ─────────────────────────────────────────────────────

it('el rango en la zona de la vista se compara contra fechas guardadas en la zona de la aplicación', function () {
    // Vista en Madrid (CEST, +02:00): el día 16 va de 2026-10-15 22:00Z a 2026-10-16 22:00Z.
    evCita('la víspera 22:30Z, ya es el 16 en Madrid', '2026-10-15 22:30:00', '2026-10-15 23:30:00');
    evCita('el 16 a mediodía', '2026-10-16 10:00:00', '2026-10-16 11:00:00');
    evCita('a las 22:30Z del 16 ya es el 17 en Madrid', '2026-10-16 22:30:00', '2026-10-16 23:30:00');
    evCita('acaba justo a las 22:00Z de la víspera', '2026-10-15 21:00:00', '2026-10-15 22:00:00');

    $from = CarbonImmutable::parse('2026-10-16 00:00:00', 'Europe/Madrid');
    $to = CarbonImmutable::parse('2026-10-17 00:00:00', 'Europe/Madrid');

    // Si se ligara '2026-10-16 00:00:00' tal cual contra una columna en UTC, la primera
    // fila quedaría fuera (acaba a las 23:30 del 15) y la tercera dentro.
    expect(evTitulos((new EloquentSource(Cita::query(), evMapa()))->events($from, $to)))->toBe([
        'el 16 a mediodía',
        'la víspera 22:30Z, ya es el 16 en Madrid',
    ]);
});

it('el rango no cambia de significado según la zona del objeto que se pasa', function () {
    evCita('dentro', '2026-10-16 10:00:00', '2026-10-16 11:00:00');
    evCita('fuera', '2026-10-17 10:00:00', '2026-10-17 11:00:00');

    $source = new EloquentSource(Cita::query(), evMapa());

    // El mismo instante expresado en tres zonas.
    foreach (['UTC', 'Europe/Madrid', 'Pacific/Auckland'] as $zona) {
        $from = CarbonImmutable::parse('2026-10-16 00:00:00', 'UTC')->setTimezone($zona);
        $to = CarbonImmutable::parse('2026-10-17 00:00:00', 'UTC')->setTimezone($zona);

        expect(evTitulos($source->events($from, $to)))->toBe(['dentro']);
    }
});

it('el pipeline completo en Madrid encuentra el evento que cruza la medianoche local', function () {
    // 22:30Z–23:30Z del 15 son las 00:30–01:30 del 16 en Madrid.
    evCita('madrugada', '2026-10-15 22:30:00', '2026-10-15 23:30:00');
    evCita('la tarde anterior', '2026-10-15 15:00:00', '2026-10-15 16:00:00');

    $reloj = new WallClock('Europe/Madrid');
    $colector = new EventCollector($reloj, new EventNormalizer($reloj));
    $dia = new DateRange(Marker::of(2026, 10, 16), Marker::of(2026, 10, 17));

    $out = $colector->collect([new EloquentSource(Cita::query(), evMapa())], $dia);

    expect(array_map(fn ($i) => $i->event->title, $out))->toBe(['madrugada'])
        ->and((string) $out[0]->range->start)->toBe('2026-10-16 00:30:00');
});

it('con columnas de fecha (sin hora) el filtro nunca descarta un día completo que sí cae en la vista', function (string $zona) {
    Schema::create('dias', function ($table) {
        $table->id();
        $table->string('nombre');
        $table->date('inicio');
        $table->date('fin')->nullable();
    });

    $modelo = new class extends Model
    {
        protected $table = 'dias';

        public $timestamps = false;
    };

    $dia = fn (string $nombre, string $inicio, ?string $fin = null) => DB::table('dias')->insert(['nombre' => $nombre, 'inicio' => $inicio, 'fin' => $fin]);
    $dia('el 16', '2026-10-16');
    $dia('el 16 con fin', '2026-10-16', '2026-10-17');
    $dia('del 14 al 17 (cubre el 16)', '2026-10-14', '2026-10-17');
    $dia('del 16 al 20', '2026-10-16', '2026-10-20');
    $dia('el 3', '2026-10-03');

    $reloj = new WallClock($zona);
    $colector = new EventCollector($reloj, new EventNormalizer($reloj));
    $vista = new DateRange(Marker::of(2026, 10, 16), Marker::of(2026, 10, 17));

    $source = new EloquentSource($modelo::query(), fn ($m) => [
        'id' => $m->id, 'title' => $m->nombre, 'start' => $m->inicio, 'end' => $m->fin,
    ], 'inicio', 'fin');

    $titulos = array_map(fn ($i) => $i->event->title, $colector->collect([$source], $vista));
    sort($titulos);

    expect($titulos)->toBe(['del 14 al 17 (cubre el 16)', 'del 16 al 20', 'el 16', 'el 16 con fin']);
})->with(['UTC', 'Europe/Madrid', 'Asia/Tokyo', 'America/New_York', 'America/Los_Angeles', 'Pacific/Auckland']);

// ── Columnas cualificadas ────────────────────────────────────────────

it('cualifica las columnas con la tabla: una consulta con join no da columnas ambiguas', function () {
    Schema::create('salas', function ($table) {
        $table->id();
        $table->string('nombre');
        $table->dateTime('starts_at')->nullable();   // el mismo nombre que la tabla de citas
    });

    DB::table('salas')->insert(['nombre' => 'Sala 1', 'starts_at' => '2000-01-01 00:00:00']);
    evCita('dentro', '2026-10-14 10:00:00', '2026-10-14 11:00:00', 1);

    $query = Cita::query()->select('citas.*')->join('salas', 'salas.id', '=', 'citas.resource_id');

    expect(evTitulos((new EloquentSource($query, evMapa()))->events(evDesde(), evHasta())))->toBe(['dentro']);
});

it('una columna ya cualificada se respeta', function () {
    evCita('dentro', '2026-10-14 10:00:00', '2026-10-14 11:00:00');

    $source = new EloquentSource(Cita::query(), evMapa(), 'citas.starts_at', 'citas.ends_at');

    expect(evTitulos($source->events(evDesde(), evHasta())))->toBe(['dentro']);
});

it('constrain() es público y trabaja sobre cualquier Builder', function () {
    evCita('dentro', '2026-10-14 10:00:00', '2026-10-14 11:00:00');
    evCita('fuera', '2026-12-14 10:00:00', '2026-12-14 11:00:00');

    $query = Cita::query();
    EloquentSource::constrain($query, 'starts_at', 'ends_at', evDesde(), evHasta());

    expect($query->pluck('titulo')->all())->toBe(['dentro'])
        ->and($query)->toBeInstanceOf(Builder::class);
});
