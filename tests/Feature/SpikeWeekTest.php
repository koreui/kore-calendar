<?php

/*
 * La prueba técnica de la fase 1. Se borra junto con spike-week.blade.php.
 */

it('calcula la posición de cada evento en porcentaje del eje', function () {
    // Eje 08:00-18:00 = 600 min. Un evento a las 10:00 (120) de 90 min.
    $this->blade('<x-kore::calendar.spike-week :columns="[\'lun\', \'mar\']" :events="$events" />', [
        'events' => [['id' => 7, 'title' => 'Revisión', 'column' => 1, 'start' => 120, 'duration' => 90]],
    ])
        ->assertSee('top: 20%; height: 15%', false)
        ->assertSee('wire:key="spike-event-7"', false)
        ->assertSee('startDrag($event, 7, 1, 120, 90)', false);
});

it('un evento bloqueado no se puede arrastrar', function () {
    $this->blade('<x-kore::calendar.spike-week :columns="[\'lun\']" :events="$events" />', [
        'events' => [['id' => 'x', 'title' => 'Fijo', 'column' => 0, 'start' => 0, 'duration' => 60, 'locked' => true]],
    ])
        ->assertDontSee('startDrag', false)
        ->assertSee('cursor-not-allowed', false);
});

it('declara su bundle con @assets, no con un <script> a pelo', function () {
    // Livewire recoge @assets y lo inyecta en la respuesta completa, así que NO
    // aparece en el HTML de un componente aislado. Un <script> a pelo que llegara
    // dentro de una respuesta de Livewire (el calendario en un modal) no lo
    // ejecutaría el navegador. Que llegue de verdad se comprueba en el demo.
    $html = (string) $this->blade('<x-kore::calendar.spike-week :columns="[\'lun\']" :events="[]" />');

    expect($html)->not->toContain('<script src=');
});
