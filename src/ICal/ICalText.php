<?php

namespace KoreUi\Calendar\ICal;

use KoreUi\Calendar\Interaction\Messages;

/**
 * Los mensajes del lector iCalendar: errores (`ICalException`, `errors()`) y avisos (`warnings()`).
 *
 * Estaban escritos en español dentro del código, así que una aplicación en inglés enseñaba
 * «Línea 12: el VEVENT no tiene DTSTART» al lado de un calendario en inglés. Ahora son las
 * claves `ical_*` de `lang/{idioma}/calendar.php` y salen en el idioma del calendario
 * (`Locale::resolve()`): se sobrescriben como cualquier otro texto, en
 * `lang/vendor/kore-calendar/` o en `kore-calendar.translations`.
 *
 * Los nombres de iCalendar (VEVENT, DTSTART, RRULE, TZID…) no se traducen: son los que
 * el usuario va a buscar en su archivo.
 */
final class ICalText
{
    /** @param  array<string, string|int>  $replace */
    public static function of(string $key, array $replace = []): string
    {
        return Messages::get('ical_'.$key, $replace);
    }
}
