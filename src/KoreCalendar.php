<?php

namespace KoreUi\Calendar;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use KoreUi\Calendar\Availability\ConflictFinder;
use KoreUi\Calendar\Concerns\ManagesEventForm;
use KoreUi\Calendar\Concerns\ManagesRecurrence;
use KoreUi\Calendar\Concerns\UndoesChanges;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventCollector;
use KoreUi\Calendar\Events\EventInstance;
use KoreUi\Calendar\Events\EventNormalizer;
use KoreUi\Calendar\Events\Sources\CallbackSource;
use KoreUi\Calendar\Interaction\Bounds;
use KoreUi\Calendar\Interaction\EventMutation;
use KoreUi\Calendar\Interaction\Events\EventMoved;
use KoreUi\Calendar\Interaction\Events\EventResized;
use KoreUi\Calendar\Interaction\Messages;
use KoreUi\Calendar\Interaction\MutationBuilder;
use KoreUi\Calendar\Interaction\MutationRejected;
use KoreUi\Calendar\Interaction\MutationValidator;
use KoreUi\Calendar\Interaction\Selection;
use KoreUi\Calendar\Resources\ResourceSet;
use KoreUi\Calendar\Support\Locale;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\ViewRange;
use KoreUi\Calendar\View\CalendarRenderer;
use KoreUi\Calendar\View\CalendarSettings;
use KoreUi\Calendar\View\CalendarViews;
use KoreUi\Core\Concerns\InteractsWithFeedback;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Clase base de un calendario con estado.
 *
 *     class TeamCalendar extends KoreCalendar
 *     {
 *         public function configure(): void
 *         {
 *             $this->views(['month', 'week', 'list'])->timezone('Europe/Madrid')->slotMinTime('08:00');
 *         }
 *
 *         public function events(CarbonImmutable $from, CarbonImmutable $to): iterable
 *         {
 *             return Meeting::overlapping($from, $to)->get()->map->toCalendarEvent();
 *         }
 *     }
 *
 * Convenciones de KoreDataTable / KoreKanban:
 *
 * - `configure()` se aplica en `booted()`, no en `mount()`: la configuración es
 *   `protected`, Livewire no la serializa, y si solo corriera en `mount()` se
 *   perdería en la primera acción.
 * - El único estado público es la navegación (`view`, `date`), en la URL: se
 *   comparte, sobrevive a recargar y se prueba con `Livewire::test()`.
 * - Lo que se pasa a la vista lleva prefijo `kore`: las propiedades públicas del
 *   consumidor ganan sobre los datos de `view()`, y una `$calendar` suya taparía
 *   la nuestra.
 *
 * Interacción (`->editable()`, `->selectable()`): el navegador solo propone
 * (`moveEvent`, `resizeEvent`, `selectRange`) y el servidor decide. Nada de lo que
 * llega es de fiar: el evento se vuelve a buscar entre los que `events()` devuelve
 * para la vista, el destino se vuelve a ajustar y validar, y solo entonces se
 * llama al hook de persistencia del consumidor (`persistMove`, `persistResize`,
 * `onDateSelect`), dentro de una transacción.
 *
 * Crear y editar (`->eventForm()`): seleccionar un rango abre el formulario de
 * kore-ui (un overlay) y pulsar un evento abre el de edición. El formulario no
 * guarda nada: devuelve los datos y el calendario, por el mismo camino de
 * arriba, llama a `createEvent()`, `updateEvent()` o `deleteEvent()`.
 *
 * Deshacer (`->undoable()`): tras mover o redimensionar, un toast con «Deshacer»
 * que repite el cambio al revés con el mismo hook de persistencia.
 *
 * Horario laboral y conflictos (`->businessHours()`, `->eventConstraint()`,
 * `->selectConstraint()`, `->selectOverlap()`, `->highlightConflicts()`): lo no
 * laborable se pinta, las restricciones las decide el servidor al validar y
 * `conflicts()` lista los eventos que se pisan en la vista.
 *
 * Series recurrentes: mover, redimensionar, editar o eliminar una ocurrencia no
 * persiste nada hasta que el usuario elige el alcance (solo esta, esta y las
 * siguientes, toda la serie) en un diálogo. La acción queda pendiente en el
 * servidor (`$koreRecurrencePending`, `#[Locked]`) y se repite entera, con la
 * misma validación, cuando llega la elección (`applyRecurrenceScope()`).
 */
abstract class KoreCalendar extends Component
{
    // toast() para los motivos de rechazo. Es el mismo trait que usa el DataTable
    // (WithBulkActions), así que el toast llega al mismo gestor del navegador.
    use InteractsWithFeedback;

