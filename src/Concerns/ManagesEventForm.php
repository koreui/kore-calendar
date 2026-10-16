<?php

namespace KoreUi\Calendar\Concerns;

use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventInstance;
use KoreUi\Calendar\Events\EventNormalizer;
use KoreUi\Calendar\Interaction\EventFormPayload;
use KoreUi\Calendar\Interaction\Messages;
use KoreUi\Calendar\Interaction\Selection;
use KoreUi\Calendar\Overlays\EventForm;
use KoreUi\Calendar\Time\Marker;
use Livewire\Attributes\On;

/**
 * Crear, editar y eliminar eventos con el formulario de kore-ui (fase 7).
 *
 * El camino es el de mover y redimensionar (docs/calendar.md §11): lo que llega del
 * navegador no es de fiar, así que el evento se vuelve a buscar entre los de la
 * vista, los datos se vuelven a comprobar y solo entonces se llama al hook del
 * consumidor (`createEvent`, `updateEvent`, `deleteEvent`) dentro de una transacción.
 *
 * Vive en un trait por tamaño: usa piezas de `KoreCalendar` (`koreFind`,
 * `koreInstances`, `korePersist`, `koreReject`) y los hooks públicos del consumidor
 * están declarados allí, junto a `persistMove()`.
 */
trait ManagesEventForm
{
    /** Con `eventForm()`: pulsar un evento o seleccionar un rango abre el formulario. */
    protected bool $koreEventForm = false;

    /**
     * La instancia que se pulsó, mientras dura `clickEvent()`. `onEventClick()` recibe
     * el `CalendarEvent` y no la instancia (su firma es la de antes de las series),
     * pero el formulario de una ocurrencia necesita saber CUÁL es: el evento de una
     * ocurrencia lleva el id de la serie, igual que las demás.
     */
    protected ?EventInstance $koreClicked = null;

    // ── Acciones (llegan del navegador) ─────────────────────────────────

    /**
     * Un clic (o Intro) en un evento. Lo propone el navegador y el servidor decide:
     * el evento se vuelve a buscar entre los de la vista, nunca por id directo.
     */
    public function clickEvent(string|int $id): void
    {
        $settings = $this->koreSettingsResolved();

        // Un calendario sin clic no explica nada: el cliente no ofrece el gesto.
        if (! $settings->eventClick) {
            $this->skipRender();

            return;
        }

        $original = self::koreFind($this->koreInstances($settings), $id);

        if ($original === null) {
            // Borrado en otra pestaña, de otra semana o ajeno: se avisa y se repinta,
            // que es lo que hace desaparecer el evento de la pantalla.
            $this->toast()->error(Messages::get('event_not_found'))->send();

            return;
        }

        $this->koreClicked = $original;

        try {
            $this->onEventClick($original->event);
        } finally {
            $this->koreClicked = null;
        }
    }

    /**
     * El formulario devuelve lo que el usuario escribió (o `mode: 'delete'`).
     *
     * Público porque Livewire solo despacha eventos a métodos públicos, lo que lo
     * hace también llamable desde el navegador: por eso todo lo de dentro se vuelve
     * a comprobar. El aviso es global, así que cada calendario descarta los que
     * llevan el id de otro (hay páginas con dos).
     *
     * @param  array<string, mixed>  $data
     */
    #[On(EventForm::SAVED)]
    public function koreEventFormSaved(string $calendar, array $data): void
    {
        if ($calendar !== $this->getId() || ! $this->koreEventForm) {
            $this->skipRender();

            return;
        }

        $mode = $data['mode'] ?? null;

        match ($mode) {
            'create', 'edit', 'delete' => $this->koreSaveEvent($mode, $data),
            default => $this->koreReject('update_rejected', Messages::get('invalid_change')),
        };
    }

    // ── Piezas ──────────────────────────────────────────────────────────

