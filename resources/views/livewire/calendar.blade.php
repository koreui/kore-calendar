{{--
    Vista de KoreCalendar: envuelve el componente anónimo con el calendario ya
    montado y la barra en modo Livewire. Atajos de teclado dentro del calendario:
    RePág / AvPág periodo anterior y siguiente, T hoy.
--}}
<div
    x-data
    x-on:keydown.page-up.prevent="$wire.previous()"
    x-on:keydown.page-down.prevent="$wire.next()"
    x-on:keydown="if ($event.key === 't' && ! $event.target.closest('input, textarea, select, [contenteditable]') && ! $event.ctrlKey && ! $event.metaKey && ! $event.altKey) { $event.preventDefault(); $wire.today() }"
>
    <x-kore::calendar :calendar="$koreCalendar" :views="$koreViews" mode="livewire" />
</div>
