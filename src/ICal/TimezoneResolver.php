<?php

namespace KoreUi\Calendar\ICal;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * De un TZID a una zona de PHP.
 *
 * Un TZID de Google o Apple es un nombre IANA (`Europe/Madrid`) y PHP lo conoce. El
 * de Outlook y Exchange es un nombre de Windows («Romance Standard Time») que no es
 * de ninguna base de datos de zonas: lo único que dice qué significa es el VTIMEZONE
 * del mismo archivo. En orden:
 *
 * 1. Un nombre que PHP conoce, también con el prefijo global de RFC 5545 (`/mozilla.org/
 *    20050126_1/Europe/Madrid`) o como desplazamiento (`UTC+0200`, `GMT+01:00`).
 * 2. Un TZID con VTIMEZONE: se busca una zona de PHP con los mismos cambios de hora
 *    (los de los años del evento). Así «Romance Standard Time» sigue el cambio de hora
 *    y una serie semanal no se desplaza una hora en invierno. La zona elegida es una
 *    con las mismas reglas, no necesariamente la ciudad del usuario (Madrid, París y
 *    Berlín cambian a la vez); desempata primero la zona de la aplicación.
 * 3. Si el VTIMEZONE no tiene cambios, un desplazamiento fijo. Si los tiene y ninguna
 *    zona de PHP coincide, el desplazamiento que el VTIMEZONE da en la fecha de ese
 *    evento: es correcto ese día, pero una serie ya no sigue el cambio de hora.
 *
 * Los pasos 2 y 3 avisan (una vez por TZID): no son errores, pero el consumidor
 * debe saber que la zona no es la que decía el archivo.
 *
 * @internal
 */
final class TimezoneResolver
{
    /** Lo que `infer()` devuelve cuando el VTIMEZONE no tiene cambios de hora. */
    private const FIXED = '*';

    /** Zonas de referencia por si la de la aplicación no coincide: las que más se usan en español. */
    private const PREFERRED = [
        'Europe/Madrid', 'Europe/London', 'Europe/Paris', 'Europe/Berlin',
        'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
        'America/Mexico_City', 'America/Bogota', 'America/Argentina/Buenos_Aires',
        'America/Sao_Paulo', 'America/Santiago', 'Australia/Sydney', 'Asia/Tokyo',
    ];

    /** @var array<string, TimezoneDefinition> */
    private array $definitions = [];

    /** @var array<string, ?DateTimeZone> */
    private array $named = [];

    /** @var array<string, ?string> */
    private array $inferred = [];

    /** @var array<string, true> */
    private array $warned = [];

    /**
     * @param  Closure(string): void  $warn
     */
    public function __construct(private readonly Closure $warn) {}

    public function define(TimezoneDefinition $definition): void
    {
        $this->definitions[strtolower($definition->tzid)] = $definition;
    }

    /**
     * @param  DateTimeImmutable  $wall  La hora de pared a la que se refiere (campos en UTC): para elegir el desplazamiento si no hay zona equivalente.
     *
     * @throws ICalException
     */
    public function resolve(string $tzid, DateTimeImmutable $wall, int $line): DateTimeZone
    {
        $tzid = trim($tzid);

        if (($zone = $this->named($tzid)) !== null) {
            return $zone;
        }

        $definition = $this->definitions[strtolower($tzid)] ?? null;

        if ($definition === null) {
            throw new ICalException(
                "Línea {$line}: el TZID «{$tzid}» no es una zona horaria conocida y el archivo no trae su VTIMEZONE, así que no se sabe a qué hora corresponde.",
                $line,
            );
        }

        $year = (int) $wall->format('Y');
        $key = strtolower($tzid).'|'.$year;
        $this->inferred[$key] ??= $this->infer($definition, $year);

        if ($this->inferred[$key] === self::FIXED) {
            $offset = self::offsetName($definition->offsetAt(gmmktime(0, 0, 0, 7, 15, $year)));
            $this->warnOnce($tzid, "Línea {$line}: el TZID «{$tzid}» no es una zona IANA; su VTIMEZONE no tiene cambios de hora y se usó el desplazamiento fijo {$offset}.");

            return new DateTimeZone($offset);
        }

        if ($this->inferred[$key] !== '') {
            $this->warnOnce($tzid, "Línea {$line}: el TZID «{$tzid}» no es una zona IANA; se usó la definición de su VTIMEZONE, que coincide con «{$this->inferred[$key]}».");

            return new DateTimeZone($this->inferred[$key]);
        }

        // Sin zona equivalente: el desplazamiento de la fecha del evento. Dos pasadas porque el
        // desplazamiento depende del instante y el instante depende del desplazamiento.
        $guess = $wall->getTimestamp() - $definition->offsetAt($wall->getTimestamp());
        $offset = $definition->offsetAt($guess);

        $this->warnOnce($tzid, sprintf(
            'Línea %d: el TZID «%s» no es una zona IANA y ninguna coincide con su VTIMEZONE; se usa el desplazamiento %s en la fecha de cada evento (una serie no seguirá los cambios de hora).',
            $line,
            $tzid,
            self::offsetName($offset),
        ));

        return new DateTimeZone(self::offsetName($offset));
    }

