{{--
    <x-kore::calendar> — el calendario completo: barra + vista.

    Sin estado: la navegación son enlaces (?date= y ?view=), así que funciona en
    cualquier página Blade, sin Livewire. Para estado, edición o eventos desde la
    base de datos, la clase base KoreCalendar (que pasa :calendar ya montado).

    Las props llevan el mismo nombre que la configuración (config/kore-calendar.php)
    y ganan sobre ella.

    `mobile-view`: la vista que se abre, en la PRIMERA carga sin ?view= en la URL, si la pantalla
    es estrecha (max-width: 640px). El servidor no sabe el ancho, así que lo hace el cliente
    (KoreCalendarMobileView) al iniciar: provoca un render extra la primera vez. Solo cuenta si
    es una de las vistas del selector y la barra está activa (sin barra la URL se ignora).

    w-full: la barra, el mes y el timeline son contenedores de CSS (inline-size), y un contenedor
    no toma su ancho de lo que lleva dentro. En un `flex` sin ancho propio el calendario se
    quedaba en 2 px; con w-full llena el hueco y se encoge si hace falta.
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
    'businessHours' => null,
    'highlightConflicts' => null,
    'views' => null,
    'toolbar' => true,
    'dateParam' => 'date',
    'viewParam' => 'view',
    'mobileView' => null,
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
                // La URL manda (así funcionan los enlaces de la barra); después la prop y
                // `default_view`, y por último la primera del selector. Como en KoreCalendar,
                // una vista que el selector no ofrece no cuenta.
                'view' => \KoreUi\Calendar\View\CalendarViews::initial([$requestedView, $view, config('kore-calendar.default_view')], $available),
                'date' => \KoreUi\Calendar\Interaction\Bounds::acceptsDate($requestedDate) ? $requestedDate : $date,
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
                'business_hours' => $businessHours,
                'highlight_conflicts' => $highlightConflicts,
            ]),
            $events,
            $resourceSet,
            // Sin Livewire no hay quien dé la ventana siguiente al hacer scroll: el
            // timeline pinta todas las filas en vez de dejar el resto inalcanzable.
            virtualize: false,
        );
    }

    $vista = \KoreUi\Calendar\View\CalendarViews::component($calendar->settings->view);

    // Prop > config, y como la vista inicial: solo cuenta si el selector la ofrece. `false` la apaga
    // aunque el config tenga una (así la pasa KoreCalendar cuando no hay ninguna).
    $mobile = $toolbar
        ? \KoreUi\Calendar\View\CalendarViews::mobile($mobileView ?? config('kore-calendar.mobile_view'), $available)
        : null;
@endphp

@if ($mobile !== null)
    @assets
        <script src="{{ \KoreUi\Calendar\KoreCalendarServiceProvider::scriptUrl() }}" defer></script>
    @endassets
@endif

<div
    @if ($mobile !== null)
        x-data="KoreCalendarMobileView({{ Js::from([
            'view' => $mobile,
            // La vista con la que el servidor pintó esta página: si ya es la móvil, no hay nada que cambiar.
            'current' => $calendar->settings->view,
            'param' => $viewParam,
            'live' => $mode === 'livewire',
        ]) }})"
    @endif
    {{ $attributes->class('kore-calendar w-full space-y-3') }}
>
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
