<?php

namespace KoreUi\Calendar\Support;

/**
 * El idioma con el que habla el calendario: textos y fechas, el mismo para los dos.
 *
 * Orden: el que pide el calendario (`->locale('en')`, la prop `locale`), después
 * `kore-calendar.locale` y, si tampoco está, el de la aplicación. De lo que salga
 * solo cuenta el idioma base (`es_MX` y `es-AR` son `es`; `en_GB`, `en`). Si ese
 * idioma no tiene textos en `lang/` se usa `kore-calendar.fallback_locale`.
 *
 * Que sea UNO solo es lo que evita una pantalla con la fecha en inglés y los
 * botones en español: `CalendarSettings::$locale`, `Messages` y el formulario de
 * evento resuelven siempre por aquí.
 */
final class Locale
{
    /** Los idiomas con textos en el paquete (`lang/{idioma}/calendar.php`). */
    public const SUPPORTED = ['en', 'es'];

    /**
     * El idioma pedido tal cual (`es_MX`, `en-GB`), sin reducirlo al base.
     *
     * Para lo que sí sabe de regiones y no tiene textos propios: el primer día de la
     * semana y la numeración (`WeekRules`) y el orden alfabético (`Collator`).
     */
    public static function requested(?string $override = null): string
    {
        foreach ([$override, config('kore-calendar.locale'), app()->getLocale()] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return 'en';
    }

    /** El idioma de los textos y las fechas: uno de `SUPPORTED`. */
    public static function resolve(?string $override = null): string
    {
        $base = self::base(self::requested($override));

        if (in_array($base, self::SUPPORTED, true)) {
            return $base;
        }

        $fallback = self::base((string) config('kore-calendar.fallback_locale', 'en'));

        return in_array($fallback, self::SUPPORTED, true) ? $fallback : 'en';
    }

    /** `es_MX`, `es-AR`, `ES` → `es`. */
    private static function base(string $locale): string
    {
        return strtolower(preg_split('/[-_.@]/', trim($locale), 2)[0]);
    }
}