    // El formulario de evento (crear, editar, eliminar), el alcance de las series
    // recurrentes y deshacer viven aparte por tamaño; usan las piezas de esta clase
    // (koreFind, koreInstances, korePersist…).
    use ManagesEventForm;
    use ManagesRecurrence;
    use UndoesChanges;

    #[Url(as: 'view', except: '')]
    public string $view = '';

    #[Url(as: 'date', except: '')]
    public string $date = '';

    /** Primera fila de la ventana del timeline virtualizado. No va a la URL. */
    public int $timelineRow = 0;

    /** Ajustes de `configure()` con la forma del config ('time.slot_duration' => '15m'). */
    protected array $koreSettings = [];

    /** @var list<string>|null */
    protected ?array $koreViews = null;

    private ?ResourceSet $koreResourceCache = null;

    /**
     * Los eventos que intersecan [from, to), en instantes con la zona de la vista.
     * CalendarEvent, arrays con su forma, o lo que devuelva `HasCalendarEvents`.
     */
    abstract public function events(CarbonImmutable $from, CarbonImmutable $to): iterable;

    /** Recursos (salas, personas…). Sin recursos, las vistas por recurso no se ofrecen. */
    public function resources(): iterable
    {
        return [];
    }

    /** La configuración del calendario. Se llama en cada petición. */
    public function configure(): void {}

    public function booted(): void
    {
        $this->koreSettings = [];
        $this->koreViews = null;
        $this->koreEventForm = false;
        $this->koreUndoable = false;
        $this->configure();
    }

    // ── Navegación (acciones públicas) ──────────────────────────────────

    // previous/next solo necesitan el rango de la vista: sin consultar eventos.
    public function previous(): void
    {
        $this->date = $this->koreRange()->previous()->toDateString();
    }

    public function next(): void
    {
        $this->date = $this->koreRange()->next()->toDateString();
    }

    public function today(): void
    {
        $this->date = '';
    }

    public function changeView(string $view): void
    {
        // Lo que llega del navegador no es fiable: solo vistas que este calendario ofrece.
        if (in_array($view, $this->koreAvailableViews(), true)) {
            $this->view = $view;
            $this->timelineRow = 0;
        }
    }

