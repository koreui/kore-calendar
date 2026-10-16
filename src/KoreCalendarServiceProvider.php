<?php

namespace KoreUi\Calendar;

use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use KoreUi\Calendar\Overlays\EventForm;
use KoreUi\Calendar\Overlays\RecurrenceScope;
use Livewire\Livewire;

class KoreCalendarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigDeep(__DIR__.'/../config/kore-calendar.php', 'kore-calendar');
    }

    /**
     * Como `mergeConfigFrom`, pero con los bloques asociativos fundidos en profundidad.
     *
     * `mergeConfigFrom` usa `array_merge`, que solo mira las claves de primer nivel: quien
     * publica el config y deja un `translations` con una sola traducción pierde las demás
     * (y un `formats`, un `time` o un `interaction` a medias, el resto de su bloque), y el
     * texto sin traducción sale como la clave. Aquí lo publicado se funde sobre los valores
     * del paquete, a cualquier profundidad, con una excepción: las LISTAS se sustituyen
     * enteras. Un `views` de dos vistas tiene que ser esas dos, no las dos primeras del
     * paquete con las siete últimas detrás (que es lo que haría `array_replace_recursive`),
     * ni un tramo de `business_hours` mezclado índice a índice con otro.
     */
    protected function mergeConfigDeep(string $path, string $key): void
    {
        // Con el config en caché ya trae el resultado del primer arranque (Laravel hace lo mismo).
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');

        $config->set($key, self::mergeDefaults(require $path, (array) $config->get($key, [])));
    }

    /**
     * Lo publicado sobre los valores por defecto. Se funde (recursivamente) solo cuando
     * el valor por defecto es un bloque asociativo y lo publicado también lo es; en
     * cualquier otro caso lo publicado sustituye al valor entero: listas, escalares,
     * `null` y, por ejemplo, un `business_hours` que por defecto es `false`.
     *
     * Un bloque publicado vacío (`'translations' => []`) cuenta como «sin cambios»: lo
     * natural al dejar el esqueleto de un bloque sin rellenar, y vaciarlo dejaría la
     * interfaz sin un solo texto.
     *
     * @param  array<array-key, mixed>  $defaults
     * @param  array<array-key, mixed>  $published
     * @return array<array-key, mixed>
     */
    private static function mergeDefaults(array $defaults, array $published): array
    {
        foreach ($published as $key => $value) {
            $default = $defaults[$key] ?? null;

            $defaults[$key] = is_array($default) && is_array($value)
                && $default !== [] && ! array_is_list($default)
                && ($value === [] || ! array_is_list($value))
                    ? self::mergeDefaults($default, $value)
                    : $value;
        }

        return $defaults;
    }

    public function boot(): void
    {
        // Los componentes anónimos comparten el prefijo `kore` con kore-ui:
        // <x-kore::calendar> y no <x-kore-calendar::calendar>. Funciona porque
        // anonymousComponentPath() calcula el namespace como md5 del prefijo y
        // añade esta ruta a la de kore-ui en vez de sustituirla. Todo vive bajo
        // components/calendar/ para no chocar con un componente de kore-ui.
        //
        // La carpeta donde `vendor:publish --tag=kore-calendar-views` deja las copias se
        // registra ANTES que la del paquete: las rutas de un mismo prefijo se buscan por
        // orden de registro, así que una copia publicada gana a la original. Sin esto el
        // tag publicaba `components/` y nadie lo leía (anonymousComponentPath no mira
        // `resources/views/vendor`, a diferencia de loadViewsFrom). Se registra aunque la
        // carpeta no exista todavía: no cuesta nada y no depende de cuándo se publique.
        Blade::anonymousComponentPath(resource_path('views/vendor/kore-calendar/components'), 'kore');
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'kore');

        // Las vistas que no son componentes (la de Livewire, parciales) van en
        // su propio namespace, que sí es exclusivo del paquete. loadViewsFrom ya
        // busca antes en `resources/views/vendor/kore-calendar` (si existe al arrancar).
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'kore-calendar');

        // El formulario de evento es un overlay de kore-ui: lo abre el calendario
        // con `kore:open` por este nombre, y el OverlayManager lo resuelve con él.
        Livewire::component('kore-calendar-event-form', EventForm::class);

        // Y el diálogo que pregunta el alcance de un cambio en una serie recurrente.
        Livewire::component('kore-calendar-recurrence-scope', RecurrenceScope::class);

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

            // `components/` y `livewire/`: las dos carpetas tienen quien lea la copia (las
            // rutas anónimas de arriba y el namespace `kore-calendar`). Si se añade otra
            // carpeta a resources/views, tiene que poder sobrescribirse por uno de los dos
            // caminos; tests/Feature/ServiceProviderTest.php lo comprueba.
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