    /**
     * Crea, guarda o elimina lo que dijo el formulario. `$scope` solo llega al repetir
     * la acción tras el diálogo de alcance (`applyRecurrenceScope()`): una ocurrencia
     * sin alcance no se guarda, se pregunta.
     *
     * @param  array<string, mixed>  $data
     */
    private function koreSaveEvent(string $mode, array $data, ?string $scope = null): void
    {
        $settings = $this->koreSettingsResolved();
        $rejected = ['create' => 'create_rejected', 'edit' => 'update_rejected', 'delete' => 'delete_rejected'][$mode];
        $original = null;
        $instance = null;

        if ($mode !== 'create') {
            // Una ocurrencia se identifica por su `instanceId`: el id del evento es el de
            // la serie y `koreFind()` lo rechaza. Para un evento normal basta con el id.
            $id = $data['instanceId'] ?? $data['id'] ?? null;
            $instance = is_string($id) || is_int($id) ? self::koreFind($this->koreInstances($settings), $id) : null;

            if ($instance === null) {
                $this->koreReject($rejected, Messages::get('event_not_found'), stale: true);

                return;
            }

            // Un evento marcado `editable: false` (un festivo, una reserva de otro sistema) no se
            // cambia desde el formulario aunque el calendario sea editable.
            if ($instance->event->editable === false) {
                $this->koreReject($rejected, Messages::get('not_editable'));

                return;
            }

            $original = $instance->event;
        }

        $clean = null;

        if ($mode !== 'delete') {
            $clean = EventFormPayload::check($data, $settings, $this->koreResources(), $original);

            if (is_string($clean)) {
                $this->koreReject($rejected, $clean);

                return;
            }
        }

        $occurrence = $instance?->isOccurrence() === true;

        // Ya validado, pero sin alcance: se pregunta. Se guardan los datos limpios
        // sin los instantes (`startAt`/`endAt`): al repetir se vuelven a comprobar
        // y a calcular, y Livewire no lleva un Carbon en una propiedad pública.
        if ($occurrence && $scope === null) {
            $this->koreAskScope($mode, $instance, ['data' => $clean === null ? [] : array_diff_key($clean, ['startAt' => true, 'endAt' => true])]);

            return;
        }

        $deleteData = ['mode' => 'delete', 'id' => $original?->id];

        if ($occurrence) {
            $series = [
                'scope' => $scope,
                'seriesId' => $instance->seriesId,
                'recurrenceId' => $instance->recurrenceId,
                'occurrenceStart' => $instance->occurrenceStart,
            ];

            $deleteData += $series;

            if ($clean !== null) {
                // Cuánto se movió el inicio de ESTA ocurrencia respecto a como se abrió el
                // formulario: lo que `all` suma al inicio de la serie. No sale de restarle
                // `recurrenceId`, porque una ocurrencia ya modificada no empieza donde nació.
                $clean = [...$clean, ...$series, 'deltaSeconds' => Marker::parse($clean['start'])->ts - $instance->range->start->ts];
            }
        }

        $refusal = $this->korePersist(fn () => match ($mode) {
            'create' => $this->createEvent($clean),
            'edit' => $this->updateEvent($original, $clean),
            // Con `$data` como segundo argumento, que `deleteEvent()` no declara (ver su nota).
            'delete' => $this->deleteEvent($original, $deleteData),
        });

        if ($refusal !== null) {
            $this->koreReject($rejected, $refusal);

            return;
        }

        $done = ['create' => 'event_created', 'edit' => 'event_updated', 'delete' => 'event_deleted'][$mode];

        $this->toast()->success(Messages::get($done, ['title' => $clean['title'] ?? $original->title]))->send();

        // El formulario de una ocurrencia no se cierra solo: espera al diálogo de alcance. Si
        // el evento ya no era una serie cuando llegó el aviso, no habrá diálogo que lo cierre.
        if ($scope === null && isset($data['instanceId'])) {
            $this->dispatch('kore:close', force: true);
        }
    }

    /** Abre el formulario de un evento nuevo con el rango que se seleccionó. */
    private function koreOpenCreateForm(Selection $selection): void
    {
        $this->koreOpenForm('create', [
            'allDay' => $selection->allDay,
            'start' => $selection->allDay ? $selection->startMarker->toDateString() : $selection->startMarker->toDateTimeString(),
            'end' => $selection->allDay ? $selection->endMarker->toDateString() : $selection->endMarker->toDateTimeString(),
            'resourceId' => $selection->resourceId,
        ]);
    }

