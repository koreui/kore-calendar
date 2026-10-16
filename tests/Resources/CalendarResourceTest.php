<?php

use KoreUi\Calendar\Resources\CalendarResource;

it('make() solo exige id y título', function () {
    $r = CalendarResource::make(id: 'sala-1', title: 'Sala 1');

    expect($r->id)->toBe('sala-1')
        ->and($r->title)->toBe('Sala 1')
        ->and($r->parentId)->toBeNull()
        ->and($r->group)->toBeNull()
        ->and($r->color)->toBeNull()
        ->and($r->order)->toBeNull()
        ->and($r->businessHours)->toBeNull()
        ->and($r->meta)->toBe([]);
});

it('conserva todo lo que se le da', function () {
    $r = CalendarResource::make(
        id: 4, title: 'Sala 4', parentId: 'planta-2', group: 'Edificio A', color: 'chart-2', order: 3,
        businessHours: ['days' => [1, 2, 3], 'start' => '09:00', 'end' => '17:00'], meta: ['aforo' => 20],
    );

    expect($r->id)->toBe(4)
        ->and($r->parentId)->toBe('planta-2')
        ->and($r->group)->toBe('Edificio A')
        ->and($r->color)->toBe('chart-2')
        ->and($r->order)->toBe(3)
        ->and($r->businessHours)->toBe(['days' => [1, 2, 3], 'start' => '09:00', 'end' => '17:00'])
        ->and($r->meta)->toBe(['aforo' => 20]);
});

it('order acepta int, string y null', function (int|string|null $order) {
    expect(CalendarResource::make(id: 1, title: 'x', order: $order)->order)->toBe($order);
})->with([0, 7, 'b', null]);

it('es inmutable', function () {
    $r = CalendarResource::make(id: 1, title: 'x');

    expect(fn () => $r->title = 'otro')->toThrow(Error::class);
});

it('make() y el constructor tienen los mismos argumentos y KEYS los lista', function () {
    $nombres = fn (ReflectionFunctionAbstract $f) => array_map(fn ($p) => $p->getName(), $f->getParameters());

    $make = new ReflectionMethod(CalendarResource::class, 'make');
    $constructor = new ReflectionMethod(CalendarResource::class, '__construct');

    expect($nombres($make))->toBe($nombres($constructor))
        ->and(CalendarResource::KEYS)->toBe($nombres($constructor));
});

it('rechaza un id vacío, pero 0 es un id', function () {
    expect(fn () => CalendarResource::make(id: '', title: 'x'))->toThrow(InvalidArgumentException::class, 'el id no puede estar vacío');
    expect(fn () => CalendarResource::make(id: '  ', title: 'x'))->toThrow(InvalidArgumentException::class, 'el id no puede estar vacío');
    expect(CalendarResource::make(id: 0, title: 'x')->id)->toBe(0);
});

it('rechaza un parentId vacío y que sea su propio padre', function () {
    expect(fn () => CalendarResource::make(id: 1, title: 'x', parentId: ''))
        ->toThrow(InvalidArgumentException::class, 'Usa null si es un recurso raíz');

    expect(fn () => CalendarResource::make(id: 1, title: 'x', parentId: 1))
        ->toThrow(InvalidArgumentException::class, 'no puede ser su propio padre');

    // 1 y '1' son el mismo id.
    expect(fn () => CalendarResource::make(id: 1, title: 'x', parentId: '1'))
        ->toThrow(InvalidArgumentException::class, 'no puede ser su propio padre');
});

it('rechaza un group vacío: caería en el cajón de los recursos sin grupo', function () {
    expect(fn () => CalendarResource::make(id: 1, title: 'x', group: ''))
        ->toThrow(InvalidArgumentException::class, 'Usa null si no pertenece a ningún grupo');
});

it('fromArray construye lo mismo que make', function () {
    $datos = [
        'id' => 'r1', 'title' => 'Recurso', 'parentId' => 'p', 'group' => 'g', 'color' => 'primary',
        'order' => 2, 'businessHours' => ['start' => '08:00'], 'meta' => ['k' => 'v'],
    ];

    expect(CalendarResource::fromArray($datos))->toEqual(CalendarResource::make(...$datos));
});

it('fromArray rechaza claves desconocidas y lista las permitidas', function () {
    try {
        CalendarResource::fromArray(['id' => 1, 'title' => 'x', 'parent_id' => 2, 'colour' => 'red']);
        $this->fail('Debía lanzar.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())
            ->toContain('claves desconocidas «parent_id», «colour»')
            ->toContain('Permitidas: id, title, parentId, group, color, order, businessHours, meta')
            ->toContain('`meta`');
    }
});

it('fromArray con una sola clave desconocida habla en singular', function () {
    expect(fn () => CalendarResource::fromArray(['id' => 1, 'title' => 'x', 'colour' => 'red']))
        ->toThrow(InvalidArgumentException::class, 'clave desconocida «colour»');
});

it('fromArray exige id y title', function (array $datos, string $faltan) {
    expect(fn () => CalendarResource::fromArray($datos))
        ->toThrow(InvalidArgumentException::class, "faltan claves obligatorias: {$faltan}.");
})->with([
    'todo' => [[], 'id, title'],
    'sin título' => [['id' => 1], 'title'],
    'sin id' => [['title' => 'x'], 'id'],
]);

it('fromArray convierte un tipo equivocado en una excepción en español, con la causa original', function () {
    try {
        CalendarResource::fromArray(['id' => 'r9', 'title' => 'x', 'meta' => 'no es un array']);
        $this->fail('Debía lanzar.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('recurso «r9» con un valor de tipo no válido')
            ->and($e->getPrevious())->toBeInstanceOf(TypeError::class);
    }
});
