<?php

namespace KoreUi\Calendar\ICal;

/**
 * Las tres operaciones de bajo nivel de RFC 5545 §3.1 y §3.3.11: escapar el
 * tipo TEXT, plegar líneas largas y desplegarlas.
 *
 * Todo trabaja sobre BYTES, no sobre caracteres. El plegado de RFC 5545 se mide
 * en octetos, y un emoji partido entre dos líneas solo vuelve a ser un emoji
 * cuando se juntan los bytes: decodificar antes de desplegar lo rompería.
 *
 * @internal
 */
final class Text
{
    /** Octetos por línea sin contar el CRLF (RFC 5545 §3.1). */
    public const FOLD_AT = 75;

    /**
     * Escapa un valor de tipo TEXT. Los saltos de línea (CRLF, LF o CR) pasan a `\n`:
     * un salto sin escapar acabaría una propiedad y empezaría otra, y con ella
     * un título hostil podría inyectar un ATTENDEE o un VALARM.
     */
    public static function escape(string $value): string
    {
        return strtr($value, [
            '\\' => '\\\\',
            ';' => '\;',
            ',' => '\\,',
            "\r\n" => '\\n',
            "\n" => '\\n',
            "\r" => '\\n',
        ]);
    }

    /** Lo contrario de `escape()`. Un `\x` que no conoce se queda en `x`: mejor perder la barra que el texto. */
    public static function unescape(string $value): string
    {
        return preg_replace_callback(
            '/\\\\(.)/s',
            fn (array $m) => $m[1] === 'n' || $m[1] === 'N' ? "\n" : $m[1],
            $value,
        );
    }

    /**
     * Separa una lista de TEXT (`CATEGORIES`, `X-KORE-RESOURCES`) por las comas
     * SIN escapar y desescapa cada elemento. Se separa antes de desescapar: si no,
     * la coma de `a\,b` partiría el elemento.
     *
     * @return list<string>
     */
    public static function splitList(string $value): array
    {
        $items = [];
        $current = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $current .= $char.$value[++$i];

                continue;
            }

            if ($char === ',') {
                $items[] = self::unescape($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $items[] = self::unescape($current);

        return $items;
    }

    /**
     * Un valor que no es TEXT (URL, RRULE, TZID) sin caracteres de control. No se
     * escapa porque su sintaxis no lo admite, pero tampoco puede llevar un salto
     * de línea: es la misma inyección que en TEXT.
     */
    public static function clean(string $value): string
    {
        return preg_replace('/[\x00-\x1F\x7F]+/', '', $value);
    }

    /** Un valor de parámetro. Las comillas lo protegen si lleva `:`, `;` o `,`; el propio `"` no cabe en ninguna sintaxis. */
    public static function param(string $value): string
    {
        $value = str_replace('"', '', self::clean($value));

        return preg_match('/[:;,]/', $value) ? '"'.$value.'"' : $value;
    }

    /**
     * Una línea de contenido plegada a 75 octetos y con su CRLF. Las
     * continuaciones empiezan con un espacio que también cuenta, así que llevan 74
     * octetos de contenido.
     *
     * El corte nunca cae dentro de un carácter UTF-8: si el byte donde se cortaría
     * es de continuación (`10xxxxxx`), se retrocede hasta el inicio del carácter.
     */
    public static function fold(string $line): string
    {
        $length = strlen($line);

        if ($length <= self::FOLD_AT) {
            return $line."\r\n";
        }

        $out = '';
        $pos = 0;
        $room = self::FOLD_AT;

        while ($pos < $length) {
            $take = min($room, $length - $pos);

            if ($pos + $take < $length) {
                while ($take > 0 && (ord($line[$pos + $take]) & 0xC0) === 0x80) {
                    $take--;
                }

                // Bytes que no son UTF-8 válido y ningún punto de corte: se corta donde toca
                // antes que quedarse sin avanzar.
                if ($take === 0) {
                    $take = min($room, $length - $pos);
                }
            }

            $out .= ($pos === 0 ? '' : ' ').substr($line, $pos, $take)."\r\n";
            $pos += $take;
            $room = self::FOLD_AT - 1;
        }

        return $out;
    }

    /**
     * Despliega el archivo: una línea física que empieza por espacio o tabulador
     * continúa la anterior (RFC 5545 §3.1). Acepta CRLF, LF y CR sueltos, porque
     * hay exportadores que no respetan el CRLF.
     *
     * Devuelve cada línea lógica con el número de su PRIMERA línea física: es el
     * que hay que dar en un error para que el usuario la encuentre en su editor.
     *
     * @return list<array{0: int, 1: string}>
     */
    public static function unfold(string $ics): array
    {
        return iterator_to_array(self::lines($ics), false);
    }

    /**
     * Lo mismo que `unfold()`, de una en una. Es lo que usa el lector: un archivo de 5 MB de
     * saltos de línea son cinco millones de líneas físicas, y `preg_split()` más un array por
     * cada una pasan de 300 MB. Aquí solo hay una línea lógica en memoria a la vez.
     *
     * @return \Generator<int, array{0: int, 1: string}>
     */
    public static function lines(string $ics): \Generator
    {
        $length = strlen($ics);
        $position = 0;
        $number = 0;
        $current = null;

        while (true) {
            $end = $position + strcspn($ics, "\r\n", $position);
            $physical = substr($ics, $position, $end - $position);
            $number++;

            if ($current !== null && $physical !== '' && ($physical[0] === ' ' || $physical[0] === "\t")) {
                $current[1] .= substr($physical, 1);
            } else {
                if ($current !== null) {
                    yield $current;
                }

                $current = [$number, $physical];
            }

            if ($end >= $length) {
                break;
            }

            $position = $end + ($ics[$end] === "\r" && ($ics[$end + 1] ?? '') === "\n" ? 2 : 1);
        }

        yield $current;
    }
}
