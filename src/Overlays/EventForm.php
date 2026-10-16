<?php

namespace KoreUi\Calendar\Overlays;

use Illuminate\Validation\Rule;
use KoreUi\Calendar\Interaction\EventFormPayload;
use KoreUi\Calendar\Interaction\Messages;
use KoreUi\Calendar\Support\Locale;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Core\Concerns\InteractsWithFeedback;
use KoreUi\Overlay\OverlayComponent;
use Livewire\Attributes\Locked;

/**
 * El formulario de un evento (crear y editar), como overlay de kore-ui.
 *
 * NO guarda nada. Valida lo que el usuario escribió y se lo devuelve al
 * calendario que lo abrió con un evento de Livewire (`SAVED`); el calendario lo
 * vuelve a comprobar y llama a `createEvent()` / `updateEvent()` /
 * `deleteEvent()` del consumidor. Un formulario que escribiera en la base de
 * datos tendría que saber del modelo de cada aplicación, y se saltaría el
 * camino de seguridad de las demás acciones (docs/calendar.md §11).
 *
 * Todo viaja como hora de pared en la zona del calendario ('Y-m-d' o
 * 'Y-m-d H:i:s'). En el formulario el fin de un evento de día completo es
 * INCLUSIVO («del 14 al 16» incluye el 16, como se dice en voz alta); al
 * devolverlo se convierte al fin exclusivo que usa el resto del paquete.
 *
 * Los argumentos con los que se abre (`mount`) vienen del calendario y llegan
 * con el fin ya exclusivo, tal como lo guarda el motor.
 *
 * Una ocurrencia de una serie (`recurring`) no se cierra al guardar ni al eliminar:
 * el calendario responde abriendo el diálogo de alcance (`RecurrenceScope`) encima
 * de este formulario, y es ese diálogo el que cierra la pila entera. Dejarlo
 * abierto evita abrir un overlay mientras otro se cierra, y si el usuario cancela
 * el alcance vuelve a lo que estaba escribiendo. Tampoco pide confirmación para
 * eliminar: elegir el alcance en un diálogo que dice «Eliminar» ya lo es.
 */
class EventForm extends OverlayComponent
{
    // Para pedir confirmación antes de eliminar (`confirm()`), con el mismo
    // diálogo y la misma lista de callbacks autorizados que el resto de kore-ui.
    use InteractsWithFeedback;

    /**
     * El aviso con el resultado. Propio del paquete y global (Livewire no dirige
     * un evento a UNA instancia): lleva el id del calendario destino y cada
     * calendario descarta los que no son suyos, como el `callerRef` del confirm.
     */
    public const SAVED = 'kore-calendar:event-saved';

    // Lo que decide el calendario y el navegador no puede tocar: a quién se
    // devuelve el resultado, si se crea o se edita, y qué evento.
    #[Locked]
    public string $calendar = '';

    #[Locked]
    public string $mode = 'create';

    #[Locked]
    public string|int|null $eventId = null;

    /** La ocurrencia que se edita (`instanceId`), si es de una serie: el id del evento es el de la serie. */
    #[Locked]
    public ?string $instanceId = null;

    /** Es una ocurrencia: guardar y eliminar piden alcance, y el formulario espera a la respuesta abierto. */
    #[Locked]
    public bool $recurring = false;

    /** El título con el que se abrió: el del diálogo de eliminar no cambia mientras se escribe. */
    #[Locked]
    public string $eventTitle = '';

    /** El color con el que se abrió, por si no es uno de la lista (`#ff0000`) y hay que ofrecerlo para no borrarlo. */
    #[Locked]
    public ?string $originalColor = null;

    /** @var list<array{value: string, label: string}> */
    #[Locked]
    public array $resources = [];

    #[Locked]
    public string $calendarLocale = '';

    #[Locked]
    public int $weekStart = 1;

    // Lo que se edita. `?string` y no `string`: un selector de fecha o de hora
    // vaciado con su aspa asigna `null`, y una propiedad tipada `string` lanzaría.
    public ?string $title = '';

    public bool $allDay = false;

    public ?string $startDate = '';

    public ?string $startTime = '';

    public ?string $endDate = '';

    public ?string $endTime = '';

    public ?string $resourceId = null;

    public ?string $color = null;

