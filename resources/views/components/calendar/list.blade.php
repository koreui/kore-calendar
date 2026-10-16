{{--
    Agenda: un grupo por día con eventos. Recibe un RenderedCalendar cuyo layout es
    un ListLayout. Sin eventos, un mensaje con role=status.
--}}
@props(['calendar'])

@php
    $layout = $calendar->layout;
@endphp

<div {{ $attributes->class('kore-calendar-list overflow-hidden rounded-kore-lg border border-kore-border bg-kore-bg text-kore-fg') }}>
    @if ($layout->days === [])
        <p role="status" class="px-4 py-10 text-center text-sm text-kore-muted-fg">{{ $calendar->text('no_events') }}</p>
    @else
        <ul aria-label="{{ $calendar->title() }}">
            @foreach ($layout->days as $day)
                <li wire:key="kore-calendar-list-{{ $day['date']->ts }}" class="border-b border-kore-border last:border-b-0">
                    <h3
                        @if ($day['isToday']) aria-current="date" @endif
                        @class([
                            'bg-kore-muted/50 px-4 py-1.5 text-sm font-medium',
                            'text-kore-primary-text' => $day['isToday'],
                        ])
                    >{{ $calendar->format($day['date'], 'day_header_long') }}</h3>

                    <ul>
                        @foreach ($day['items'] as $item)
                            @php
                                $instance = $item['instance'];
                                $when = match ($item['kind']) {
                                    'all-day', 'middle' => $calendar->text('all_day'),
                                    'single' => $calendar->time($instance->range->start).' – '.$calendar->time($instance->range->end),
                                    'start' => $calendar->time($instance->range->start).' – 24:00',
                                    'end' => '00:00 – '.$calendar->time($instance->range->end),
                                };
                                $style = \KoreUi\Calendar\View\EventColor::style($instance->event->color);
                                $conflicts = $calendar->conflictsOf($instance);
                            @endphp
                            <li class="flex items-center gap-3 px-4 py-2 text-sm">
                                <span class="w-36 shrink-0 whitespace-nowrap tabular-nums text-kore-muted-fg">{{ $when }}</span>
                                <span class="kore-calendar-dot size-2.5 shrink-0 rounded-full" @if ($style) style="{{ $style }}" @endif aria-hidden="true"></span>
                                @if ($instance->event->url)
                                    <a href="{{ $instance->event->url }}" class="min-w-0 truncate font-medium hover:underline">{{ $instance->event->title }}</a>
                                @else
                                    <span class="min-w-0 truncate font-medium">{{ $instance->event->title }}</span>
                                @endif
                                @if ($conflicts !== [])
                                    <x-lucide-triangle-alert class="size-3.5 shrink-0 text-kore-destructive-text" aria-hidden="true" />
                                    <span class="sr-only">{{ $calendar->conflictLabel($instance) }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </li>
            @endforeach
        </ul>
    @endif
</div>
