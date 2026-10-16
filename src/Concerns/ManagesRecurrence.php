<?php

namespace KoreUi\Calendar\Concerns;

use KoreUi\Calendar\Events\EventInstance;
use KoreUi\Calendar\Interaction\Messages;
use KoreUi\Calendar\Overlays\RecurrenceScope;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * Editar una serie recurrente por alcance (fase 8, docs/calendar.md §7.7).
 *
 * Mover, redimensionar, editar o eliminar una ocurrencia no se puede guardar sin
 * saber a qué alcanza: solo esa, esa y las siguientes, o toda la serie. La primera
 * petición llega sin alcance, así que NO persiste nada: valida, guarda la acción
 * en una propiedad `#[Locked]` y abre el diálogo (`RecurrenceScope`). Cuando el
 * usuario elige, `applyRecurrenceScope()` repite la acción ENTERA por el mismo
 * camino de siempre (docs/calendar.md §11): vuelve a buscar la instancia entre las
 * de la vista, que pudo cambiar mientras el diálogo estaba abierto, vuelve a
 * ajustar y validar el destino y solo entonces llama al hook del consumidor.
 *
 * Lo pendiente guarda los argumentos que el navegador mandó (ya validados una vez),
 * no la mutación: una mutación lleva instantes y objetos que Livewire no
 * serializa bien, y lo que valía hace un minuto puede no valer ahora.
 *
 * Usa piezas de `KoreCalendar` (`koreMove`, `koreResize`) y de `ManagesEventForm`
 * (`koreSaveEvent`).
 */
trait ManagesRecurrence
{
    /**
     * La acción que espera un alcance, o `null`: `action` (`move`, `resize`, `edit`,
     * `delete`), `instanceId` y `arguments`. Es estado del servidor y `#[Locked]`:
     * el navegador no puede escribir ahí una acción que no pasó por una petición
     * aceptada, solo elegir el alcance de la que hay.
     *
     * @var array{action: string, instanceId: string, arguments: array<string, mixed>}|null
     */
    #[Locked]
    public ?array $koreRecurrencePending = null;

    /**
     * Deja la acción pendiente y abre el diálogo. No se repinta: nada cambió.
     *
     * @param  'move'|'resize'|'edit'|'delete'  $action
     * @param  array<string, mixed>  $arguments  Escalares y listas: Livewire los lleva de una petición a otra.
     * @return array{ok: false, needsScope: true, message: string}
     */
    protected function koreAskScope(string $action, EventInstance $instance, array $arguments = []): array
    {
        $this->koreRecurrencePending = [
            'action' => $action,
            'instanceId' => $instance->instanceId,
            'arguments' => $arguments,
        ];

        $this->dispatch('kore:open',
            name: 'kore-calendar-recurrence-scope',
            arguments: ['calendar' => $this->getId(), 'action' => $action, 'title' => $instance->event->title],
            // El nombre accesible del diálogo (`role="dialog"`): lo sabe quien lo abre.
            overlayAttributes: ['title' => Messages::get(RecurrenceScope::TITLES[$action])],
        );

        $this->skipRender();

        // `message` vacío: el cliente no tiene nada que anunciar. Solo limpia la previsualización.
        return ['ok' => false, 'needsScope' => true, 'message' => ''];
    }

    /**
     * Repite la acción pendiente con el alcance elegido.
     *
     * Público porque lo llama el diálogo (por `koreRecurrenceScopeChosen`) y, por serlo,
     * también el navegador: de ahí que el alcance se valide y que lo único que se
     * pueda hacer sea elegir uno de los tres para la acción que el servidor ya aceptó.
     * Sin acción pendiente, o con un alcance que no existe, no hace nada.
     *
     * La pendiente se consume ANTES de intentarlo (como `undoLastChange()`): un
     * rechazo no deja reintentar con otro alcance, el usuario ve el motivo y repite el gesto.
     */
    public function applyRecurrenceScope(string $scope): void
    {
        $pending = $this->koreRecurrencePending;

        if (! in_array($scope, RecurrenceScope::SCOPES, true) || ! is_array($pending)) {
            $this->skipRender();

            return;
        }

        $this->koreRecurrencePending = null;

        $id = $pending['instanceId'] ?? null;
        $args = $pending['arguments'] ?? null;

        if (! is_string($id) || ! is_array($args)) {
            $this->skipRender();

            return;
        }

        match ($pending['action'] ?? null) {
            'move' => $this->koreMove($id, (int) ($args['start'] ?? 0), (bool) ($args['allDay'] ?? false), $args['resourceId'] ?? null, $args['fromResourceId'] ?? null, $scope),
            'resize' => $this->koreResize($id, (int) ($args['edge'] ?? 0), (bool) ($args['fromStart'] ?? false), $scope),
            'edit' => $this->koreSaveEvent('edit', [...(array) ($args['data'] ?? []), 'mode' => 'edit', 'instanceId' => $id], $scope),
            'delete' => $this->koreSaveEvent('delete', ['mode' => 'delete', 'instanceId' => $id], $scope),
            default => $this->skipRender(),
        };
    }

    /** Se canceló el diálogo: la acción pendiente se descarta. */
    public function cancelRecurrenceScope(): void
    {
        $this->koreRecurrencePending = null;
        $this->skipRender();
    }

    /**
     * La elección del diálogo. El aviso es global, así que cada calendario descarta
     * los que llevan el id de otro (hay páginas con dos).
     */
    #[On(RecurrenceScope::CHOSEN)]
    public function koreRecurrenceScopeChosen(string $calendar, string $scope = ''): void
    {
        if ($calendar !== $this->getId()) {
            $this->skipRender();

            return;
        }

        $this->applyRecurrenceScope($scope);
    }

    #[On(RecurrenceScope::CANCELLED)]
    public function koreRecurrenceScopeCancelled(string $calendar): void
    {
        if ($calendar !== $this->getId()) {
            $this->skipRender();

            return;
        }

        $this->cancelRecurrenceScope();
    }
}