    /**
     * @param  string  $calendar  Id del componente Livewire del calendario que lo abre.
     * @param  ?string  $start  'Y-m-d' (día completo) o 'Y-m-d H:i:s'.
     * @param  ?string  $end  Igual, EXCLUSIVO. `null` si el evento no tiene fin.
     * @param  list<array{value: string, label: string}>  $resources
     */
    public function mount(
        string $calendar,
        string $mode = 'create',
        string|int|null $eventId = null,
        string $title = '',
        bool $allDay = false,
        ?string $start = null,
        ?string $end = null,
        string|int|null $resourceId = null,
        ?string $color = null,
        array $resources = [],
        ?string $locale = null,
        int $firstDay = 1,
        ?string $instanceId = null,
        bool $recurring = false,
    ): void {
        $this->calendar = $calendar;
        // Lo que no es 'edit' es crear: el modo decide si hay botón de eliminar.
        $this->mode = $mode === 'edit' ? 'edit' : 'create';
        $this->eventId = $this->mode === 'edit' ? $eventId : null;
        // Solo se edita una ocurrencia con su `instanceId`: sin él no habría forma de saber cuál.
        $this->instanceId = $this->mode === 'edit' && $recurring && $instanceId !== null && $instanceId !== '' ? $instanceId : null;
        $this->recurring = $this->instanceId !== null;
        $this->eventTitle = $title;
        $this->title = $title;
        $this->allDay = $allDay;
        $this->resources = $resources;
        $this->resourceId = $resourceId === null ? null : (string) $resourceId;
        $this->color = $color === '' ? null : $color;
        $this->originalColor = $this->color;
        $this->calendarLocale = Locale::resolve($locale);
        $this->weekStart = $firstDay;

        [$this->startDate, $this->startTime] = self::split($start);
        [$this->endDate, $this->endTime] = self::split($end);

        // El fin de un día completo se edita inclusivo: el último día que ocupa.
        if ($allDay && $this->endDate !== '') {
            $this->endDate = Marker::parse($this->endDate)->addDays(-1)->toDateString();
        }
    }

    // ── Overlay ─────────────────────────────────────────────────────────

    public static function overlayType(): string
    {
        return 'modal';
    }

    public static function overlaySize(): string
    {
        return 'lg';
    }

    // Un formulario a medias no se pierde por un clic fuera. Escape sí lo cierra:
    // es un gesto deliberado, y es lo que hace todo overlay de kore-ui.
    public static function closesOnClickAway(): bool
    {
        return false;
    }

    // Cada apertura es un overlay nuevo con sus datos: no hay nada que conservar al cerrar.
    public static function destroysOnClose(): bool
    {
        return true;
    }

    // ── Acciones ────────────────────────────────────────────────────────

    public function save(): void
    {
        // Una hora de fin sin fecha es «ese mismo día»: lo natural al escribirla.
        if (! $this->allDay && self::filled($this->endTime) && ! self::filled($this->endDate)) {
            $this->endDate = $this->startDate;
        }

        $this->validate();

        if (($conflict = $this->endBeforeStart()) !== null) {
            $this->addError($conflict, $this->koreText('form_end_before_start'));

            return;
        }

        if ($this->recurring) {
            // Sin cerrar: ver la nota de la clase.
            $this->dispatch(self::SAVED, calendar: $this->calendar, data: $this->payload());

            return;
        }

        $this->closeWith([[self::SAVED, ['calendar' => $this->calendar, 'data' => $this->payload()]]]);
    }

    /**
     * Pide confirmación. La eliminación en sí es `confirmDelete()`, que solo se
     * ejecuta si el diálogo la autoriza (`koreConfirmable`).
     */
    public function delete(): void
    {
        if ($this->mode !== 'edit') {
            return;
        }

        if ($this->recurring) {
            $this->dispatch(self::SAVED, calendar: $this->calendar, data: $this->deletePayload());

            return;
        }

        $this->confirm($this->koreText('form_delete_confirm', ['title' => $this->eventTitle]))
            ->description($this->koreText('form_delete_description'))
            ->type('warning')
            ->confirmText($this->koreText('form_delete'))
            ->cancelText($this->koreText('form_cancel'))
            ->onConfirm('confirmDelete')
            ->send();
    }

    public function confirmDelete(): void
    {
        if ($this->mode !== 'edit') {
            return;
        }

        // `closeAll()` y no `closeWith()`: esta respuesta llega mientras el diálogo de
        // confirmación se cierra (su `close()` y este van uno detrás de otro, con 300 ms
        // de transición cada uno en overlay.js). Cerrar la pila entera es lo que no
        // depende de en qué punto de esa transición caiga: el evento ya no existe y el
        // formulario no tiene a dónde volver. Es lo único que difiere de `closeWith()`.
        $this->dispatch(self::SAVED, calendar: $this->calendar, data: $this->deletePayload());
        $this->closeAll();
    }

    /** @return array{mode: string, id: string|int|null, instanceId?: string} */
    private function deletePayload(): array
    {
        return ['mode' => 'delete', 'id' => $this->eventId] + ($this->instanceId === null ? [] : ['instanceId' => $this->instanceId]);
    }

