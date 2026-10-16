<?php

use Illuminate\Support\Facades\Route;
use KoreUi\Calendar\Interaction\Messages;
use KoreUi\Calendar\KoreCalendarServiceProvider;

it('fusiona su configuración bajo kore-calendar', function () {
    expect(config('kore-calendar.time.slot_duration'))->toBe('30m');
});

it('sirve los componentes con el prefijo kore sin tapar los de kore-ui', function () {
    // El riesgo de compartir prefijo: que la segunda ruta anónima sustituya a la
    // de kore-ui en vez de sumarse. Si pasara, <x-kore::badge> dejaría de existir.
    $this->blade('<x-kore::badge label="Sigue vivo" />')->assertSee('Sigue vivo');

    // Y los del calendario siguen en su sitio al lado de los de kore-ui.
    $this->blade('<x-kore::calendar.toolbar :calendar="$calendar" />', [
        'calendar' => KoreUi\Calendar\View\CalendarRenderer::render(KoreUi\Calendar\View\CalendarSettings::make(['timezone' => 'UTC'])),
    ])->assertSee('aria-live="polite"', false);
});

it('los componentes declaran el bundle con @assets, no con un <script> a pelo', function () {
    // Livewire recoge @assets y lo inyecta en la respuesta completa, así que NO
    // aparece en el HTML de un componente aislado. Un <script> a pelo que llegara
    // dentro de una respuesta de Livewire (el calendario en un modal) no lo
    // ejecutaría el navegador. Que llegue de verdad se comprueba en el demo.
    foreach (['month', 'week', 'list', 'timeline-day'] as $vista) {
        $html = (string) $this->blade("<x-kore::calendar view=\"{$vista}\" :resources=\"[['id' => 'a', 'title' => 'Sala']]\" timezone=\"UTC\" />");

        expect($html)->not->toContain('<script src=');
    }
});

it('la prueba técnica de la fase 1 ya no existe: ni la vista ni su componente de Alpine', function () {
    expect(view()->exists('kore::components.calendar.spike-week'))->toBeFalse()
        ->and(file_exists(__DIR__.'/../../resources/views/components/calendar/spike-week.blade.php'))->toBeFalse()
        ->and(file_exists(__DIR__.'/../../resources/js/interaction/drag.js'))->toBeFalse()
        // El bundle versionado es lo que llega a la aplicación: sin rastro de ella.
        ->and(file_get_contents(__DIR__.'/../../dist/kore-calendar.js'))->not->toContain('KoreCalendarDrag');

    expect(fn () => (string) $this->blade('<x-kore::calendar.spike-week :columns="[\'lun\']" :events="[]" />'))->toThrow(InvalidArgumentException::class, 'Unable to locate a class or view for component [kore::calendar.spike-week]');
});

// ── Vistas publicadas ─────────────────────────────────────────────────

/**
 * Escribe una vista en el sitio donde `vendor:publish --tag=kore-calendar-views` deja las suyas
 * y la borra al acabar. Se escribe DESPUÉS de arrancar la aplicación, como hace quien publica con
 * el servidor ya en marcha o entre dos tests: el registro no puede depender de que el directorio exista al arrancar.
 */
function conVistaPublicada(string $relativa, string $contenido, Closure $prueba): void
{
    $ruta = resource_path('views/vendor/kore-calendar/'.$relativa);
    $existia = is_dir(dirname($ruta));

    @mkdir(dirname($ruta), 0777, true);
    file_put_contents($ruta, $contenido);

    try {
        $prueba();
    } finally {
        @unlink($ruta);
        $limpiar = resource_path('views/vendor/kore-calendar');

        // Solo se borra lo que creó este test (y `vendor/`, si quedó vacía: rmdir no toca una con algo dentro).
        if (! $existia && is_dir($limpiar)) {
            exec('rm -rf '.escapeshellarg($limpiar));
            @rmdir(dirname($limpiar));
        }
    }
}

it('una vista de componente publicada gana a la del paquete', function () {
    conVistaPublicada('components/calendar/toolbar.blade.php', '<p>TOOLBAR PUBLICADA</p>', function () {
        $html = (string) $this->blade('<x-kore::calendar timezone="UTC" />');

        expect($html)->toContain('TOOLBAR PUBLICADA')
            // Y el resto del calendario sigue siendo el del paquete.
            ->and($html)->toContain('role="table"');
    });
});

it('sin copia publicada, el paquete sirve su vista', function () {
    $html = (string) $this->blade('<x-kore::calendar timezone="UTC" />');

    expect($html)->not->toContain('TOOLBAR PUBLICADA')->toContain('aria-live="polite"');
});

it('el tag kore-calendar-views publica solo lo que el paquete lee de ahí', function () {
    $publicado = Illuminate\Support\ServiceProvider::pathsToPublish(KoreCalendarServiceProvider::class, 'kore-calendar-views');
    $destinos = array_values($publicado);

    // Una única carpeta de origen, hacia la carpeta que el paquete busca primero.
    expect($publicado)->toHaveCount(1)
        ->and($destinos[0])->toBe(resource_path('views/vendor/kore-calendar'))
        // Cada carpeta publicada tiene quien la lea: `components` (rutas anónimas) y `livewire` (namespace de vistas).
        ->and(array_map('basename', glob(array_keys($publicado)[0].'/*', GLOB_ONLYDIR)))->toEqualCanonicalizing(['components', 'livewire']);
});

