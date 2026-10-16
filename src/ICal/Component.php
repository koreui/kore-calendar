<?php

namespace KoreUi\Calendar\ICal;

/**
 * Un componente del árbol iCalendar (`BEGIN:VEVENT … END:VEVENT`): sus
 * propiedades y los componentes que contiene (el VALARM de un VEVENT, el STANDARD
 * de un VTIMEZONE).
 *
 * @internal
 */
final class Component
{
    /** @var list<ContentLine> */
    public array $lines = [];

    /** @var list<Component> */
    public array $children = [];

    /**
     * Lo que fue mal al leerlo: líneas sin sentido o un `END` que no llegó. Se
     * guarda en vez de lanzarse porque en modo no estricto un VEVENT roto se salta y
     * el resto del archivo se lee.
     *
     * Son datos (línea y mensaje) y no excepciones: una `ICalException` lleva su traza, y
     * 300 000 líneas malas eran 800 MB. Y no se guardan todos, ver `problem()`.
     *
     * @var list<array{line: int, message: string}>
     */
    public array $problems = [];

    /** Cuántos problemas se descartaron por no caber. */
    public int $dropped = 0;

    public function __construct(public readonly string $name, public readonly int $line) {}

    /**
     * Anota un problema si caben `$max`; si no, solo lo cuenta. De un VEVENT solo se usa el
     * primero (con él se salta); de un VCALENDAR, cada uno es un aviso.
     */
    public function problem(string $message, int $line, int $max): void
    {
        if (count($this->problems) < $max) {
            $this->problems[] = ['line' => $line, 'message' => $message];

            return;
        }

        $this->dropped++;
    }

    /** El problema de que no se cierre, que va el primero: es el que explica los demás. */
    public function unclosed(string $message, int $line): void
    {
        array_unshift($this->problems, ['line' => $line, 'message' => $message]);
    }

    public function first(string $name): ?ContentLine
    {
        foreach ($this->lines as $line) {
            if ($line->name === $name) {
                return $line;
            }
        }

        return null;
    }

    /** @return list<ContentLine> */
    public function all(string $name): array
    {
        return array_values(array_filter($this->lines, fn (ContentLine $l) => $l->name === $name));
    }
}
