<?php

namespace KoreUi\Calendar\Overlays;

use KoreUi\Calendar\Interaction\Messages;
use KoreUi\Overlay\OverlayComponent;
use Livewire\Attributes\Locked;

/**
 * El diálogo que pregunta a qué alcanza el cambio de una ocurrencia: solo esa,
 * esa y las siguientes, o la serie entera (docs/calendar.md §7.7).
 *
 * No cambia nada. Devuelve la elección al calendario que lo abrió con un evento
 * de Livewire y es el calendario quien repite la acción pendiente, volviéndola a
 * validar: igual que `EventForm`, el aviso es global (Livewire no dirige un
 * evento a UNA instancia) y lleva el id del calendario destino, y cada
 * calendario descarta los que no son suyos.
 *
 * Cierra TODA la pila (`closeAll()`) al elegir y solo ESTE diálogo al cancelar.
 * Al editar o eliminar desde el formulario, el diálogo se apila encima del
 * formulario, que sigue abierto debajo: cancelar vuelve a él y no se pierde lo
 * escrito. El formulario no se cierra antes porque abrir un overlay mientras otro
 * acaba su transición de cierre (300 ms) deja al segundo sin pantalla: el
 * gestor resetea su estado al terminar de cerrar el primero.
 */
class RecurrenceScope extends OverlayComponent
{
    /** La elección: `calendar` (a quién) y `scope`. */
    public const CHOSEN = 'kore-calendar:recurrence-scope-chosen';

    /** Se canceló: el calendario descarta la acción pendiente. */
    public const CANCELLED = 'kore-calendar:recurrence-scope-cancelled';

    /** @var list<string> */
    public const SCOPES = ['this', 'following', 'all'];

    /**
     * Acción → clave de traducción de su título. Cada clave va escrita entera, no
     * montada con un prefijo: así el test de claves sin usar las ve.
     */
    public const TITLES = [
        'move' => 'scope_move',
        'resize' => 'scope_resize',
        'edit' => 'scope_edit',
        'delete' => 'scope_delete',
    ];

    // Lo que decide el calendario y el navegador no puede tocar.
    #[Locked]
    public string $calendar = '';

    #[Locked]
    public string $action = 'move';

    /** El título del evento, para nombrarlo en la pregunta. */
    #[Locked]
    public string $eventTitle = '';

    public function mount(string $calendar, string $action = 'move', string $title = ''): void
    {
        $this->calendar = $calendar;
        // Lo que no se conoce es mover: el texto genérico menos engañoso.
        $this->action = isset(self::TITLES[$action]) ? $action : 'move';
        $this->eventTitle = $title;
    }

    // ── Overlay ─────────────────────────────────────────────────────────

    public static function overlayType(): string
    {
        return 'modal';
    }

    public static function overlaySize(): string
    {
        return 'md';
    }

    // Es una pregunta con tres respuestas: un clic fuera no es ninguna. Escape sí cancela,
    // y solo este diálogo (no la pila): el formulario de debajo, si lo hay, sigue ahí.
    public static function closesOnClickAway(): bool
    {
        return false;
    }

    public static function escapeClosesAll(): bool
    {
        return false;
    }

    public static function destroysOnClose(): bool
    {
        return true;
    }

    // ── Acciones ────────────────────────────────────────────────────────

    /**
     * Público porque Livewire solo despacha a métodos públicos, lo que lo hace
     * llamable desde la consola del navegador: por eso el alcance se vuelve a
     * comprobar aquí y otra vez en el calendario.
     */
    public function choose(string $scope): void
    {
        if (! in_array($scope, self::SCOPES, true)) {
            return;
        }

        // `closeAll()` y no `closeWith()`: ver la nota de la clase.
        $this->dispatch(self::CHOSEN, calendar: $this->calendar, scope: $scope);
        $this->closeAll();
    }

    public function cancel(): void
    {
        $this->dispatch(self::CANCELLED, calendar: $this->calendar);
        $this->close();
    }

    // ── Render ──────────────────────────────────────────────────────────

    public function render()
    {
        return view('kore-calendar::livewire.recurrence-scope', [
            'koreHeading' => Messages::get(self::TITLES[$this->action]),
        ]);
    }
}
