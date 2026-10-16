<?php

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Events\CalendarEvent;

// ── make() y valores por defecto ─────────────────────────────────────

it('make() solo exige id, título e inicio y deja el resto en su valor neutro', function () {
    $e = CalendarEvent::make(id: 7, title: 'Revisión', start: '2026-10-16 10:00');

    expect($e->id)->toBe(7)
        ->and($e->title)->toBe('Revisión')
        ->and($e->start)->toBe('2026-10-16 10:00')
        ->and($e->end)->toBeNull()
        ->and($e->allDay)->toBeNull()
        ->and($e->resourceIds)->toBe([])
        ->and($e->groupId)->toBeNull()
        ->and($e->color)->toBeNull()
        ->and($e->url)->toBeNull()
        ->and($e->display)->toBe('auto')
        ->and($e->editable)->toBeNull()
        ->and($e->startEditable)->toBeNull()
        ->and($e->durationEditable)->toBeNull()
        ->and($e->overlap)->toBeNull()
        ->and($e->meta)->toBe([]);
});

it('conserva todo lo que se le da, sin tocarlo', function () {
    $start = CarbonImmutable::parse('2026-10-16 10:00', 'Europe/Madrid');
    $end = new DateTimeImmutable('2026-10-16 11:00');

    $e = CalendarEvent::make(
        id: 'a1', title: 'Taller', start: $start, end: $end, allDay: false, resourceIds: ['sala-1', 5],
        groupId: 'serie', color: 'chart-3', url: 'https://example.test/e/1', display: 'block',
        editable: true, startEditable: false, durationEditable: true, overlap: false, meta: ['aforo' => 12],
    );

    expect($e->start)->toBe($start)
        ->and($e->end)->toBe($end)
        ->and($e->allDay)->toBeFalse()
        ->and($e->resourceIds)->toBe(['sala-1', 5])
        ->and($e->groupId)->toBe('serie')
        ->and($e->color)->toBe('chart-3')
        ->and($e->url)->toBe('https://example.test/e/1')
        ->and($e->display)->toBe('block')
        ->and($e->editable)->toBeTrue()
        ->and($e->startEditable)->toBeFalse()
        ->and($e->durationEditable)->toBeTrue()
        ->and($e->overlap)->toBeFalse()
        ->and($e->meta)->toBe(['aforo' => 12]);
});

it('es inmutable', function () {
    $e = CalendarEvent::make(id: 1, title: 'x', start: '2026-10-16');

    expect(fn () => $e->title = 'otro')->toThrow(Error::class);
    expect(fn () => $e->resourceIds[] = 'sala')->toThrow(Error::class);
});

it('make() y el constructor tienen exactamente los mismos argumentos y en el mismo orden', function () {
    $nombres = fn (ReflectionFunctionAbstract $f) => array_map(fn ($p) => $p->getName(), $f->getParameters());

    $make = new ReflectionMethod(CalendarEvent::class, 'make');
    $constructor = new ReflectionMethod(CalendarEvent::class, '__construct');

    expect($nombres($make))->toBe($nombres($constructor));

    // También los valores por defecto: un `make()` que difiere en silencio es una trampa.
    foreach ($make->getParameters() as $i => $p) {
        $q = $constructor->getParameters()[$i];
        expect($p->isDefaultValueAvailable())->toBe($q->isDefaultValueAvailable(), $p->getName());

        if ($p->isDefaultValueAvailable()) {
            expect($p->getDefaultValue())->toBe($q->getDefaultValue(), $p->getName());
        }
    }
});

it('KEYS es la lista de argumentos del constructor', function () {
    $constructor = new ReflectionMethod(CalendarEvent::class, '__construct');
    $nombres = array_map(fn ($p) => $p->getName(), $constructor->getParameters());

    expect(CalendarEvent::KEYS)->toBe($nombres);
});

// ── Validación ───────────────────────────────────────────────────────

it('rechaza un id vacío', function (string|int $id) {
    expect(fn () => CalendarEvent::make(id: $id, title: 'x', start: '2026-10-16'))
        ->toThrow(InvalidArgumentException::class, 'el id no puede estar vacío');
})->with(['', '   ']);

it('un id 0 es un id', function (string|int $id) {
    expect(CalendarEvent::make(id: $id, title: 'x', start: '2026-10-16')->id)->toBe($id);
})->with([0, '0']);

it('rechaza un inicio o un fin en blanco, y distingue "sin fin" (null) de "fin vacío"', function () {
    expect(fn () => CalendarEvent::make(id: 1, title: 'x', start: ''))
        ->toThrow(InvalidArgumentException::class, '`start` no puede estar vacío');

    expect(fn () => CalendarEvent::make(id: 1, title: 'x', start: '2026-10-16', end: ' '))
        ->toThrow(InvalidArgumentException::class, 'Usa null si el evento no tiene fin');

    expect(CalendarEvent::make(id: 1, title: 'x', start: '2026-10-16', end: null)->end)->toBeNull();
});

it('valida display contra la lista cerrada', function (string $display) {
    expect(CalendarEvent::make(id: 1, title: 'x', start: '2026-10-16', display: $display)->display)->toBe($display);
})->with(['auto', 'block', 'list-item', 'background', 'none']);

it('un display desconocido lanza y dice cuáles hay', function (string $display) {
    expect(fn () => CalendarEvent::make(id: 9, title: 'x', start: '2026-10-16', display: $display))
        ->toThrow(InvalidArgumentException::class, "display «{$display}» no válido. Valores permitidos: auto, block, list-item, background, none.");
})->with(['inverse-background', 'Block', 'list_item', '']);

