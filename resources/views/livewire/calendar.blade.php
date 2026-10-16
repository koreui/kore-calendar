{{--
    Vista de KoreCalendar: el calendario con la barra en modo Livewire, la
    interacción (KoreCalendarInteraction) y sus capas:

    - [data-kore-calendar-overlay] con wire:ignore: la previsualización del gesto.
      El morph no la toca, así que un re-render a mitad del gesto no la borra.
    - una región aria-live para los anuncios (movido, cancelado…).
    - el texto de ayuda del teclado al que apuntan los eventos movibles.

    Atajos dentro del calendario (si no hay un evento agarrado con el teclado):
    RePág / AvPág periodo anterior y siguiente, T hoy.
--}}
@php
    $settings = $koreCalendar->settings;
@endphp

<div
    x-data="KoreCalendarInteraction({{ Js::from([
        'snap' => $settings->snapSeconds,
        'selectable' => $settings->selectable,
        'defaultTimed' => $settings->defaultTimedSeconds,
        'locale' => $settings->locale,
        'texts' => [
            'grabbed' => $koreCalendar->text('kbd_grabbed'),
            'moving' => '',
            'cancelled' => $koreCalendar->text('kbd_cancelled'),
        ],
    ]) }})"
    class="relative"
    x-on:pointerdown="onPointerDown($event)"
    x-on:pointermove.window="onPointerMove($event)"
    x-on:pointerup.window="onPointerUp()"
    x-on:pointercancel.window="cancel()"
    x-on:click.capture="onClickCapture($event)"
    x-on:keydown="onKeydown($event)"
    x-on:keydown.page-up="if (! keyboard) { $event.preventDefault(); $wire.previous() }"
    x-on:keydown.page-down="if (! keyboard) { $event.preventDefault(); $wire.next() }"
    x-on:keydown.t="if (! keyboard && ! $event.target.closest('input, textarea, select, [contenteditable]') && ! $event.ctrlKey && ! $event.metaKey && ! $event.altKey) { $event.preventDefault(); $wire.today() }"
>
    <x-kore::calendar :calendar="$koreCalendar" :views="$koreViews" mode="livewire" />

    <div wire:ignore data-kore-calendar-overlay class="pointer-events-none absolute inset-0 z-40" aria-hidden="true"></div>
    <div class="sr-only" aria-live="assertive" aria-atomic="true" x-text="announcement"></div>
    <p id="kore-calendar-kbd-help" class="sr-only">{{ $koreCalendar->text('kbd_help') }}</p>
</div>
