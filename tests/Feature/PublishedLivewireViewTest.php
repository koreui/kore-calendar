<?php

namespace KoreUi\Calendar\Tests\Feature;

use KoreUi\Calendar\Tests\Feature\Fixtures\PlainCalendar;
use KoreUi\Calendar\Tests\PublishedViewsTestCase;
use Livewire\Livewire;

/**
 * `php artisan vendor:publish --tag=kore-calendar-views` deja la vista de Livewire en
 * resources/views/vendor/kore-calendar/livewire/. El namespace `kore-calendar` la busca
 * allí antes que en el paquete (loadViewsFrom). Los componentes anónimos tienen su propio
 * camino, probado en ServiceProviderTest.
 *
 * Es una clase de PHPUnit y no un test de Pest porque Pest liga toda la carpeta tests/ a
 * TestCase, y este necesita la aplicación con la vista ya publicada al arrancar.
 */
class PublishedLivewireViewTest extends PublishedViewsTestCase
{
    public function test_una_vista_de_livewire_publicada_gana_a_la_del_paquete(): void
    {
        Livewire::test(PlainCalendar::class)->assertSee('CALENDARIO PUBLICADO');
    }
}
