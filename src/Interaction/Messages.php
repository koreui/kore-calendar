<?php

namespace KoreUi\Calendar\Interaction;

/**
 * Los textos de interacción, de `kore-calendar.translations`.
 *
 * `RenderedCalendar::text()` hace lo mismo para las vistas, pero cuelga de un
 * calendario ya renderizado; los motivos de rechazo se necesitan antes, en una
 * acción de Livewire, donde no hay ninguno.
 */
final class Messages
{
    /** @param array<string, string|int> $replace */
    public static function get(string $key, array $replace = []): string
    {
        $text = (string) config("kore-calendar.translations.{$key}", $key);

        // strtr y no str_replace en cadena: el título de un evento puede contener
        // «:date», y no debe sustituirse dentro del texto que acabamos de insertar.
        return $replace === [] ? $text : strtr($text, array_combine(
            array_map(fn (string $name) => ':'.$name, array_keys($replace)),
            array_map('strval', array_values($replace)),
        ));
    }
}
