<?php

use KoreUi\Calendar\Resources\CalendarResource;
use KoreUi\Calendar\Resources\ResourceSet;

function rsRecurso(string|int $id, ?string $title = null, string|int|null $parent = null, int|string|null $order = null, ?string $group = null): CalendarResource
{
    return CalendarResource::make(id: $id, title: $title ?? "R{$id}", parentId: $parent, group: $group, order: $order);
}

/** @return list<string|int> */
function rsIds(array $recursos): array
{
    return array_map(fn (CalendarResource $r) => $r->id, $recursos);
}

// ── Construcción ─────────────────────────────────────────────────────

it('un conjunto vacío es válido', function () {
    $set = new ResourceSet([]);

    expect($set->isEmpty())->toBeTrue()
        ->and($set->count())->toBe(0)
        ->and(count($set))->toBe(0)
        ->and($set->all())->toBe([])
        ->and($set->roots())->toBe([])
        ->and($set->flatten())->toBe([])
        ->and($set->groups())->toBe([])
        ->and($set->find('x'))->toBeNull();
});

it('acepta CalendarResource, arrays y una mezcla, y cualquier iterable', function () {
    $set = new ResourceSet((function () {
        yield rsRecurso('a');
        yield ['id' => 'b', 'title' => 'B', 'parentId' => 'a', 'order' => 2];
    })());

    expect($set->count())->toBe(2)
        ->and($set->find('b')->parentId)->toBe('a')
        ->and($set->find('b')->order)->toBe(2)
        ->and($set->isEmpty())->toBeFalse();
});

it('un array con una clave desconocida lanza e indica la posición', function () {
    expect(fn () => new ResourceSet([rsRecurso('a'), ['id' => 'b', 'title' => 'B', 'colour' => 'red']]))
        ->toThrow(InvalidArgumentException::class, 'recurso en la posición 1 no válido')
        ->and(fn () => new ResourceSet([['id' => 'b', 'title' => 'B', 'colour' => 'red']]))
        ->toThrow(InvalidArgumentException::class, '«colour»');
});

it('algo que no es recurso ni array lanza', function (mixed $malo, string $tipo) {
    expect(fn () => new ResourceSet([rsRecurso('a'), $malo]))
        ->toThrow(UnexpectedValueException::class, "en la posición 1 recibió {$tipo}");
})->with([
    'string' => ['sala', 'string'],
    'null' => [null, 'null'],
    'objeto' => [new stdClass, 'stdClass'],
]);

// ── Validación ───────────────────────────────────────────────────────

it('rechaza ids duplicados', function () {
    expect(fn () => new ResourceSet([rsRecurso('a'), rsRecurso('b'), rsRecurso('a', 'Otro')]))
        ->toThrow(InvalidArgumentException::class, 'el id de recurso «a» está repetido');
});

it('1 y "1" son el mismo id: duplicado', function () {
    expect(fn () => new ResourceSet([rsRecurso(1), rsRecurso('1')]))
        ->toThrow(InvalidArgumentException::class, 'repetido');
});

it('rechaza un padre que no existe, y dice cuál es el huérfano', function () {
    expect(fn () => new ResourceSet([rsRecurso('a'), rsRecurso('b', parent: 'fantasma')]))
        ->toThrow(InvalidArgumentException::class, 'el recurso «b» apunta a un padre que no existe («fantasma»)');
});

it('un padre que llega DESPUÉS en la lista es válido', function () {
    $set = new ResourceSet([rsRecurso('hijo', parent: 'padre'), rsRecurso('padre')]);

    expect(rsIds($set->children('padre')))->toBe(['hijo']);
});

it('rechaza un ciclo de dos y dice el camino', function () {
    expect(fn () => new ResourceSet([rsRecurso('a', parent: 'b'), rsRecurso('b', parent: 'a')]))
        ->toThrow(InvalidArgumentException::class, 'ciclo en la jerarquía de recursos (cada uno apunta a su padre): a → b → a');
});

it('rechaza un ciclo largo aunque haya ramas válidas colgando de él', function () {
    $recursos = [
        rsRecurso('raiz'),
        rsRecurso('ok', parent: 'raiz'),
        rsRecurso('a', parent: 'c'),
        rsRecurso('b', parent: 'a'),
        rsRecurso('c', parent: 'b'),
        rsRecurso('cuelga', parent: 'a'),
    ];

    expect(fn () => new ResourceSet($recursos))
        ->toThrow(InvalidArgumentException::class, 'ciclo en la jerarquía');
});