    // ── Validación ──────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        $colors = array_keys(EventFormPayload::COLORS);

        if ($this->originalColor !== null) {
            $colors[] = $this->originalColor;
        }

        return [
            'title' => ['required', 'string', 'max:'.EventFormPayload::TITLE_MAX],
            'startDate' => ['required', 'date_format:Y-m-d'],
            'startTime' => $this->allDay ? ['nullable'] : ['required', 'date_format:H:i'],
            'endDate' => ['nullable', 'date_format:Y-m-d'],
            // La hora de fin sin fecha ya se resolvió arriba; la fecha de fin sin hora no tiene resolución.
            'endTime' => $this->allDay ? ['nullable'] : ['nullable', 'date_format:H:i', 'required_with:endDate'],
            'resourceId' => ['nullable', Rule::in(array_column($this->resources, 'value'))],
            'color' => ['nullable', Rule::in($colors)],
        ];
    }

    /**
     * Mensajes por regla y no por campo: son tres y valen para todos. Los de Laravel
     * salen de los `lang/` de la aplicación y no de los del paquete.
     *
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'required' => $this->koreText('form_required'),
            'required_with' => $this->koreText('form_required'),
            'string' => $this->koreText('form_invalid'),
            'max' => $this->koreText('form_invalid'),
            'date_format' => $this->koreText('form_invalid'),
            'in' => $this->koreText('form_invalid'),
        ];
    }

    /** El campo del fin si queda antes que el inicio, o `null` si el orden es bueno o no hay fin. */
    private function endBeforeStart(): ?string
    {
        if (! self::filled($this->endDate)) {
            return null;
        }

        if ($this->allDay) {
            return $this->endDate < $this->startDate ? 'endDate' : null;
        }

        // 'Y-m-d' y 'H:i' en ceros a la izquierda se ordenan como texto igual que como fecha.
        if ($this->endDate !== $this->startDate) {
            return $this->endDate < $this->startDate ? 'endDate' : null;
        }

        return $this->endTime < $this->startTime ? 'endTime' : null;
    }

    // ── Salida ──────────────────────────────────────────────────────────

    /**
     * `instanceId` solo viaja si es una ocurrencia.
     *
     * @return array{mode: string, id: string|int|null, title: string, allDay: bool, start: string, end: ?string, resourceId: ?string, color: ?string, instanceId?: string}
     */
    private function payload(): array
    {
        $hasEnd = self::filled($this->endDate);

        if ($this->allDay) {
            $start = $this->startDate;
            // De inclusivo (lo que se escribe) a exclusivo (lo que se guarda).
            $end = $hasEnd ? Marker::parse($this->endDate)->addDays(1)->toDateString() : null;
        } else {
            $start = "{$this->startDate} {$this->startTime}:00";
            $end = $hasEnd ? "{$this->endDate} {$this->endTime}:00" : null;
        }

        return [
            'mode' => $this->mode,
            'id' => $this->eventId,
            'title' => trim((string) $this->title),
            'allDay' => $this->allDay,
            'start' => $start,
            'end' => $end,
            'resourceId' => self::filled($this->resourceId) ? (string) $this->resourceId : null,
            'color' => self::filled($this->color) ? $this->color : null,
        ] + ($this->instanceId === null ? [] : ['instanceId' => $this->instanceId]);
    }

    /**
     * 'Y-m-d' o 'Y-m-d H:i[:s]' → [fecha, 'H:i']. Lo que no se entiende queda
     * vacío y lo dice la validación al guardar.
     *
     * @return array{0: string, 1: string}
     */
    private static function split(?string $wall): array
    {
        if ($wall === null || ! preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2}))?/', $wall, $m)) {
            return ['', ''];
        }

        return [$m[1], $m[2] ?? ''];
    }

    private static function filled(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }

    /** Un texto de interfaz en el idioma del calendario que abrió el formulario. */
    protected function koreText(string $key, array $replace = []): string
    {
        return Messages::get($key, $replace, $this->calendarLocale);
    }

    // ── Render ──────────────────────────────────────────────────────────

    public function render()
    {
        $colors = [];

        foreach (EventFormPayload::COLORS as $token => $key) {
            $colors[] = ['value' => $token, 'label' => $this->koreText($key)];
        }

        // Su color, si no es de la lista: sin esta opción el selector lo enseñaría vacío y guardar lo borraría.
        if ($this->originalColor !== null && ! isset(EventFormPayload::COLORS[$this->originalColor])) {
            array_unshift($colors, ['value' => $this->originalColor, 'label' => $this->originalColor]);
        }

        return view('kore-calendar::livewire.event-form', ['koreColors' => $colors]);
    }
}