    /** Abre el formulario con el evento cargado. */
    private function koreOpenEditForm(CalendarEvent $event): void
    {
        $settings = $this->koreSettingsResolved();
        // Normalizar es puro (no consulta nada): da el día completo inferido, el fin
        // por defecto y si el evento traía fin, que el DTO por sí solo no sabe.
        $instance = (new EventNormalizer($settings->clock, $settings->defaultTimedSeconds, $settings->defaultAllDayDays))->normalize($event);

        // Una ocurrencia: el formulario sabe cuál es (`instanceId`) y que no se cierra
        // al guardar, porque lo siguiente es el diálogo de alcance, encima de él.
        $clicked = $this->koreClicked?->event === $event && $this->koreClicked->isOccurrence() ? $this->koreClicked : null;

        $this->koreOpenForm('edit', [
            'eventId' => $event->id,
            'title' => $event->title,
            'allDay' => $instance->allDay,
            ...self::koreFormRange($instance),
            'resourceId' => $event->resourceIds[0] ?? null,
            'color' => $event->color,
            ...($clicked === null ? [] : ['instanceId' => $clicked->instanceId, 'recurring' => true]),
        ]);
    }

    /**
     * El inicio y el fin (exclusivo) en hora de pared. Sin fin propio, `null`: el
     * formulario lo deja vacío en vez de enseñar el que se le puso por defecto.
     *
     * @return array{start: string, end: ?string}
     */
    private static function koreFormRange(EventInstance $instance): array
    {
        $format = fn ($marker) => $instance->allDay ? $marker->toDateString() : $marker->toDateTimeString();

        return [
            'start' => $format($instance->range->start),
            'end' => $instance->hasEnd ? $format($instance->range->end) : null,
        ];
    }

    /** @param  array<string, mixed>  $arguments */
    private function koreOpenForm(string $mode, array $arguments): void
    {
        $settings = $this->koreSettingsResolved();

        $this->dispatch('kore:open',
            name: 'kore-calendar-event-form',
            arguments: [
                ...$arguments,
                'mode' => $mode,
                'calendar' => $this->getId(),
                'locale' => $settings->locale,
                'firstDay' => $settings->weekRules->dow,
                'resources' => array_map(
                    fn ($resource) => ['value' => (string) $resource->id, 'label' => $resource->title],
                    $this->koreResources()->all(),
                ),
            ],
            // El nombre accesible del diálogo (`role="dialog"`): lo sabe quien lo abre.
            overlayAttributes: ['title' => Messages::get($mode === 'edit' ? 'form_edit' : 'form_create')],
        );

        // Abrir un overlay no cambia nada de lo que el calendario dibuja.
        $this->skipRender();
    }

    // ── Configuración fluida ────────────────────────────────────────────

    /**
     * Activa el formulario: seleccionar un rango abre uno de evento nuevo y pulsar
     * un evento abre el de edición, y lo que el usuario guarda llega a `createEvent()`,
     * `updateEvent()` y `deleteEvent()`. Hace falta `<livewire:kore-overlay-manager />` en el layout.
     */
    protected function eventForm(bool $enabled = true): static
    {
        $this->koreEventForm = $enabled;

        return $this;
    }

    /**
     * Pulsar un evento lo vuelve enfocable y llama a `onEventClick()`. Lo activa
     * solo `eventForm()`, y también sobrescribir `onEventClick()`: aquí está por si
     * se quiere apagar (`false`) o encender sin ninguno de los dos.
     */
    protected function eventClick(bool $enabled = true): static
    {
        return $this->set('interaction.event_click', $enabled);
    }

    /** `onEventClick()` lo define el consumidor y no la clase base. */
    private function koreOverridesEventClick(): bool
    {
        return (new \ReflectionMethod($this, 'onEventClick'))->getDeclaringClass()->getName() !== self::class;
    }
}
