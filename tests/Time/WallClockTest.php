<?php

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\WallClock;

it('convierte un instante a la hora de pared de la zona de la vista', function () {
    $clock = new WallClock('Europe/Madrid');

    // Un pedido de las 23:30 UTC es del día siguiente en Madrid (verano: +2).
    expect((string) $clock->toMarker(CarbonImmutable::parse('2026-07-01 23:30', 'UTC')))->toBe('2026-07-02 01:30:00');
});

it('un string sin offset es hora de pared en esa zona; con offset conserva el instante', function () {
    $clock = new WallClock('America/New_York');

    expect((string) $clock->toMarker('2026-07-01 12:00'))->toBe('2026-07-01 12:00:00')
        ->and((string) $clock->toMarker('2026-07-01T12:00:00Z'))->toBe('2026-07-01 08:00:00')
        ->and((string) $clock->toMarker('2026-07-01T12:00:00+02:00'))->toBe('2026-07-01 06:00:00');
});

it('una hora que no existe se resuelve hacia delante', function (string $zone, string $wall, string $expected) {
    expect((new WallClock($zone))->toInstant(Marker::parse($wall))->toIso8601String())->toBe($expected);
})->with([
    'Madrid 2026-03-29 02:30' => ['Europe/Madrid', '2026-03-29 02:30', '2026-03-29T03:30:00+02:00'],
    'Nueva York 2024-03-10 02:30' => ['America/New_York', '2024-03-10 02:30', '2024-03-10T03:30:00-04:00'],
    'Santiago: medianoche que no existe' => ['America/Santiago', '2024-09-08 00:00', '2024-09-08T01:00:00-03:00'],
]);

it('una hora repetida es siempre la primera ocurrencia, en todas las zonas', function (string $zone, string $wall, string $expected) {
    // PHP no es consistente aquí: en Madrid daría la SEGUNDA (+01:00) y en
    // Nueva York la primera. Este test es la razón de que WallClock no delegue.
    expect((new WallClock($zone))->toInstant(Marker::parse($wall))->toIso8601String())->toBe($expected);
})->with([
    'Madrid 2026-10-25 02:30' => ['Europe/Madrid', '2026-10-25 02:30', '2026-10-25T02:30:00+02:00'],
    'Nueva York 2024-11-03 01:30' => ['America/New_York', '2024-11-03 01:30', '2024-11-03T01:30:00-04:00'],
]);

it('ida y vuelta conserva la hora de pared fuera de los cambios de horario', function (string $zone) {
    $clock = new WallClock($zone);

    foreach (['2026-01-15 09:00', '2026-07-15 18:30', '2026-12-31 23:59'] as $wall) {
        expect((string) $clock->toMarker($clock->toInstant(Marker::parse($wall))))->toBe(Marker::parse($wall)->toDateTimeString());
    }
})->with(['Europe/Madrid', 'America/New_York', 'Asia/Kolkata', 'Australia/Lord_Howe', 'UTC']);

it('las dos pasadas de la hora repetida dan la misma hora de pared', function () {
    $clock = new WallClock('Europe/Madrid');

    expect((string) $clock->toMarker('2026-10-25T02:30:00+02:00'))->toBe('2026-10-25 02:30:00')
        ->and((string) $clock->toMarker('2026-10-25T02:30:00+01:00'))->toBe('2026-10-25 02:30:00');
});

it('"ahora" respeta Carbon::setTestNow', function () {
    CarbonImmutable::setTestNow('2026-10-16 08:00:00 UTC');

    expect((string) (new WallClock('Europe/Madrid'))->now())->toBe('2026-10-16 10:00:00');

    CarbonImmutable::setTestNow();
});
