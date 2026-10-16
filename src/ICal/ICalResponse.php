<?php

namespace KoreUi\Calendar\ICal;

use DateTimeInterface;
use Illuminate\Support\Str;
use KoreUi\Calendar\Events\CalendarEvent;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * La descarga de un `.ics` desde un controlador:
 *
 *     return ICalResponse::download($events, 'equipo.ics', ['name' => 'Equipo']);
 *
 * Devuelve una `Response` de Symfony (la base de la de Laravel), así que sirve en un
 * controlador, en una ruta con closure y en un middleware.
 */
final class ICalResponse
{
    /**
     * @param  iterable<CalendarEvent|array<string, mixed>>  $events
     * @param  array{name?: ?string, uidDomain?: ?string, dtstamp?: DateTimeInterface|string|null, defaultDuration?: ?int}  $options  Las de `ICalWriter::write()`.
     */
    public static function download(iterable $events, string $filename = 'calendar.ics', array $options = []): Response
    {
        $response = new Response((new ICalWriter)->write($events, $options), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            // Sin esto un navegador puede decidir que un `.ics` es otra cosa y mostrarlo como HTML.
            'X-Content-Type-Options' => 'nosniff',
        ]);

        $name = self::filename($filename);

        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, self::fallback($name)),
        );

        return $response;
    }

    /**
     * El nombre llega de quien llama y a veces de una petición (`?name=`): sin separadores
     * de ruta ni caracteres de control, que acabarían en una cabecera. Siempre `.ics`.
     */
    private static function filename(string $filename): string
    {
        $name = trim(preg_replace('#[\x00-\x1F\x7F/\\\\]+#', '_', $filename));
        $name = preg_replace('/\.ics$/i', '', $name);

        return ($name === '' ? 'calendar' : $name).'.ics';
    }

    /**
     * La cabecera clásica `filename=` solo admite ASCII (los navegadores modernos leen el
     * `filename*=UTF-8''…` que Symfony añade al lado con el nombre real).
     */
    private static function fallback(string $name): string
    {
        $ascii = trim(str_replace(['%', '"'], '_', Str::ascii($name)));

        return preg_match('/^\.ics$/iD', $ascii) === 1 || $ascii === '' ? 'calendar.ics' : $ascii;
    }
}
