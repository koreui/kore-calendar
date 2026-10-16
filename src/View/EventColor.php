<?php

namespace KoreUi\Calendar\View;

/**
 * El color de un evento o recurso → el valor de la custom property --kore-event.
 *
 * Un nombre de token de kore-ui (`primary`, `success`, `chart-3`) se resuelve a su
 * variable, para que cambie con el tema. Cualquier otra cosa se acepta solo si
 * parece un color CSS: el valor acaba dentro de un atributo `style`, y un
 * `red; background: url(…)` no puede llegar ahí.
 */
final class EventColor
{
    private const TOKENS = ['primary', 'secondary', 'accent', 'success', 'warning', 'destructive', 'info', 'muted'];

    public static function css(?string $color): ?string
    {
        if ($color === null || $color === '') {
            return null;
        }

        $color = trim($color);

        if (in_array($color, self::TOKENS, true) || preg_match('/^chart-[1-8]$/', $color)) {
            return "var(--kore-{$color})";
        }

        if (preg_match('/^(#[0-9a-f]{3,8}|(rgb|rgba|hsl|hsla|oklch|oklab|lab|lch)\([0-9a-z.,%\s\/+-]+\)|[a-z]+)$/i', $color)) {
            return $color;
        }

        return null;
    }

    /** El atributo style listo para pegar, o '' si no hay color. */
    public static function style(?string $color): string
    {
        $css = self::css($color);

        return $css === null ? '' : "--kore-event: {$css};";
    }
}
