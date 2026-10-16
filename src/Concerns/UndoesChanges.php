<?php

namespace KoreUi\Calendar\Concerns;

use KoreUi\Calendar\Interaction\EventMutation;
use KoreUi\Calendar\Interaction\Events\EventMoved;
use KoreUi\Calendar\Interaction\Events\EventResized;
use KoreUi\Calendar\Interaction\Messages;
use KoreUi\Calendar\Interaction\MutationValidator;
use KoreUi\Calendar\Interaction\UndoSnapshot;
use Livewire\Attributes\Locked;

/**
 * Deshacer el último movimiento o cambio de duración (fase 7).
 *
 * Es opt-in (`->undoable()`): un toast tras cada arrastre llenaría la pantalla, y
 * quien quiera que un calendario sea más cómodo de equivocarse en él lo activa.
 *
 * Deshacer es otro cambio más y pasa por el mismo camino que cualquiera
 * (docs/calendar.md §11): el evento se vuelve a buscar entre los de la vista, la
 * mutación inversa se valida (editable, rango, solapes: puede que otro evento haya
 * ocupado el sitio mientras tanto) y se persiste con el MISMO hook, `persistMove()`
 * o `persistResize()`. El consumidor no tiene nada nuevo que implementar.
 */
trait UndoesChanges
{
    /**
     * Cómo estaba el último evento movido o redimensionado, o `null`. Es estado del
     * servidor (Livewire lo lleva de una petición a otra) y `#[Locked]`: el navegador
     * no puede escribir ahí un estado que no ha pasado por una acción aceptada.
     *
     * @var array<string, mixed>|null
     */
    #[Locked]
    public ?array $koreLastChange = null;

    protected bool $koreUndoable = false;

    /**
     * Tras mover o redimensionar un evento, un toast breve con la acción «Deshacer».
     * Solo se puede deshacer el último cambio, y una vez.
     */
    protected function undoable(bool $enabled = true): static
    {
        $this->koreUndoable = $enabled;

        return $this;
    }

    /**
     * Guarda el estado anterior y ofrece deshacerlo. Se llama con el cambio ya
     * aceptado y persistido. `$message` es el mismo texto del anuncio de aria-live.
     */
    protected function koreOfferUndo(string $type, EventMutation $mutation, string $message): void
    {
        if (! $this->koreUndoable) {
            return;
        }

        $this->koreLastChange = UndoSnapshot::before($type, $mutation)->toArray();

        $this->toast()->info($message)->action(Messages::get('undo'), 'undoLastChange')->send();
    }

    /**
     * Devuelve el último evento movido o redimensionado a como estaba.
     *
     * Público porque lo llama el toast, y por eso lo único que hace falta para
     * llegar aquí es que haya un cambio guardado por una acción aceptada: el estado
     * es `#[Locked]`. Se consume ANTES de intentarlo, así que un rechazo (otro evento
     * ocupó el sitio) tampoco deja reintentar: el toast ya no está y el usuario ve
     * el motivo.
     */
    public function undoLastChange(): void
    {
        $snapshot = UndoSnapshot::fromArray($this->koreLastChange);
        $this->koreLastChange = null;

        // Ya se deshizo, o nunca hubo nada: no hay nada que repintar.
        if ($snapshot === null) {
            $this->skipRender();

            return;
        }

        $settings = $this->koreSettingsResolved();
        $instances = $this->koreInstances($settings);
        $current = self::koreFind($instances, $snapshot->id);

        if ($current === null) {
            // Cambió de semana o se borró: el evento ya no está en la vista.
            $this->koreReject('undo_failed', Messages::get('event_not_found'), stale: true);

            return;
        }

        $mutation = $snapshot->inverse($current, $settings);

        // Alguien lo dejó como estaba (otro arrastre, otra pestaña): nada que guardar.
        if ($mutation->isNoop()) {
            $this->skipRender();

            return;
        }

        $resize = $snapshot->type === 'resize';
        $validator = new MutationValidator($settings);

        $refusal = ($resize ? $validator->validateResize($mutation, $instances) : $validator->validateMove($mutation, $instances))
            ?? $this->korePersist(fn () => $resize ? $this->persistResize($current->event, $mutation) : $this->persistMove($current->event, $mutation));

        if ($refusal !== null) {
            $this->koreReject('undo_failed', $refusal);

            return;
        }

        // Es un cambio como los demás para quien audita: lo que se movió, se movió.
        event($resize
            ? new EventResized(static::class, $current->event, $mutation)
            : new EventMoved(static::class, $current->event, $mutation));

        $this->toast()->success(Messages::get('undo_done'))->send();
    }
}