it('rechaza un groupId vacío', function () {
    expect(fn () => CalendarEvent::make(id: 1, title: 'x', start: '2026-10-16', groupId: ''))
        ->toThrow(InvalidArgumentException::class, 'Usa null si el evento no tiene grupo');
});

it('resourceIds: sin repetidos, reindexado y 1 es lo mismo que "1"', function () {
    $e = CalendarEvent::make(id: 1, title: 'x', start: '2026-10-16', resourceIds: [3 => 'a', 7 => 'b', 9 => 'a', 11 => 1, 12 => '1']);

    expect($e->resourceIds)->toBe(['a', 'b', 1]);
});

it('resourceIds solo admite string o int no vacíos', function (mixed $malo, string $tipo) {
    expect(fn () => CalendarEvent::make(id: 1, title: 'x', start: '2026-10-16', resourceIds: ['ok', $malo]))
        ->toThrow(InvalidArgumentException::class, "y llegó {$tipo}");
})->with([
    'null' => [null, 'null'],
    'array' => [['a'], 'array'],
    'float' => [1.5, 'float'],
    'string vacío' => ['', 'string'],
    'objeto' => [new stdClass, 'stdClass'],
]);

// ── fromArray ────────────────────────────────────────────────────────

it('fromArray construye lo mismo que make', function () {
    $datos = [
        'id' => 3, 'title' => 'Comida', 'start' => '2026-10-16 13:00', 'end' => '2026-10-16 14:00',
        'allDay' => false, 'resourceIds' => ['a', 'b'], 'groupId' => 'g', 'color' => 'primary', 'url' => '/x',
        'display' => 'list-item', 'editable' => true, 'startEditable' => false, 'durationEditable' => false,
        'overlap' => true, 'meta' => ['k' => 'v'],
    ];

    expect(CalendarEvent::fromArray($datos))->toEqual(CalendarEvent::make(...$datos));
});

it('fromArray rechaza una clave desconocida y lista las permitidas', function () {
    try {
        CalendarEvent::fromArray(['id' => 1, 'title' => 'x', 'start' => '2026-10-16', 'colour' => 'red']);
        $this->fail('Debía lanzar.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())
            ->toContain('clave desconocida «colour»')
            ->toContain('Permitidas: id, title, start, end, allDay, resourceIds, groupId, color, url, display, editable, startEditable, durationEditable, overlap, meta, rrule, exdates, recurrenceOf, recurrenceId, resourceId')
            ->toContain('`meta`');
    }
});

it('fromArray lista TODAS las claves desconocidas, en plural', function () {
    try {
        CalendarEvent::fromArray(['id' => 1, 'title' => 'x', 'start' => '2026-10-16', 'colour' => 'red', 'all_day' => true, 'color' => 'ok']);
        $this->fail('Debía lanzar.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())
            ->toContain('claves desconocidas «colour», «all_day»')
            ->not->toContain('«color»');
    }
});

it('fromArray no deja pasar una lista (claves numéricas) como si fuera un evento', function () {
    expect(fn () => CalendarEvent::fromArray([1, 'x', '2026-10-16']))
        ->toThrow(InvalidArgumentException::class, 'claves desconocidas «0», «1», «2»');
});

it('fromArray exige id, title y start', function (array $datos, string $faltan) {
    expect(fn () => CalendarEvent::fromArray($datos))
        ->toThrow(InvalidArgumentException::class, "faltan claves obligatorias: {$faltan}.");
})->with([
    'todo' => [[], 'id, title, start'],
    'sin título' => [['id' => 1, 'start' => '2026-10-16'], 'title'],
    'sin inicio' => [['id' => 1, 'title' => 'x'], 'start'],
    'sin id' => [['title' => 'x', 'start' => '2026-10-16'], 'id'],
]);

it('fromArray acepta resourceId como atajo de resourceIds', function () {
    $e = CalendarEvent::fromArray(['id' => 1, 'title' => 'x', 'start' => '2026-10-16', 'resourceId' => 'sala-2']);

    expect($e->resourceIds)->toBe(['sala-2']);
});

it('fromArray: resourceId null es "sin recurso" (columna nullable)', function () {
    $e = CalendarEvent::fromArray(['id' => 1, 'title' => 'x', 'start' => '2026-10-16', 'resourceId' => null]);

    expect($e->resourceIds)->toBe([]);
});

it('fromArray: resourceId y resourceIds a la vez es un error, aunque coincidan', function (mixed $plural) {
    expect(fn () => CalendarEvent::fromArray([
        'id' => 1, 'title' => 'x', 'start' => '2026-10-16', 'resourceId' => 'a', 'resourceIds' => $plural,
    ]))->toThrow(InvalidArgumentException::class, 'usa `resourceId` o `resourceIds`, no los dos a la vez');
})->with([[['a']], [['b']], [[]]]);

it('fromArray convierte un tipo equivocado en una excepción en español, con la causa original', function () {
    try {
        CalendarEvent::fromArray(['id' => 4, 'title' => ['no es un string'], 'start' => '2026-10-16']);
        $this->fail('Debía lanzar.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('evento «4» con un valor de tipo no válido')
            ->and($e->getPrevious())->toBeInstanceOf(TypeError::class);
    }
});

it('fromArray valida igual que el constructor', function () {
    expect(fn () => CalendarEvent::fromArray(['id' => 1, 'title' => 'x', 'start' => '2026-10-16', 'display' => 'punto']))
        ->toThrow(InvalidArgumentException::class, 'display «punto» no válido');
});
