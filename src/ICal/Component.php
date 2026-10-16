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
     * @var list<ICalException>
     */
    public array $problems = [];

    public function __construct(public readonly string $name, public readonly int $line) {}

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