it('el mensaje de ciclo solo incluye los miembros del ciclo, no el camino que llegó hasta él', function () {
    // entrada → a → b → a: «entrada» cuelga del ciclo pero no forma parte.
    try {
        new ResourceSet([rsRecurso('entrada', parent: 'a'), rsRecurso('a', parent: 'b'), rsRecurso('b', parent: 'a')]);
        $this->fail('Debía lanzar.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('a → b → a')->not->toContain('entrada →');
    }
});

it('un recurso que es su propio padre ya lo rechaza el propio recurso', function () {
    expect(fn () => new ResourceSet([['id' => 'a', 'title' => 'A', 'parentId' => 'a']]))
        ->toThrow(InvalidArgumentException::class, 'no puede ser su propio padre');
});

it('un árbol profundo y ancho no es un ciclo (los visitados no se repiten)', function () {
    $recursos = [rsRecurso('n0')];

    for ($i = 1; $i < 300; $i++) {
        $recursos[] = rsRecurso("n{$i}", parent: 'n'.($i - 1));
    }

    for ($i = 0; $i < 300; $i++) {
        $recursos[] = rsRecurso("h{$i}", parent: 'n150');
    }

    $set = new ResourceSet($recursos);

    expect($set->count())->toBe(600)
        ->and(count($set->flatten()))->toBe(600);
});

// ── Orden ────────────────────────────────────────────────────────────

it('all() ordena por order ascendente con los null al final', function () {
    $set = new ResourceSet([
        rsRecurso('sin', 'A sin orden'),
        rsRecurso('tres', 'B', order: 3),
        rsRecurso('uno', 'C', order: 1),
        rsRecurso('cero', 'D', order: 0),
        rsRecurso('dos', 'E', order: 2),
    ]);

    expect(rsIds($set->all()))->toBe(['cero', 'uno', 'dos', 'tres', 'sin']);
});

it('el order negativo y el 0 van antes que el positivo', function () {
    $set = new ResourceSet([rsRecurso('a', order: 5), rsRecurso('b', order: -10), rsRecurso('c', order: -2), rsRecurso('d', order: 0)]);

    expect(rsIds($set->all()))->toBe(['b', 'c', 'd', 'a']);
});

it('a igual order, por título con comparación natural y sin mayúsculas', function () {
    $set = new ResourceSet([
        rsRecurso('s10', 'Sala 10'),
        rsRecurso('s2', 'sala 2'),
        rsRecurso('s1', 'SALA 1'),
        rsRecurso('s20', 'Sala 20'),
        rsRecurso('a', 'Aula magna'),
    ]);

    // Natural: 1 < 2 < 10 < 20. Sin mayúsculas: 'sala' = 'SALA' = 'Sala'. Y «Aula» antes que «Sala».
    expect(rsIds($set->all()))->toBe(['a', 's1', 's2', 's10', 's20']);
});

it('a igual order y título, por id', function () {
    $set = new ResourceSet([rsRecurso('b', 'Igual'), rsRecurso('a', 'Igual'), rsRecurso('c', 'igual')]);

    expect(rsIds($set->all()))->toBe(['a', 'b', 'c']);
});

it('el desempate por id compara enteros como enteros y strings de forma natural', function () {
    $set = new ResourceSet([rsRecurso(10, 'X'), rsRecurso(9, 'X'), rsRecurso(100, 'X')]);

    expect(rsIds($set->all()))->toBe([9, 10, 100]);

    $set = new ResourceSet([rsRecurso('r10', 'X'), rsRecurso('r9', 'X'), rsRecurso('r100', 'X')]);

    expect(rsIds($set->all()))->toBe(['r9', 'r10', 'r100']);
});

it('order como string se compara de forma natural', function () {
    $set = new ResourceSet([rsRecurso('a', order: '10'), rsRecurso('b', order: '2'), rsRecurso('c', order: 'x1'), rsRecurso('d', order: '1')]);

    expect(rsIds($set->all()))->toBe(['d', 'b', 'a', 'c']);
});

it('el orden no depende de cómo lleguen los datos', function () {
    $recursos = [
        rsRecurso('a', 'Beta', order: 2), rsRecurso('b', 'Alfa', order: 2), rsRecurso('c', 'Gamma'),
        rsRecurso('d', 'Delta', order: 1), rsRecurso('e', 'Alfa'),
    ];
    $esperado = rsIds((new ResourceSet($recursos))->all());

    foreach ([array_reverse($recursos), [...array_slice($recursos, 2), ...array_slice($recursos, 0, 2)]] as $barajado) {
        expect(rsIds((new ResourceSet($barajado))->all()))->toBe($esperado);
    }

    expect($esperado)->toBe(['d', 'b', 'a', 'e', 'c']);
});

it('all() devuelve una lista (reindexada)', function () {
    $set = new ResourceSet([rsRecurso('b'), rsRecurso('a')]);

    expect(array_is_list($set->all()))->toBeTrue();
});

// ── Consultas ────────────────────────────────────────────────────────

it('find() busca por id; 1 y "1" son lo mismo; lo que no existe es null', function () {
    $set = new ResourceSet([rsRecurso(1, 'Uno'), rsRecurso('x', 'Equis')]);

    expect($set->find(1)->title)->toBe('Uno')
        ->and($set->find('1')->title)->toBe('Uno')
        ->and($set->find('x')->title)->toBe('Equis')
        ->and($set->find('nada'))->toBeNull()
        ->and($set->find(99))->toBeNull();
});

it('roots() y children() devuelven los hermanos ordenados', function () {
    $set = new ResourceSet([
        rsRecurso('edificio-b', 'Edificio B'),
        rsRecurso('edificio-a', 'Edificio A'),
        rsRecurso('sala-2', 'Sala 2', parent: 'edificio-a'),
        rsRecurso('sala-10', 'Sala 10', parent: 'edificio-a'),
        rsRecurso('sala-1', 'Sala 1', parent: 'edificio-a', order: 5),
        rsRecurso('sala-x', 'Sala X', parent: 'edificio-b'),
    ]);

    expect(rsIds($set->roots()))->toBe(['edificio-a', 'edificio-b'])
        // order antes que título: sala-1 tiene order 5 y los otros no.
        ->and(rsIds($set->children('edificio-a')))->toBe(['sala-1', 'sala-2', 'sala-10'])
        ->and(rsIds($set->children('edificio-b')))->toBe(['sala-x']);
});

it('children() de una hoja o de un id que no existe es una lista vacía', function () {
    $set = new ResourceSet([rsRecurso('a'), rsRecurso('b', parent: 'a')]);

    expect($set->children('b'))->toBe([])
        ->and($set->children('inexistente'))->toBe([]);
});

it('children() reconoce 1 y "1" como el mismo padre', function () {
    $set = new ResourceSet([rsRecurso(1), rsRecurso('h', parent: '1')]);

    expect(rsIds($set->children(1)))->toBe(['h'])
        ->and(rsIds($set->children('1')))->toBe(['h']);
});

// ── flatten ──────────────────────────────────────────────────────────

it('flatten() recorre en profundidad: padre antes que hijos, hermanos ordenados, con su profundidad', function () {
    $set = new ResourceSet([
        rsRecurso('sala-101', 'Sala 101', parent: 'planta-1'),
        rsRecurso('edificio-b', 'Edificio B'),
        rsRecurso('planta-1', 'Planta 1', parent: 'edificio-a'),
        rsRecurso('edificio-a', 'Edificio A'),
        rsRecurso('sala-201', 'Sala 201', parent: 'planta-2'),
        rsRecurso('planta-2', 'Planta 2', parent: 'edificio-a'),
        rsRecurso('sala-102', 'Sala 102', parent: 'planta-1'),
        rsRecurso('sala-b1', 'Sala B1', parent: 'edificio-b'),
    ]);

    $plano = array_map(fn ($f) => [$f['resource']->id, $f['depth']], $set->flatten());

    expect($plano)->toBe([
        ['edificio-a', 0],
        ['planta-1', 1],
        ['sala-101', 2],
        ['sala-102', 2],
        ['planta-2', 1],
        ['sala-201', 2],
        ['edificio-b', 0],
        ['sala-b1', 1],
    ]);
});

it('flatten() devuelve los objetos CalendarResource y una lista', function () {
    $set = new ResourceSet([rsRecurso('a'), rsRecurso('b', parent: 'a')]);
    $plano = $set->flatten();

    expect(array_is_list($plano))->toBeTrue()
        ->and($plano[0]['resource'])->toBe($set->find('a'))
        ->and($plano[1]['resource'])->toBe($set->find('b'))
        ->and(array_keys($plano[0]))->toBe(['resource', 'depth']);
});

it('sin jerarquía flatten() coincide con all(), todos a profundidad 0', function () {
    $set = new ResourceSet([rsRecurso('b', order: 2), rsRecurso('a', order: 1), rsRecurso('c')]);

    expect(rsIds(array_column($set->flatten(), 'resource')))->toBe(rsIds($set->all()))
        ->and(array_unique(array_column($set->flatten(), 'depth')))->toBe([0]);
});

it('flatten() aguanta una cadena profunda', function () {
    $recursos = [rsRecurso('n0')];

    for ($i = 1; $i <= 150; $i++) {
        $recursos[] = rsRecurso("n{$i}", parent: 'n'.($i - 1));
    }

    $plano = (new ResourceSet($recursos))->flatten();

    expect($plano)->toHaveCount(151)
        ->and($plano[150]['depth'])->toBe(150);
});

// ── groups ───────────────────────────────────────────────────────────

it('groups() agrupa por group preservando el orden de all(); los sin grupo bajo ""', function () {
    $set = new ResourceSet([
        rsRecurso('s3', 'Sala 3', group: 'Planta baja'),
        rsRecurso('s1', 'Sala 1', group: 'Planta alta'),
        rsRecurso('suelta', 'Sala 2b'),
        rsRecurso('s2', 'Sala 2', group: 'Planta alta'),
        rsRecurso('s4', 'Sala 4', group: 'Planta baja'),
    ]);

    $grupos = $set->groups();

    // Los grupos salen por orden de aparición del primer miembro en all().
    expect(array_keys($grupos))->toBe(['Planta alta', '', 'Planta baja'])
        ->and(rsIds($grupos['Planta alta']))->toBe(['s1', 's2'])
        ->and(rsIds($grupos['Planta baja']))->toBe(['s3', 's4'])
        ->and(rsIds($grupos['']))->toBe(['suelta']);
});

it('groups() sin ningún grupo deja todo bajo ""', function () {
    $set = new ResourceSet([rsRecurso('b'), rsRecurso('a')]);

    expect(array_keys($set->groups()))->toBe([''])
        ->and(rsIds($set->groups()['']))->toBe(['a', 'b']);
});

it('groups() respeta order dentro del grupo y entre grupos', function () {
    $set = new ResourceSet([
        rsRecurso('a', 'A', group: 'X', order: 2),
        rsRecurso('b', 'B', group: 'Y', order: 1),
        rsRecurso('c', 'C', group: 'X', order: 0),
    ]);

    expect(array_keys($set->groups()))->toBe(['X', 'Y'])
        ->and(rsIds($set->groups()['X']))->toBe(['c', 'a']);
});

it('groups() con un nombre numérico: PHP lo vuelve int, hay que comparar como string', function () {
    $set = new ResourceSet([rsRecurso('a', group: '10'), rsRecurso('b', group: 'x')]);

    expect(array_map('strval', array_keys($set->groups())))->toBe(['10', 'x']);
});

it('group y parentId son independientes', function () {
    $set = new ResourceSet([
        rsRecurso('edificio', 'Edificio', group: 'Sede'),
        rsRecurso('sala', 'Sala', parent: 'edificio', group: 'Reuniones'),
    ]);

    expect(array_keys($set->groups()))->toBe(['Sede', 'Reuniones'])
        ->and(rsIds($set->roots()))->toBe(['edificio']);
});

// ── Inmutabilidad ────────────────────────────────────────────────────

it('mutar lo que devuelve un método no altera el conjunto', function () {
    $set = new ResourceSet([rsRecurso('a'), rsRecurso('b')]);

    $todos = $set->all();
    array_pop($todos);
    $raices = $set->roots();
    array_pop($raices);

    expect($set->count())->toBe(2)
        ->and(count($set->all()))->toBe(2)
        ->and(count($set->roots()))->toBe(2);
});

it('ResourceSet es Countable', function () {
    expect(new ResourceSet([rsRecurso('a'), rsRecurso('b')]))->toBeInstanceOf(Countable::class)->toHaveCount(2);
});

it('ordena los títulos con las reglas del idioma: las tildes no van al final', function () {
    $set = new KoreUi\Calendar\Resources\ResourceSet([
        ['id' => 'z', 'title' => 'Sala Zeta'],
        ['id' => 'a', 'title' => 'Sala Ámbar'],
        ['id' => 'b', 'title' => 'Sala Bruma'],
        ['id' => '10', 'title' => 'Sala 10'],
        ['id' => '2', 'title' => 'Sala 2'],
    ]);

    expect(array_map(fn ($r) => $r->title, $set->all()))->toBe(['Sala 2', 'Sala 10', 'Sala Ámbar', 'Sala Bruma', 'Sala Zeta']);
})->skip(! class_exists(Collator::class), 'requiere ext-intl');
