<?php

namespace KoreUi\Calendar\Layout\Kernel;

/**
 * Un trozo de una fuente: la fuente entera o un fragmento que el kernel cortó
 * (`Slice` en kernel.ts). La fuente es dueña de la identidad y del orden; el
 * trozo solo lleva geometría lateral y si toca los bordes reales del evento.
 *
 * Inmutable. Un trozo recortado es un objeto nuevo (`narrower`).
 */
final class Slice
{
    public function __construct(
        public readonly Source $source,
        public readonly int|float $start,
        public readonly int|float $end,
        public readonly bool $isStart,
        public readonly bool $isEnd,
    ) {}

    /** El trozo que cubre la fuente entera (`createWholeSlice`). */
    public static function whole(Source $source): self
    {
        return new self($source, $source->start, $source->end, $source->isStart, $source->isEnd);
    }

    /**
     * Un recorte de este trozo (`createNarrowerSlice`). Solo conserva `isStart`
     * si el recorte sigue empezando donde empezaba el padre, y `isEnd` igual:
     * así la continuidad ("el evento sigue por la izquierda") sale de la
     * geometría real y no de un flag arrastrado.
     */
    public function narrower(int|float $start, int|float $end): self
    {
        return new self(
            $this->source,
            $start,
            $end,
            $this->isStart && $start == $this->start,
            $this->isEnd && $end == $this->end,
        );
    }

    /** Si es un fragmento y no la fuente entera (`isPartialSlice`). */
    public function isPartial(): bool
    {
        return $this->start != $this->source->start || $this->end != $this->source->end;
    }

    /**
     * Clave estable del trozo (`getSliceKey`): la de la fuente si es entero,
     * `{fuente}:{inicio}:slice` si es parcial.
     *
     * El FINAL se omite a propósito. Si un evento solo cambia de final entre dos
     * renders (otro evento ya no lo tapa), la clave sigue siendo la misma y el
     * morph de Livewire reutiliza el nodo en vez de desmontarlo y volverlo a
     * montar (wire:key estable, docs/calendar.md §7.6).
     */
    public function key(): string
    {
        if (! $this->isPartial()) {
            return $this->source->key;
        }

        return $this->source->key.':'.self::number($this->start).':slice';
    }

    /**
     * Un número como lo escribe JavaScript: `2`, no `2.0`. `(string) 2.0` ya da
     * "2" en PHP, pero depende de `precision`; esto no.
     */
    private static function number(int|float $n): string
    {
        if (is_int($n)) {
            return (string) $n;
        }
        if (is_finite($n) && floor($n) === $n && abs($n) < 1e15) {
            return (string) (int) $n;
        }

        return var_export($n, true);
    }
}
