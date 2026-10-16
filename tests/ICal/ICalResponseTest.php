<?php

use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\ICal\ICalReader;
use KoreUi\Calendar\ICal\ICalResponse;
use Symfony\Component\HttpFoundation\Response;

require_once __DIR__.'/helpers.php';

function icalDownload(string $filename = 'equipo.ics', array $options = []): Response
{
    return ICalResponse::download(
        [CalendarEvent::make(id: 'a', title: 'Reunión', start: '2026-10-16 10:00')],
        $filename,
        [...['dtstamp' => '2026-10-16T08:00:00Z', 'uidDomain' => 'ej.test'], ...$options],
    );
}

it('devuelve una Response 200 text/calendar en UTF-8 como adjunto con el nombre dado', function () {
    $response = icalDownload('equipo.ics');

    expect($response)->toBeInstanceOf(Response::class)
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toBe('text/calendar; charset=utf-8')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename=equipo.ics')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('el cuerpo es exactamente lo que escribe ICalWriter, y se lee de vuelta', function () {
    $response = icalDownload();

    expect($response->getContent())->toBe(icalWrite([CalendarEvent::make(id: 'a', title: 'Reunión', start: '2026-10-16 10:00')]))
        ->and((new ICalReader)->read($response->getContent())[0]->title)->toBe('Reunión');
});

it('las opciones llegan al escritor', function () {
    expect(icalDownload('x.ics', ['name' => 'Equipo'])->getContent())->toContain('X-WR-CALNAME:Equipo');
});

it('sin nombre, calendar.ics; sin extensión, se añade .ics; con ella en mayúsculas, no se duplica', function () {
    expect(ICalResponse::download([])->headers->get('Content-Disposition'))->toBe('attachment; filename=calendar.ics')
        ->and(icalDownload('equipo')->headers->get('Content-Disposition'))->toBe('attachment; filename=equipo.ics')
        ->and(icalDownload('EQUIPO.ICS')->headers->get('Content-Disposition'))->toBe('attachment; filename=EQUIPO.ics')
        ->and(icalDownload('')->headers->get('Content-Disposition'))->toBe('attachment; filename=calendar.ics')
        ->and(icalDownload('.ics')->headers->get('Content-Disposition'))->toBe('attachment; filename=calendar.ics');
});

it('un nombre con tildes lleva la versión ASCII y la UTF-8 de RFC 6266', function () {
    $header = icalDownload('Reunión del equipo.ics')->headers->get('Content-Disposition');

    expect($header)->toBe("attachment; filename=\"Reunion del equipo.ics\"; filename*=utf-8''Reuni%C3%B3n%20del%20equipo.ics");
});

it('un nombre con % o comillas no rompe la cabecera', function () {
    $header = icalDownload('100% "listo".ics')->headers->get('Content-Disposition');

    expect($header)->toStartWith('attachment; filename=')->not->toContain("\n")
        ->and(substr_count($header, '"') % 2)->toBe(0);
});

it('un nombre que viene de una petición no puede llevar rutas ni saltos de línea', function () {
    foreach (['../../etc/passwd', '..\\..\\windows\\system32', "equipo.ics\r\nSet-Cookie: a=b", 'a/b/c.ics'] as $hostile) {
        $response = icalDownload($hostile);
        $header = $response->headers->get('Content-Disposition');

        // El texto «Set-Cookie» puede quedar DENTRO del nombre; lo que no puede es abrir otra cabecera.
        expect($header)->not->toContain('/')->not->toContain('\\')->not->toContain("\r")->not->toContain("\n")
            ->and($header)->toStartWith('attachment; filename=')
            ->and($response->headers->has('Set-Cookie'))->toBeFalse()
            ->and(substr($response->headers->get('Content-Disposition'), -5))->toContain('.ics');
    }
});

it('se puede devolver desde una ruta de Laravel', function () {
    Illuminate\Support\Facades\Route::get('/equipo.ics', fn () => ICalResponse::download(
        [CalendarEvent::make(id: 'a', title: 'Desde una ruta', start: '2026-10-16')],
        'equipo.ics',
        ['uidDomain' => 'ej.test'],
    ));

    $this->get('/equipo.ics')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
        ->assertHeader('Content-Disposition', 'attachment; filename=equipo.ics')
        ->assertSee('SUMMARY:Desde una ruta', false);
});
