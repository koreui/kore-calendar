<?php

namespace KoreUi\Calendar\ICal;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventSource;

/**
 * Los eventos de un `.ics` (su contenido o una ruta) como fuente del calendario:
 *
 *     ->events(fn () => [ICalSource::fromFile(storage_path('app/festivos.ics'))])
 *
 * Lee y analiza el archivo una vez por instancia, la primera vez que se pide. Un archivo
 * grande que cambia debe reabrirse en cada petición (una instancia nueva); el
 * análisis no se cachea entre peticiones.
 *
 * Devuelve lo que puede tocar el rango y deja al colector lo demás:
 *
 * - **Las series siempre**, salvo las que empiezan después del rango: la expansión la
 *   hace `EventCollector`, que es quien sabe en qué zona se dibuja.
 * - **Las excepciones siempre.** Una excepción movida fuera del rango tiene que llegar de
 *   todos modos: es lo que le dice a la serie que NO genere esa ocurrencia.
 * - **El resto, si cruza el rango**, con un día de margen por los lados (los eventos con
 *   hora de pared o de día completo se comparan en la zona de la vista, que es la de `$from`).
 *   Un evento sin fin cuenta si empieza como mucho un día antes: el mismo criterio que
 *   `EloquentSource`, porque la duración por defecto la decide el colector.
 *
 * Los VEVENT que no se pudieron leer se quedan en `errors()`, y las salvedades en
 * `warnings()` (ver `ICalReader`).
 */
final class ICalSource implements EventSource
{
    private const MARGIN = 86_400;

    private ?string $content = null;

    /** @var ?list<CalendarEvent> */
    private ?array $events = null;

    private ?ICalReader $reader = null;

    /**
     * Dos constructores con nombre y no uno que adivine: un constructor que leyera
     * una ruta cuando el texto "lo parece" haría de `ICalSource($request->input('ics'))`
     * un lector de archivos del servidor (`/ruta/.env`), y sus líneas podrían salir en
     * `errors()`. Leer del disco tiene que ser una decisión del código, no del dato.
     *
     * @param  array{strict?: bool, uidDomain?: ?string, untitled?: string}  $options
     */
    private function __construct(private readonly ?string $path, ?string $content, private readonly array $options)
    {
        $this->content = $content;
    }

    /**
     * El contenido de un `.ics` (un archivo subido, una respuesta HTTP). Nunca se
     * interpreta como ruta.
     *
     * @param  array{strict?: bool, uidDomain?: ?string, untitled?: string}  $options  Las de `ICalReader::read()`.
     */
    public static function fromString(string $ics, array $options = []): self
    {
        return new self(null, $ics, $options);
    }

    /**
     * Un `.ics` en disco. Se lee una vez, la primera vez que se piden eventos.
     *
     * @param  array{strict?: bool, uidDomain?: ?string, untitled?: string}  $options  Las de `ICalReader::read()`.
     */
    public static function fromFile(string $path, array $options = []): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("ICalSource: no se puede leer el archivo «{$path}».");
        }

        return new self($path, null, $options);
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        $this->load();

        return $this->reader->errors();
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        $this->load();

        return $this->reader->warnings();
    }

    public function events(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        $this->load();

        $zone = $from->getTimezone();
        $earliest = $from->getTimestamp() - self::MARGIN;
        $latest = $to->getTimestamp() + self::MARGIN;
        $result = [];

        foreach ($this->events as $event) {
            if ($event->isOverride()) {
                $result[] = $event;

                continue;
            }

            $start = self::instant($event->start, $zone);

            // Una serie no tiene ocurrencias antes de su inicio. Hacia el otro lado no hay tope: no se
            // sabe desde aquí dónde acaba una regla (UNTIL, COUNT).
            if ($event->isRecurring()) {
                if ($start < $latest) {
                    $result[] = $event;
                }

                continue;
            }

            $end = $event->end === null ? null : self::instant($event->end, $zone);

            if ($start < $latest && ($end === null ? $start >= $earliest : $end > $earliest)) {
                $result[] = $event;
            }
        }

        return $result;
    }

    private function load(): void
    {
        if ($this->events !== null) {
            return;
        }

        $content = $this->content;

        if ($content === null) {
            $content = file_get_contents($this->path);

            if ($content === false) {
                throw new InvalidArgumentException("ICalSource: no se pudo leer «{$this->path}».");
            }
        }

        $this->reader = new ICalReader;
        $this->events = $this->reader->read($content, $this->options);
    }

    /** El instante de un valor de fecha. Una hora de pared o un día se leen en la zona de la vista. */
    private static function instant(DateTimeInterface|string $value, DateTimeZone $zone): int
    {
        return $value instanceof DateTimeInterface
            ? $value->getTimestamp()
            : (new DateTimeImmutable(trim($value), $zone))->getTimestamp();
    }
}
