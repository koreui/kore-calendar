<?php

namespace KoreUi\Calendar\Tests\Feature\Fixtures;

use Livewire\Component;

/** Un componente de Livewire que no es un calendario, con métodos que se llaman como los del calendario. */
class PlainLivewire extends Component
{
    public function events(): string
    {
        return 'mis eventos';
    }

    public function configure(): string
    {
        return 'mi configuración';
    }

    public function render()
    {
        return '<div>plano</div>';
    }
}