    private function named(string $tzid): ?DateTimeZone
    {
        $key = strtolower($tzid);

        if (! array_key_exists($key, $this->named)) {
            $this->named[$key] = $this->lookup($tzid);
        }

        return $this->named[$key];
    }

    private function lookup(string $tzid): ?DateTimeZone
    {
        // El segundo es el nombre sin el prefijo de RFC 5545 §3.2.19 (`/dominio/versión/`).
        foreach ([$tzid, preg_replace('#^/[^/]+/[^/]+/#', '', $tzid)] as $candidate) {
            if ($candidate === '') {
                continue;
            }

            try {
                return new DateTimeZone($candidate);
            } catch (Exception) {
                // No es un nombre de PHP: se prueba el siguiente.
            }
        }

        if (preg_match('/^(?:UTC|GMT)?([+-])(\d{2}):?(\d{2})?$/i', $tzid, $m)) {
            return new DateTimeZone(sprintf('%s%s:%s', $m[1], $m[2], $m[3] ?? '00'));
        }

        return null;
    }

    /**
     * El nombre de la zona de PHP con los mismos cambios en los años del evento; `FIXED`
     * si el VTIMEZONE no tiene ninguno y `''` si ninguna zona coincide.
     */
    private function infer(TimezoneDefinition $definition, int $year): string
    {
        $onsets = $definition->onsetsBetween(gmmktime(0, 0, 0, 1, 1, $year - 1), gmmktime(0, 0, 0, 1, 1, $year + 2));

        // Los dos extremos del año más una hora a cada lado de cada cambio: ahí es
        // donde dos zonas "parecidas" se separan.
        $probes = [gmmktime(0, 0, 0, 1, 15, $year), gmmktime(0, 0, 0, 7, 15, $year)];
        foreach ($onsets as $onset) {
            $probes[] = $onset['ts'] - 3600;
            $probes[] = $onset['ts'] + 3600;
        }

        $expected = array_map(fn (int $ts) => $definition->offsetAt($ts), $probes);

        if (count(array_unique($expected)) === 1) {
            return self::FIXED;
        }

        foreach ($this->candidates($definition) as $name) {
            $zone = new DateTimeZone($name);

            foreach ($probes as $i => $ts) {
                if ($zone->getOffset(new DateTimeImmutable('@'.$ts)) !== $expected[$i]) {
                    continue 2;
                }
            }

            return $name;
        }

        return '';
    }

    /** @return list<string> */
    private function candidates(TimezoneDefinition $definition): array
    {
        $first = [];

        // X-LIC-LOCATION (Google, libical) dice la ciudad de verdad, cuando viene.
        if ($definition->location !== null) {
            $first[] = $definition->location;
        }

        $first[] = date_default_timezone_get();

        $valid = DateTimeZone::listIdentifiers();
        $known = array_flip($valid);

        return array_values(array_unique(array_filter(
            [...$first, ...self::PREFERRED, ...$valid],
            fn (string $name) => isset($known[$name]),
        )));
    }

    private function warnOnce(string $tzid, string $message): void
    {
        $key = strtolower($tzid);

        if (isset($this->warned[$key])) {
            return;
        }

        $this->warned[$key] = true;
        ($this->warn)($message);
    }

    private static function offsetName(int $seconds): string
    {
        $abs = abs($seconds);

        return sprintf('%s%02d:%02d', $seconds < 0 ? '-' : '+', intdiv($abs, 3600), intdiv($abs % 3600, 60));
    }
}
