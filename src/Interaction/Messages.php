<?php

namespace KoreUi\Calendar\Interaction;

use KoreUi\Calendar\Support\Locale;

/**
 * Los textos de interfaz del calendario: el único sitio donde se leen.
 *
 * Salen de `lang/{idioma}/calendar.php` (namespace `kore-calendar`, así que la
 * aplicación puede sobrescribirlos en `lang/vendor/kore-calendar/`). Encima de eso,
 * `kore-calendar.translations` es solo de overrides: una clave suya gana a la del
 * archivo de idioma, para todos los idiomas (`'today' => 'Ahora'`) o solo para uno
 * (`'es' => ['today' => 'Ahora']`, que gana a la anterior).
 *
 * `RenderedCalendar::text()` hace lo mismo para las vistas, con el idioma de su
 * calendario; los motivos de rechazo se necesitan antes, en una acción de Livewire,
 * donde no hay ninguno, y piden el idioma del calendario que los pide.
 */
final class Messages
{
    /**
     * @param  array<string, string|int>  $replace
     * @param  ?string  $locale  Idioma del calendario. `null`: el de `Locale::resolve()` sin más.
     */
    public static function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $text = self::line($key, Locale::resolve($locale));

        // strtr y no str_replace en cadena: el título de un evento puede contener
        // «:date», y no debe sustituirse dentro del texto que acabamos de insertar.
        return $replace === [] ? $text : strtr($text, array_combine(
            array_map(fn (string $name) => ':'.$name, array_keys($replace)),
            array_map('strval', array_values($replace)),
        ));
    }

    /** La plantilla sin sustituir. Una clave que no existe en ningún sitio sale con su nombre. */
    private static function line(string $key, string $locale): string
    {
        $overrides = config('kore-calendar.translations');

        if (is_array($overrides)) {
            $ofLocale = is_array($overrides[$locale] ?? null) ? $overrides[$locale] : [];

            foreach ([$ofLocale[$key] ?? null, $overrides[$key] ?? null] as $override) {
                if (is_string($override)) {
                    return $override;
                }
            }
        }

        $id = 'kore-calendar::calendar.'.$key;
        // Sin el idioma de reserva de la aplicación: si falta aquí, falta, y se ve.
        $line = app('translator')->get($id, [], $locale, false);

        return is_string($line) && $line !== $id ? $line : $key;
    }
}