    public function goToDate(string $date): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            $this->date = $date;
        }
    }

    /** El timeline pide otra ventana de filas al hacer scroll. */
    public function timelineScroll(int $row): void
    {
        $this->timelineRow = max(0, $row);
    }

    // ── Interacción (acciones públicas) ─────────────────────────────────
    //
    // Las tres siguen el mismo camino y en el mismo orden, porque lo que manda el
    // navegador no es fiable (docs/calendar.md §11):
    //
    //   1. El evento se vuelve a buscar entre los que `events()` devuelve para la
    //      vista dibujada. Nunca por id directo: así se respetan los scopes del
    //      consumidor y un id ajeno no se puede mover (IDOR).
    //   2. El destino se vuelve a ajustar (snap) y se construye la mutación.
    //   3. Se valida: editabilidad, rango válido, solapes.
    //   4. Se llama al hook del consumidor, dentro de una transacción.
    //
    // Los markers son los de `Marker::ts`: horas de pared en la zona de la vista,
    // nunca instantes.

    /**
     * Mueve un evento (arrastrándolo, o con el teclado).
     *
     * @param  string|int  $id  El `instanceId` que el servidor pinta en `data-kore-instance`. También vale el id del
     *                          evento (lo que mandaba el cliente antes de las series), salvo en una serie: todas sus
     *                          ocurrencias comparten id y con él no se sabe cuál es, así que se rechaza.
     * @param  int  $start  Marker donde cae el INICIO del evento, no el punto donde se agarró.
     * @param  bool  $allDay  Modalidad del destino: el carril de todo el día o una celda con hora.
     * @param  string|int|null  $resourceId  Recurso (columna o fila) donde se soltó. `null` en las vistas sin recursos.
     * @param  string|int|null  $fromResourceId  Recurso del que se agarró. Hace falta en un evento con varios.
     * @return array{ok: bool, message: string, needsScope?: true} `message` es el anuncio para aria-live si `ok`, o el motivo si no.
     *                                                           `needsScope`: era una ocurrencia, no se guardó nada y se abrió el diálogo de alcance.
     */
    public function moveEvent(string|int $id, int $start, bool $allDay, string|int|null $resourceId = null, string|int|null $fromResourceId = null): array
    {
        return $this->koreMove($id, $start, $allDay, $resourceId, $fromResourceId);
    }

    /**
     * El cuerpo de `moveEvent()`, con el alcance si ya se eligió. Lo repite
     * `applyRecurrenceScope()` con los mismos argumentos que se validaron la
     * primera vez, pero volviendo a resolverlo todo: entre el gesto y la elección
     * el evento pudo cambiar o desaparecer.
     *
     * @return array{ok: bool, message: string, needsScope?: true}
     */
    protected function koreMove(string|int $id, int $start, bool $allDay, string|int|null $resourceId, string|int|null $fromResourceId, ?string $scope = null): array
    {
        $settings = $this->koreSettingsResolved();

        if (! Bounds::contains($start)) {
            return $this->koreReject('move_rejected', $this->koreText('out_of_range'));
        }

        $instances = $this->koreInstances($settings);
        $original = self::koreFind($instances, $id);

        if ($original === null) {
            return $this->koreReject('move_rejected', $this->koreText('event_not_found'), stale: true);
        }

        $resourceIds = null;

        if ($resourceId !== null) {
            // El destino tiene que existir en resources(): el cliente puede mandar cualquier id.
            $target = $this->koreResources()->find($resourceId);

            if ($target === null) {
                return $this->koreReject('move_rejected', $this->koreText('resource_not_found'));
            }

            // Con el id del recurso tal como lo conoce el ResourceSet (1 y '1' son el mismo).
            $resourceIds = MutationBuilder::resourcesAfterDrop($original->event->resourceIds, $target->id, $fromResourceId);

            if ($resourceIds === null) {
                return $this->koreReject('move_rejected', $this->koreText('invalid_change'));
            }
        }

        $mutation = MutationBuilder::fromSettings($settings)->move($original, Marker::fromTimestamp($start), $allDay, $resourceIds);

        if ($mutation->isNoop()) {
            return $this->koreUnchanged();
        }

        $refusal = (new MutationValidator($settings, $this->koreResources()))->validateMove($mutation, $instances);

        // Válido pero sin alcance: no se guarda nada. Se pregunta DESPUÉS de validar para
        // no hacer elegir entre tres opciones un cambio que de todas formas se rechazaría.
        if ($refusal === null && $scope === null && $original->isOccurrence()) {
            return $this->koreAskScope('move', $original, [
                'start' => $start, 'allDay' => $allDay, 'resourceId' => $resourceId, 'fromResourceId' => $fromResourceId,
            ]);
        }

        if ($scope !== null && $original->isOccurrence()) {
            $mutation = $mutation->withScope($scope);
        }

        $refusal ??= $this->korePersist(fn () => $this->persistMove($original->event, $mutation));

        if ($refusal !== null) {
            return $this->koreReject('move_rejected', $refusal);
        }

        event(new EventMoved(static::class, $original->event, $mutation));

        $announcement = $this->koreAnnounce('event_moved', $original->event, $mutation->startMarker, $mutation->allDay, $settings);
        $this->koreOfferUndo('move', $mutation, $announcement);

        return $this->koreDone($announcement, $scope);
    }

    /**
     * Cambia la duración de un evento arrastrando uno de sus bordes.
     *
     * @param  string|int  $id  Como en `moveEvent()`: el `instanceId`.
     * @param  int  $edge  Marker del borde nuevo: el fin (exclusivo) o, con `$fromStart`, el inicio.
     * @return array{ok: bool, message: string, needsScope?: true}
     */
    public function resizeEvent(string|int $id, int $edge, bool $fromStart = false): array
    {
        return $this->koreResize($id, $edge, $fromStart);
    }

    /** @return array{ok: bool, message: string, needsScope?: true} */
    protected function koreResize(string|int $id, int $edge, bool $fromStart, ?string $scope = null): array
    {
        $settings = $this->koreSettingsResolved();

        if (! Bounds::contains($edge)) {
            return $this->koreReject('resize_rejected', $this->koreText('out_of_range'));
        }

        $instances = $this->koreInstances($settings);
        $original = self::koreFind($instances, $id);

        if ($original === null) {
            return $this->koreReject('resize_rejected', $this->koreText('event_not_found'), stale: true);
        }

        $mutation = MutationBuilder::fromSettings($settings)->resize($original, Marker::fromTimestamp($edge), $fromStart);

        if ($mutation === null) {
            return $this->koreReject('resize_rejected', $this->koreText('invalid_duration'));
        }

        if ($mutation->isNoop()) {
            return $this->koreUnchanged();
        }

        $refusal = (new MutationValidator($settings, $this->koreResources()))->validateResize($mutation, $instances);

        if ($refusal === null && $scope === null && $original->isOccurrence()) {
            return $this->koreAskScope('resize', $original, ['edge' => $edge, 'fromStart' => $fromStart]);
        }

        if ($scope !== null && $original->isOccurrence()) {
            $mutation = $mutation->withScope($scope);
        }

        $refusal ??= $this->korePersist(fn () => $this->persistResize($original->event, $mutation));

        if ($refusal !== null) {
            return $this->koreReject('resize_rejected', $refusal);
        }

        event(new EventResized(static::class, $original->event, $mutation));

        // Un día completo acaba el día ANTES de su fin exclusivo: «termina el 6», no «el 7».
        $when = match (true) {
            $fromStart => $mutation->startMarker,
            $mutation->allDay => $mutation->endMarker->addDays(-1),
            default => $mutation->endMarker,
        };

        $announcement = $this->koreAnnounce($fromStart ? 'event_resized_start' : 'event_resized', $original->event, $when, $mutation->allDay, $settings);
        $this->koreOfferUndo('resize', $mutation, $announcement);

        return $this->koreDone($announcement, $scope);
    }

    /**
     * Un rango seleccionado arrastrando sobre la rejilla. Si el calendario es
     * `selectable()`, lo ajusta, lo valida y llama a `onDateSelect()`.
     *
     * @param  int  $start  Marker del primer borde.
     * @param  int  $end  Marker del segundo borde (exclusivo). Si llega antes que `$start`, se ordenan.
     */
    public function selectRange(int $start, int $end, bool $allDay, string|int|null $resourceId = null): void
    {
        $settings = $this->koreSettingsResolved();

        // Un calendario que no es selectable no explica nada: el cliente no ofrece el gesto.
        if (! $settings->selectable) {
            $this->skipRender();

            return;
        }

        $target = null;

        if ($resourceId !== null) {
            $target = $this->koreResources()->find($resourceId);
        }

        $refusal = match (true) {
            ! Bounds::contains($start, $end) => $this->koreText('out_of_range'),
            $resourceId !== null && $target === null => $this->koreText('resource_not_found'),
            default => null,
        };

        $selection = null;

        if ($refusal === null) {
            $selection = MutationBuilder::fromSettings($settings)->select(Marker::fromTimestamp($start), Marker::fromTimestamp($end), $allDay, $target?->id);
            $validator = new MutationValidator($settings, $this->koreResources());

            // Los eventos de la vista solo se piden si hay una regla que los use (`selectOverlap(false)`):
            // seleccionar es un gesto frecuente y no debería costar una consulta de más.
            $refusal = $validator->validateSelection($selection)
                ?? $validator->validateSelectionRules($selection, $settings->selectOverlap ? [] : $this->koreInstances($settings));
        }

        if ($refusal !== null) {
            $this->toast()->error($refusal)->send();
            $this->skipRender();

            return;
        }

        $this->onDateSelect($selection);
    }

    // ── Conflictos ──────────────────────────────────────────────────────

    /**
     * Los pares de eventos del rango dibujado que se solapan en el mismo recurso (los
     * que no tienen ninguno, entre sí), con las reglas de `MutationValidator`: sin
     * contar el mismo `groupId` ni los eventos de fondo, y respetando `overlap: false`.
     * Sirve para un resumen propio («3 conflictos esta semana»); para marcarlos en el
     * calendario, `highlightConflicts()`.
     *
     * Vuelve a pedir `events()`, como las acciones: no hay caché entre peticiones.
     *
     * Protegido a propósito: Livewire deja llamar a cualquier método público
     * desde el navegador (`$wire.conflicts()`) y le devolvería las instancias
     * serializadas, `meta` incluido, más de lo que la vista pinta. La subclase lo
     * usa en su `render()` o en un `#[Computed]` que devuelva solo lo que se muestra.
     *
     * @return list<array{0: EventInstance, 1: EventInstance}>
     */
    protected function conflicts(): array
    {
        return ConflictFinder::find($this->koreInstances($this->koreSettingsResolved()));
    }

    // ── Interacción (hooks del consumidor) ──────────────────────────────

    /**
     * Guarda un evento movido. Se llama solo tras validar, dentro de una transacción.
     *
     * Devuelve `null` o `true` para aceptar; `false` para rechazar con el mensaje
     * genérico; un texto para rechazar con ese motivo (sale en el toast). Si
     * rechaza o lanza, la transacción se deshace. Sin tipo de retorno a propósito:
     * así se puede declarar `: void` o no devolver nada, y `null` es «vale».
     *
     * Por defecto rechaza, explicando que falta implementarlo: un calendario
     * `editable()` que no guarda nada no debe parecer que funciona.
     *
     * Con una ocurrencia de una serie llega `$mutation->scope` (`this`, `following` o `all`)
     * y la forma de guardar cada uno está en `RecurrenceEditor`. `$event` es la ocurrencia
     * (con SUS fechas, sin regla): la serie la carga el consumidor con `$mutation->seriesId`.
     *
     * @return bool|string|null
     */
    protected function persistMove(CalendarEvent $event, EventMutation $mutation)
    {
        return $this->koreText('hook_missing', ['hook' => 'persistMove', 'class' => static::class]);
    }

    /**
     * Guarda un evento redimensionado. Mismo contrato que `persistMove()`.
     *
     * @return bool|string|null
     */
    protected function persistResize(CalendarEvent $event, EventMutation $mutation)
    {
        return $this->koreText('hook_missing', ['hook' => 'persistResize', 'class' => static::class]);
    }

    /**
     * Se seleccionó un rango de fechas (ya ajustado y validado). Con `eventForm()` abre
     * el formulario de un evento nuevo con ese rango (y el recurso donde se soltó);
     * sin él no hace nada. Al sobrescribirlo, `parent::onDateSelect($selection)` conserva eso.
     */
    protected function onDateSelect(Selection $selection): void
    {
        if ($this->koreEventForm) {
            $this->koreOpenCreateForm($selection);
        }
    }

    /**
     * Se pulsó un evento (clic o Intro; uno con `url` es un enlace y no llega aquí).
     * Ya se resolvió entre los de la vista. Con `eventForm()` abre el formulario de
     * edición, salvo en un evento con `editable: false`; sin él no hace nada.
     *
     * Sobrescribirlo hace los eventos pulsables sin necesidad de `eventForm()`:
     * se pintan enfocables y este hook recibe el clic.
     */
    protected function onEventClick(CalendarEvent $event): void
    {
        if ($this->koreEventForm && $event->editable !== false) {
            $this->koreOpenEditForm($event);

            return;
        }

        $this->skipRender();
    }

    /**
     * Crea el evento que el usuario escribió en el formulario. Mismo contrato y mismas
     * garantías que `persistMove()`: se llama tras validar, dentro de una transacción;
     * `null` o `true` aceptan y `false` o un texto rechazan con ese motivo.
     *
     * `$data`, ya limpio:
     *   - `mode` ('create'), `id` (`null`), `title`, `allDay`, `color` (un token de kore-ui o `null`)
     *   - `start`, `end`: hora de pared en la zona del calendario ('Y-m-d' o 'Y-m-d H:i:s'),
     *     el fin EXCLUSIVO y `null` si el evento no tiene
     *   - `startAt`, `endAt`: los mismos como instantes (`CarbonImmutable`), listos para guardar
     *   - `resourceId` (el recurso o `null`) y `resourceIds` (la lista)
     *
     * Por defecto rechaza, explicando que falta implementarlo.
     *
     * @param  array<string, mixed>  $data
     * @return bool|string|null
     */
    protected function createEvent(array $data)
    {
        return $this->koreText('hook_missing', ['hook' => 'createEvent', 'class' => static::class]);
    }

    /**
     * Guarda los cambios de un evento editado en el formulario. `$event` es el evento
     * actual (el de `events()`); `$data` tiene la forma de `createEvent()`, con `mode`
     * 'edit' y su `id`. Un evento con varios recursos conserva todos si no se toca el primero.
     *
     * Si es una ocurrencia de una serie, `$data` añade `scope` (`this`, `following`, `all`),
     * `seriesId`, `recurrenceId` (`Marker`, su inicio original), `occurrenceStart` (ese
     * inicio como instante) y `deltaSeconds` (cuánto se movió el inicio, para `all`).
     *
     * @param  array<string, mixed>  $data
     * @return bool|string|null
     */
    protected function updateEvent(CalendarEvent $event, array $data)
    {
        return $this->koreText('hook_missing', ['hook' => 'updateEvent', 'class' => static::class]);
    }

    /**
     * Elimina un evento, después de que el usuario lo confirmara en el formulario.
     *
     * Se llama con un segundo argumento, `array $data`, que este método no declara para
     * no romper a quien ya lo sobrescribe con un solo parámetro: declara
     * `deleteEvent(CalendarEvent $event, array $data = [])` para recibirlo. Trae `mode`
     * ('delete'), `id` y, en una ocurrencia, `scope`, `seriesId`, `recurrenceId` y
     * `occurrenceStart` (las mismas claves que `updateEvent()`).
     *
     * @return bool|string|null
     */
    protected function deleteEvent(CalendarEvent $event)
    {
        return $this->koreText('hook_missing', ['hook' => 'deleteEvent', 'class' => static::class]);
    }

    // ── Interacción (piezas) ────────────────────────────────────────────

    /**
     * Los eventos de la vista dibujada, normalizados: lo mismo que ve el usuario.
     * Vuelve a llamar a `events()` en cada acción a propósito (nada de caché entre
     * peticiones): un evento borrado en otra pestaña tiene que dejar de ser movible.
     *
     * @return list<EventInstance>
     */
    protected function koreInstances(CalendarSettings $settings): array
    {
        $range = ViewRange::for($settings->view, $settings->date, $settings->viewOptions);

        return (new EventCollector(
            $settings->clock,
            new EventNormalizer($settings->clock, $settings->defaultTimedSeconds, $settings->defaultAllDayDays),
        ))->collect(
            [new CallbackSource(fn (CarbonImmutable $from, CarbonImmutable $to) => $this->events($from, $to))],
            $range->render,
        );
    }

    /**
     * Resuelve lo que manda el cliente entre las instancias de la vista.
     *
     * Primero por `instanceId` (lo que pinta `data-kore-instance`): es lo único que
     * distingue las ocurrencias de una serie, que comparten `event->id`. Si no, por
     * el id del evento, que es lo que mandaban los clientes anteriores a las series.
     * Pero ese segundo camino no vale para una ocurrencia: con un id de serie no hay
     * forma de saber de cuál se habla, y elegir la primera movería una al azar.
     *
     * @param  list<EventInstance>  $instances
     */
    protected static function koreFind(array $instances, string|int $id): ?EventInstance
    {
        // Como string: 1 y '1' son el mismo evento (el id viaja por JSON).
        $id = (string) $id;

        foreach ($instances as $instance) {
            if ($instance->instanceId === $id) {
                return $instance;
            }
        }

        foreach ($instances as $instance) {
            if ((string) $instance->event->id === $id) {
                return $instance->isOccurrence() ? null : $instance;
            }
        }

        return null;
    }

    /**
     * Ejecuta un hook de persistencia: `null` si aceptó o el motivo del rechazo.
     *
     * Dentro de `DB::transaction()` cuando hay base de datos: si el hook rechaza,
     * lo que hubiera escrito antes se deshace. Sin base de datos (un calendario
     * que guarda en una API, o los tests de un paquete) se ejecuta tal cual.
     */
    protected function korePersist(Closure $hook): ?string
    {
        $run = function () use ($hook): void {
            $result = $hook();

            if ($result === false || (is_string($result) && trim($result) === '')) {
                throw new MutationRejected($this->koreText('persist_failed'));
            }

            if (is_string($result)) {
                throw new MutationRejected($result);
            }
        };

        try {
            $this->koreDatabaseAvailable() ? DB::transaction($run) : $run();

            return null;
        } catch (MutationRejected $e) {
            return $e->getMessage();
        } catch (ValidationException $e) {
            // Una regla de negocio del consumidor, no un fallo: se muestra su motivo.
            return $e->validator->errors()->first() ?: $this->koreText('persist_failed');
        } catch (AuthorizationException) {
            // `$this->authorize('update', $meeting)` dentro del hook.
            return $this->koreText('not_editable');
        } catch (Throwable $e) {
            // No se traga: es un fallo del consumidor (o de su base de datos) y tiene
            // que llegar a su log. Al usuario solo le decimos que no se guardó.
            report($e);

            return $this->koreText('persist_failed');
        }
    }

    /** Hay una conexión por defecto configurada y se puede abrir. */
    private function koreDatabaseAvailable(): bool
    {
        $name = config('database.default');

        if (! is_string($name) || $name === '' || ! is_array(config("database.connections.{$name}"))) {
            return false;
        }

        try {
            DB::connection($name)->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** El idioma de este calendario (`->locale()`, `kore-calendar.locale` o el de la aplicación): `en` o `es`. */
    protected function koreLocale(): string
    {
        return Locale::resolve($this->koreSettings['locale'] ?? null);
    }

    /** Un texto de interfaz en el idioma de este calendario. Los motivos de rechazo y los toasts salen por aquí. */
    protected function koreText(string $key, array $replace = []): string
    {
        return Messages::get($key, $replace, $this->koreLocale());
    }

    /**
     * Rechazo: un toast con el motivo y la respuesta que hace que el cliente
     * deshaga su cambio optimista. No se vuelve a pintar (nada cambió), salvo si
     * el evento ya no existe: la vista está desfasada y el repintado la arregla.
     *
     * @return array{ok: false, message: string}
     */
    protected function koreReject(string $titleKey, string $reason, bool $stale = false): array
    {
        $this->toast()->error($this->koreText($titleKey), $reason)->send();

        if (! $stale) {
            $this->skipRender();
        }

        return ['ok' => false, 'message' => $reason];
    }

    /**
     * Soltar el evento donde estaba: no es un rechazo ni hay nada que guardar.
     *
     * @return array{ok: true, message: string}
     */
    private function koreUnchanged(): array
    {
        $this->skipRender();

        return ['ok' => true, 'message' => ''];
    }

    /**
     * El resultado de un movimiento aceptado. Si llegó tras el diálogo de alcance,
     * el gesto que lo empezó ya terminó (su respuesta fue `needsScope`) y nadie
     * lee este anuncio en el navegador: sale también en un toast.
     *
     * @return array{ok: true, message: string}
     */
    private function koreDone(string $announcement, ?string $scope): array
    {
        if ($scope !== null) {
            $this->toast()->success($announcement)->send();
        }

        return ['ok' => true, 'message' => $announcement];
    }

    /** El anuncio de aria-live: el título del evento y cuándo queda. Texto plano, no HTML. */
    private function koreAnnounce(string $key, CalendarEvent $event, Marker $when, bool $allDay, CalendarSettings $settings): string
    {
        return Messages::get($key, [
            'title' => $event->title,
            'date' => $when->toCarbon()->locale($settings->locale)->isoFormat($allDay ? 'LL' : 'LLL'),
        ], $settings->locale);
    }

    // ── Configuración fluida (para configure()) ─────────────────────────

    /** @param list<string> $views */
    protected function views(array $views): static
    {
        $this->koreViews = $views;

        return $this;
    }

    protected function defaultView(string $view): static
    {
        return $this->set('default_view', $view);
    }

    protected function timezone(string $timezone): static
    {
        return $this->set('timezone', $timezone);
    }

    protected function locale(string $locale): static
    {
        return $this->set('locale', $locale);
    }

    protected function firstDay(int $day): static
    {
        return $this->set('first_day', $day);
    }

    /** @param list<int> $days */
    protected function hiddenDays(array $days): static
    {
        return $this->set('hidden_days', $days);
    }

    /** @param 'iso'|'locale'|false $numbering */
    protected function weekNumbers(string|false $numbering = 'iso'): static
    {
        return $this->set('week_numbers', $numbering);
    }

    protected function slotDuration(string $duration): static
    {
        return $this->set('time.slot_duration', $duration);
    }

    protected function slotMinTime(string $time): static
    {
        return $this->set('time.slot_min_time', $time);
    }

    protected function slotMaxTime(string $time): static
    {
        return $this->set('time.slot_max_time', $time);
    }

    protected function maxEvents(?int $max): static
    {
        return $this->set('month.max_events', $max);
    }

    protected function nowIndicator(bool $show = true): static
    {
        return $this->set('now_indicator', $show);
    }

    protected function datesAboveResources(bool $above = true): static
    {
        return $this->set('time_grid.dates_above_resources', $above);
    }

    protected function eventOrder(string $order, bool $strict = false): static
    {
        return $this->set('event_order', $order)->set('event_order_strict', $strict);
    }

    // Interacción (config `interaction.*`)

    /** Se pueden mover y redimensionar los eventos (cada uno puede decidir lo suyo: `editable`, `startEditable`, `durationEditable`). */
    protected function editable(bool $editable = true): static
    {
        return $this->set('interaction.editable', $editable);
    }

    /** Se pueden seleccionar rangos de fechas arrastrando; llega a `onDateSelect()`. */
    protected function selectable(bool $selectable = true): static
    {
        return $this->set('interaction.selectable', $selectable);
    }

    /** Paso del gesto: `->snap('15m')`. Si no divide `slotDuration`, se usa el slot. */
    protected function snap(string $duration): static
    {
        return $this->set('interaction.snap', $duration);
    }

    /** `false`: ningún evento se puede solapar con otro (salvo los de su `groupId`). */
    protected function eventOverlap(bool $allowed = true): static
    {
        return $this->set('interaction.event_overlap', $allowed);
    }

    /** Fechas `YYYY-MM-DD` entre las que se puede editar; el `$end` incluye ese día. `null` deja el extremo abierto. */
    protected function validRange(?string $start, ?string $end): static
    {
        return $this->set('interaction.valid_range', ['start' => $start, 'end' => $end]);
    }

    /** Al pasar de día completo a con hora (o al revés), conservar la duración en vez de usar la de por defecto. */
    protected function allDayMaintainDuration(bool $maintain = true): static
    {
        return $this->set('interaction.all_day_maintain_duration', $maintain);
    }

    /** Se puede cambiar un evento de recurso arrastrándolo entre columnas o filas. */
    protected function resourceEditable(bool $editable = true): static
    {
        return $this->set('interaction.resource_editable', $editable);
    }

    // Horario laboral y conflictos (config `business_hours`, `interaction.*`, `highlight_conflicts`)

    /**
     * El horario laboral del calendario: las horas que no lo son se pintan con
     * `--kore-calendar-non-business`. `true` = lunes a viernes de 09:00 a 17:00; un tramo
     * (`['daysOfWeek' => [1, 2, 3], 'startTime' => '08:00', 'endTime' => '14:00']`) o una
     * lista de tramos; `false` = sin horario. El `businessHours` de un recurso gana al de aquí.
     *
     * @param  bool|array<int|string, mixed>  $hours
     */
    protected function businessHours(bool|array $hours = true): static
    {
        return $this->set('business_hours', $hours);
    }

    /**
     * `'businessHours'`: un evento movido o redimensionado tiene que quedar dentro del
     * horario laboral de su recurso destino (o del calendario). Lo decide el servidor; el
     * navegador solo marca la previsualización. `null` quita la regla.
     */
    protected function eventConstraint(?string $constraint = 'businessHours'): static
    {
        return $this->set('interaction.event_constraint', $constraint ?? false);
    }

    /** Lo mismo que `eventConstraint()`, para seleccionar un rango. */
    protected function selectConstraint(?string $constraint = 'businessHours'): static
    {
        return $this->set('interaction.select_constraint', $constraint ?? false);
    }

    /** `false`: no se puede seleccionar un rango que pisa un evento (salvo los de fondo sin `overlap: false`). */
    protected function selectOverlap(bool $allowed = true): static
    {
        return $this->set('interaction.select_overlap', $allowed);
    }

    /** Marcar (icono, borde y texto para lectores de pantalla) los eventos que se solapan en el mismo recurso. */
    protected function highlightConflicts(bool $highlight = true): static
    {
        return $this->set('highlight_conflicts', $highlight);
    }

    /** Cualquier clave del config, con puntos: `->set('time.event_min_minutes', 20)`. */
    protected function set(string $key, mixed $value): static
    {
        $this->koreSettings[$key] = $value;

        return $this;
    }

    // ── Render ──────────────────────────────────────────────────────────

    public function render()
    {
        return view('kore-calendar::livewire.calendar', [
            'koreCalendar' => $this->koreRendered(),
            'koreViews' => $this->koreAvailableViews(),
        ]);
    }

    /** @return list<string> */
    protected function koreAvailableViews(): array
    {
        return CalendarViews::available($this->koreViews ?? config('kore-calendar.views'), $this->koreResources());
    }

    protected function koreResources(): ResourceSet
    {
        return $this->koreResourceCache ??= new ResourceSet($this->resources());
    }

    protected function koreSettingsResolved(): CalendarSettings
    {
        // La vista de la URL (o de la última `changeView`), después `default_view` y, por
        // último, la primera que ofrezca. La misma cadena que <x-kore::calendar>.
        $view = CalendarViews::initial(
            [$this->view, $this->koreSettings['default_view'] ?? config('kore-calendar.default_view')],
            $this->koreAvailableViews(),
        );

        return CalendarSettings::make([
            ...$this->koreSettings,
            'view' => $view,
            'date' => $this->date !== '' ? $this->date : null,
            'timeline.row_start' => $this->timelineRow,
            // Lo que se pulsa solo se ofrece si alguien lo va a recibir: el formulario
            // o un `onEventClick()` propio. Un `->eventClick(false)` explícito gana.
            'interaction.event_click' => $this->koreSettings['interaction.event_click']
                ?? (config('kore-calendar.interaction.event_click') || $this->koreEventForm || $this->koreOverridesEventClick()),
        ]);
    }

    protected function koreRange(): ViewRange
    {
        $settings = $this->koreSettingsResolved();

        return ViewRange::for($settings->view, $settings->date, $settings->viewOptions);
    }

    protected function koreRendered(): View\RenderedCalendar
    {
        return CalendarRenderer::render(
            $this->koreSettingsResolved(),
            [new CallbackSource(fn (CarbonImmutable $from, CarbonImmutable $to) => $this->events($from, $to))],
            $this->koreResources(),
            id: $this->getId(),
        );
    }
}
