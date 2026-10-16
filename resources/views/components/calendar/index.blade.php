{{--
    <x-kore::calendar> — el calendario completo: barra + vista.

    Sin estado: la navegación son enlaces (?date= y ?view=), así que funciona en
    cualquier página Blade, sin Livewire. Para estado, edición o eventos desde la
    base de datos, la clase base KoreCalendar (que pasa :calendar ya montado).

    Las props llevan el mismo nombre que la configuración (config/kore-calendar.php)
    y ganan sobre ella.
--}}
@props([
    'events' => [],
    'resources' => null,
    'view' => null,
    'date' => null,
    'timezone' => null,
    'locale' => null,
    'firstDay' => null,
    'hiddenDays' => null,
    'weekNumbers' => null,
    'slotDuration' => null,
    'slotMinTime' => null,
    'slotMaxTime' => null,
    'maxEvents' => null,
    'nowIndicator' => null,
    'views' => null,
    'toolbar' => true,
    'dateParam' => 'date',
    'viewParam' => 'view',
    'calendar' => null,
    'mode' => 'links',
])

@php
    // Con un calendario ya montado (KoreCalendar), sus recursos mandan.
    $resourceSet = match (true) {
        $calendar !== null => $calendar->resources,
        $resources instanceof \KoreUi\Calendar\Resources\ResourceSet => $resources,
        $resources === null => null,
        default => new \KoreUi\Calendar\Resources\ResourceSet($resources),
    };

    $available = \KoreUi\Calendar\View\CalendarViews::available($views ?? config('kore-calendar.views'), $resourceSet);

    if ($calendar === null) {
        // En modo enlaces la vista y la fecha llegan por la URL; solo se aceptan
        // vistas del selector y fechas YYYY-MM-DD (lo demás se ignora, no revienta).
        $requestedView = $toolbar ? request($viewParam) : null;
        $requestedDate = $toolbar ? request($dateParam) : null;

        $calendar = \KoreUi\Calendar\View\CalendarRenderer::render(
            \KoreUi\Calendar\View\CalendarSettings::make([
                'view' => in_array($requestedView, $available, true) ? $requestedView : ($view ?? $available[0] ?? null),
                'date' => is_string($requestedDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedDate) ? $requestedDate : $date,
                'timezone' => $timezone,
                'locale' => $locale,
                'first_day' => $firstDay,
                'hidden_days' => $hiddenDays,
                'week_numbers' => $weekNumbers,
                'time.slot_duration' => $slotDuration,
                'time.slot_min_time' => $slotMinTime,
                'time.slot_max_time' => $slotMaxTime,
                'month.max_events' => $maxEvents,
                'now_indicator' => $nowIndicator,
            ]),
            $events,
            $resourceSet,
        );
    }

    $vista = \KoreUi\Calendar\View\CalendarViews::component($calendar->settings->view);
@endphp

<div {{ $attributes->class('kore-calendar space-y-3') }}>
    @if ($toolbar)
        <x-kore::calendar.toolbar :calendar="$calendar" :views="$available" :mode="$mode" :date-param="$dateParam" :view-param="$viewParam" />
    @endif

    @if ($vista === 'timeline')
        {{-- Solo el timeline necesita el modo: con Livewire pide ventanas de filas al hacer scroll. --}}
        <x-kore::calendar.timeline :calendar="$calendar" :mode="$mode" />
    @else
        <x-dynamic-component :component="'kore::calendar.'.$vista" :calendar="$calendar" />
    @endif
</div>
