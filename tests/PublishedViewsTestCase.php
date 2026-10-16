<?php

namespace KoreUi\Calendar\Tests;

/**
 * Una aplicación que arranca con la vista de Livewire ya publicada.
 *
 * `loadViewsFrom` solo busca en `resources/views/vendor/kore-calendar` si la carpeta
 * existe al ARRANCAR (en una aplicación real, la publicación de la petición anterior),
 * así que no se puede probar escribiendo el archivo dentro del test: la aplicación ya
 * arrancó. Aquí se escribe antes de arrancar y se borra al terminar.
 */
class PublishedViewsTestCase extends TestCase
{
    private ?string $carpeta = null;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->carpeta = $app->resourcePath('views/vendor/kore-calendar');

        @mkdir($this->carpeta.'/livewire', 0777, true);
        file_put_contents($this->carpeta.'/livewire/calendar.blade.php', '<div>CALENDARIO PUBLICADO</div>');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->carpeta !== null && is_dir($this->carpeta)) {
            exec('rm -rf '.escapeshellarg($this->carpeta));
            @rmdir(dirname($this->carpeta)); // `vendor/`, si quedó vacía
        }
    }
}
