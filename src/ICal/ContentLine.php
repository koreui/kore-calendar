<?php

namespace KoreUi\Calendar\ICal;

/**
 * Una línea de contenido ya desplegada y separada en nombre, parámetros y valor
 * (RFC 5545 §3.1): `DTSTART;TZID=Europe/Madrid:20261016T090000`.
 *
 * El valor se deja TAL CUAL, sin desescapar: cuál es su tipo (TEXT, lista de
 * TEXT, fecha, regla…) lo sabe quien lo lee, y desescapar antes de separar una
 * lista rompería sus elementos.
 *
 * @internal
 */
final class ContentLine
{
    /**
     * @param  array<string, list<string>>  $params  Nombre en mayúsculas; valores sin comillas.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $params,
        public readonly string $value,
        public readonly int $line,
    ) {}

    public function param(string $name): ?string
    {
        return $this->params[strtoupper($name)][0] ?? null;
    }

    /**
     * Los parámetros pueden llevar comillas con `:`, `;` y `,` dentro
     * (`CN="Ruiz, Marta":mailto:…`), así que no se parte por el primer `:`.
     *
     * @throws ICalException
     */
    public static function parse(string $raw, int $line): self
    {
        $error = null;

        return self::tryParse($raw, $line, $error) ?? throw new ICalException($error, $line);
    }

    /**
     * Lo mismo sin lanzar: `null` y el motivo en `$error`. El lector lo usa para las líneas
     * que no se pueden leer, que en un archivo hostil son cientos de miles, y una excepción
     * cada una cuesta su traza.
     */
    public static function tryParse(string $raw, int $line, ?string &$error = null): ?self
    {
        $length = strlen($raw);
        $i = strcspn($raw, ';:');

        $name = strtoupper(trim(substr($raw, 0, $i)));

        if ($i >= $length || $name === '' || ! preg_match('/^[A-Z0-9.-]+$/D', $name)) {
            $error = "Línea {$line}: no es una propiedad iCalendar válida (se espera NOMBRE:valor): «".self::preview($raw).'».';

            return null;
        }

        $params = [];

        while ($i < $length && $raw[$i] === ';') {
            $i++;
            $start = $i;
            $i += strcspn($raw, '=;:', $i);

            $paramName = strtoupper(trim(substr($raw, $start, $i - $start)));

            // Un parámetro sin `=` (vCard antiguo: `;PREF`) no aporta nada que usemos.
            if ($i >= $length || $raw[$i] !== '=') {
                continue;
            }

            $i++;
            $values = [];

            do {
                if ($i < $length && $raw[$i] === '"') {
                    $close = strpos($raw, '"', $i + 1);

                    if ($close === false) {
                        $error = "Línea {$line}: el parámetro ".self::preview($paramName).' abre comillas y no las cierra.';

                        return null;
                    }

                    $values[] = substr($raw, $i + 1, $close - $i - 1);
                    $i = $close + 1;
                } else {
                    $from = $i;
                    $i += strcspn($raw, ',;:', $i);

                    $values[] = substr($raw, $from, $i - $from);
                }

                $more = $i < $length && $raw[$i] === ',';
                $i += $more ? 1 : 0;
            } while ($more);

            $params[$paramName] = [...($params[$paramName] ?? []), ...$values];
        }

        if ($i >= $length || $raw[$i] !== ':') {
            $error = "Línea {$line}: la propiedad {$name} no tiene «:» antes del valor.";

            return null;
        }

        return new self($name, $params, substr($raw, $i + 1), $line);
    }

    private static function preview(string $raw): string
    {
        return mb_strlen($raw) > 60 ? mb_substr($raw, 0, 57).'…' : $raw;
    }
}
