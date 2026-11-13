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
    /**
     * Las plantillas ya resueltas durante una lectura, o `null` fuera de una.
     *
     * Resolver una plantilla pasa por el traductor de Laravel y por la configuración, y un
     * archivo hostil puede traer cientos de miles de líneas malas: sin esto, leerlo costaba
     * varios segundos más que cuando los textos eran literales. Vive solo lo que dura
     * `during()`, así que un cambio de idioma o de `kore-calendar.translations` entre dos
     * lecturas se ve en la siguiente.
     *
     * @var ?array<string, string>
     */
    private static ?array $templates = null;

    /**
     * Ejecuta una lectura con las plantillas memorizadas.
     *
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    public static function during(callable $read): mixed
    {
        if (self::$templates !== null) {
            return $read();   // Una lectura dentro de otra comparte la memoria de la de fuera.
        }

        self::$templates = [];

        try {
            return $read();
        } finally {
            self::$templates = null;
        }
    }

    /** @param  array<string, string|int>  $replace */
    public static function of(string $key, array $replace = []): string
    {
        if (self::$templates === null) {
            return Messages::get('ical_'.$key, $replace);
        }

        $text = self::$templates[$key] ??= Messages::get('ical_'.$key);

        if ($replace === []) {
            return $text;
        }

        // La misma sustitución que `Messages::get()`: strtr, para que un valor que contenga
        // «:line» no se sustituya dentro del texto recién insertado.
        $pairs = [];
        foreach ($replace as $name => $value) {
            $pairs[':'.$name] = (string) $value;
        }

        return strtr($text, $pairs);
    }
}
