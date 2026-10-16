<?php

namespace KoreUi\Calendar\Tests\Feature\Fixtures;

/**
 * Pide una vista móvil con `mobileView()`, para probar que `configure()` llega al cliente.
 * `$mobile` y `$call` los cambia cada test: Livewire crea la instancia, así que no se le puede
 * inyectar nada.
 */
class MobileViewCalendar extends PlainCalendar
{
    public static ?string $mobile = 'day';

    /** `false`: no llama a `mobileView()` (cae al config). */
    public static bool $call = true;

    public function configure(): void
    {
        $this->views(['month', 'week', 'day', 'list']);

        if (static::$call) {
            $this->mobileView(static::$mobile);
        }
    }
}
