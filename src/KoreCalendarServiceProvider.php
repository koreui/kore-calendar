<?php

namespace KoreUi\Calendar;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class KoreCalendarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/kore-calendar.php', 'kore-calendar');
    }

    public function boot(): void
    {
        // Los componentes anónimos comparten el prefijo `kore` con kore-ui:
        // <x-kore::calendar> y no <x-kore-calendar::calendar>. Funciona porque
        // anonymousComponentPath() calcula el namespace como md5 del prefijo y
        // añade esta ruta a la de kore-ui en vez de sustituirla. Todo vive bajo
        // components/calendar/ para no chocar con un componente de kore-ui.
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'kore');

        // Las vistas que no son componentes (la de Livewire, parciales) van en
        // su propio namespace, que sí es exclusivo del paquete.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'kore-calendar');

        Route::get('/vendor/kore-calendar/kore-calendar.js', function () {
            $bundle = __DIR__.'/../dist/kore-calendar.js';

            // Mismo criterio que kore-ui: un bundle que falta da 404, no 500.
            if (! is_file($bundle)) {
                abort(404);
            }

            return response()->file($bundle, [
                'Content-Type' => 'application/javascript; charset=utf-8',
                'Cache-Control' => request()->filled('id')
                    ? 'public, max-age=31536000, immutable'
                    : 'public, max-age=0, must-revalidate',
            ]);
        })->name('kore-calendar.scripts');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/kore-calendar.php' => config_path('kore-calendar.php'),
            ], 'kore-calendar-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/kore-calendar'),
            ], 'kore-calendar-views');
        }
    }

    /**
     * URL del bundle con la huella del archivo.
     *
     * La carga el propio componente con `@assets`, no una directiva en el
     * layout: así funciona también cuando el calendario llega dentro de un
     * overlay o de una respuesta de Livewire.
     */
    public static function scriptUrl(): string
    {
        static $huella = null;

        if ($huella === null) {
            $bundle = __DIR__.'/../dist/kore-calendar.js';
            $huella = is_file($bundle) ? substr(md5((string) filemtime($bundle)), 0, 8) : 'dev';
        }

        return route('kore-calendar.scripts', ['id' => $huella]);
    }
}