// ── Configuración publicada ───────────────────────────────────────────

/** Arranca el registro del proveedor sobre una configuración ya cargada, como hace Laravel con config/kore-calendar.php. */
function registrarConfig(array $publicada): void
{
    config()->set('kore-calendar', $publicada);
    (new KoreCalendarServiceProvider(app()))->register();
}

it('`translations` viene vacío: los textos de serie están en lang/, no en el config', function () {
    expect(config('kore-calendar.translations'))->toBe([])
        ->and(config('kore-calendar.locale'))->toBeNull()
        ->and(config('kore-calendar.fallback_locale'))->toBe('en');
});

it('un override publicado suelto cambia solo esa traducción y deja las del archivo de idioma', function () {
    registrarConfig(['translations' => ['today' => 'Ahora']]);

    expect(Messages::get('today', [], 'es'))->toBe('Ahora')
        ->and(Messages::get('next', [], 'es'))->toBe('Siguiente')
        ->and(Messages::get('form_save', [], 'es'))->toBe('Guardar');
});

it('un bloque publicado a medias conserva el resto de sus claves', function () {
    registrarConfig([
        'formats' => ['title_month' => 'MMM YYYY'],
        'time' => ['slot_duration' => '15m'],
        'interaction' => ['editable' => true, 'valid_range' => ['start' => '2026-01-01']],
        'timeline' => ['slots' => ['timeline-day' => '2h']],
    ]);

    expect(config('kore-calendar.formats.title_month'))->toBe('MMM YYYY')
        ->and(config('kore-calendar.formats.day_header'))->toBe('ddd D')
        ->and(config('kore-calendar.time.slot_duration'))->toBe('15m')
        ->and(config('kore-calendar.time.slot_max_time'))->toBe('24:00')
        ->and(config('kore-calendar.interaction.editable'))->toBeTrue()
        ->and(config('kore-calendar.interaction.selectable'))->toBeFalse()
        // Hasta dentro de un bloque anidado.
        ->and(config('kore-calendar.interaction.valid_range'))->toBe(['start' => '2026-01-01', 'end' => null])
        ->and(config('kore-calendar.timeline.slots'))->toBe(['timeline-day' => '2h', 'timeline-week' => '1d', 'timeline-month' => '1d'])
        // Y lo que no se tocó está intacto.
        ->and(config('kore-calendar.default_view'))->toBe('month');
});

it('una lista publicada sustituye a la del paquete, no se mezcla índice a índice', function () {
    registrarConfig([
        'views' => ['week', 'list'],
        'hidden_days' => [0, 6],
        'business_hours' => [
            ['daysOfWeek' => [1, 2, 3], 'startTime' => '08:00', 'endTime' => '14:00'],
            ['daysOfWeek' => [4, 5], 'startTime' => '10:00', 'endTime' => '18:00'],
        ],
    ]);

    // Con array_replace_recursive quedarían `week`, `list` y los siete últimos del paquete.
    expect(config('kore-calendar.views'))->toBe(['week', 'list'])
        ->and(config('kore-calendar.hidden_days'))->toBe([0, 6])
        ->and(config('kore-calendar.business_hours'))->toHaveCount(2)
        ->and(config('kore-calendar.business_hours.1.daysOfWeek'))->toBe([4, 5]);
});

it('una lista más corta que la del paquete no deja colas', function () {
    registrarConfig(['views' => ['month']]);

    expect(config('kore-calendar.views'))->toBe(['month']);

    // Y las vistas del selector son esas, de punta a punta.
    $this->blade('<x-kore::calendar timezone="UTC" />')->assertDontSee('view=week', false);
});

it('un horario laboral de un solo tramo (asociativo) sustituye a `false`, no se funde con él', function () {
    registrarConfig(['business_hours' => ['daysOfWeek' => [1, 2], 'startTime' => '08:00', 'endTime' => '14:00']]);

    expect(config('kore-calendar.business_hours'))->toBe(['daysOfWeek' => [1, 2], 'startTime' => '08:00', 'endTime' => '14:00']);
});

it('un bloque publicado vacío no borra los ajustes del paquete', function () {
    registrarConfig(['formats' => [], 'interaction' => []]);

    expect(config('kore-calendar.formats.title_month'))->toBe('MMMM YYYY')
        ->and(config('kore-calendar.interaction.editable'))->toBeFalse();
});

it('el tag kore-calendar-lang publica lang/ en lang/vendor/kore-calendar, donde loadTranslationsFrom busca primero', function () {
    $publicado = Illuminate\Support\ServiceProvider::pathsToPublish(KoreCalendarServiceProvider::class, 'kore-calendar-lang');

    expect($publicado)->toHaveCount(1)
        ->and(array_values($publicado)[0])->toBe(app()->langPath('vendor/kore-calendar'))
        ->and(array_map('basename', glob(array_keys($publicado)[0].'/*', GLOB_ONLYDIR)))->toEqualCanonicalizing(['en', 'es'])
        // Y el paquete las lee como `kore-calendar::calendar.*`.
        ->and(trans('kore-calendar::calendar.today', [], 'en'))->toBe('Today');
});

it('sin configuración publicada quedan los valores del paquete', function () {
    expect(config('kore-calendar.views'))->toHaveCount(9)
        ->and(config('kore-calendar.default_view'))->toBe('month');
});
