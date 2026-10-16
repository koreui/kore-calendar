<?php

namespace KoreUi\Calendar;

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Events\Sources\CallbackSource;
use KoreUi\Calendar\Resources\ResourceSet;
use KoreUi\Calendar\Time\ViewRange;
use KoreUi\Calendar\View\CalendarRenderer;
use KoreUi\Calendar\View\CalendarSettings;
use KoreUi\Calendar\View\CalendarViews;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Clase base de un calendario con estado.
 *
 *     class TeamCalendar extends KoreCalendar
 *     {
 *         public function configure(): void
 *         {
 *             $this->views(['month', 'week', 'list'])->timezone('Europe/Madrid')->slotMinTime('08:00');
 *         }
 *
 *         public function events(CarbonImmutable $from, CarbonImmutable $to): iterable
 *         {
 *             return Meeting::overlapping($from, $to)->get()->map->toCalendarEvent();
 *         }
 *     }
 *
 * Convenciones de KoreDataTable / KoreKanban:
 *
 * - `configure()` se aplica en `booted()`, no en `mount()`: la configuración es
 *   `protected`, Livewire no la serializa, y si solo corriera en `mount()` se
 *   perdería en la primera acción.
 * - El único estado público es la navegación (`view`, `date`), en la URL: se
 *   comparte, sobrevive a recargar y se prueba con `Livewire::test()`.
 * - Lo que se pasa a la vista lleva prefijo `kore`: las propiedades públicas del
 *   consumidor ganan sobre los datos de `view()`, y una `$calendar` suya taparía
 *   la nuestra.
 */
abstract class KoreCalendar extends Component
{
    #[Url(as: 'view', except: '')]
    public string $view = '';

    #[Url(as: 'date', except: '')]
    public string $date = '';

    /** Primera fila de la ventana del timeline virtualizado. No va a la URL. */
    public int $timelineRow = 0;

    /** Ajustes de `configure()` con la forma del config ('time.slot_duration' => '15m'). */
    protected array $koreSettings = [];

    /** @var list<string>|null */
    protected ?array $koreViews = null;

    private ?ResourceSet $koreResourceCache = null;

    /**
     * Los eventos que intersecan [from, to), en instantes con la zona de la vista.
     * CalendarEvent, arrays con su forma, o lo que devuelva `HasCalendarEvents`.
     */
    abstract public function events(CarbonImmutable $from, CarbonImmutable $to): iterable;

    /** Recursos (salas, personas…). Sin recursos, las vistas por recurso no se ofrecen. */
    public function resources(): iterable
    {
        return [];
    }

    /** La configuración del calendario. Se llama en cada petición. */
    public function configure(): void {}

    public function booted(): void
    {
        $this->koreSettings = [];
        $this->koreViews = null;
        $this->configure();
    }

    // ── Navegación (acciones públicas) ──────────────────────────────────

    // previous/next solo necesitan el rango de la vista: sin consultar eventos.
    public function previous(): void
    {
        $this->date = $this->koreRange()->previous()->toDateString();
    }

    public function next(): void
    {
        $this->date = $this->koreRange()->next()->toDateString();
    }

    public function today(): void
    {
        $this->date = '';
    }

    public function changeView(string $view): void
    {
        // Lo que llega del navegador no es fiable: solo vistas que este calendario ofrece.
        if (in_array($view, $this->koreAvailableViews(), true)) {
            $this->view = $view;
            $this->timelineRow = 0;
        }
    }

    public function goToDate(string $date): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            $this->date = $date;
        }
    }

    /** El timeline pide otra ventana de filas al hacer scroll. */
    public function timelineScroll(int $row): void
    {
        $this->timelineRow = max(0, $row);
    }

    // ── Configuración fluida (para configure()) ─────────────────────────

    /** @param list<string> $views */
    protected function views(array $views): static
    {
        $this->koreViews = $views;

        return $this;
    }

    protected function defaultView(string $view): static
    {
        return $this->set('default_view', $view);
    }

    protected function timezone(string $timezone): static
    {
        return $this->set('timezone', $timezone);
    }

    protected function locale(string $locale): static
    {
        return $this->set('locale', $locale);
    }

    protected function firstDay(int $day): static
    {
        return $this->set('first_day', $day);
    }

    /** @param list<int> $days */
    protected function hiddenDays(array $days): static
    {
        return $this->set('hidden_days', $days);
    }

    /** @param 'iso'|'locale'|false $numbering */
    protected function weekNumbers(string|false $numbering = 'iso'): static
    {
        return $this->set('week_numbers', $numbering);
    }

    protected function slotDuration(string $duration): static
    {
        return $this->set('time.slot_duration', $duration);
    }

    protected function slotMinTime(string $time): static
    {
        return $this->set('time.slot_min_time', $time);
    }

    protected function slotMaxTime(string $time): static
    {
        return $this->set('time.slot_max_time', $time);
    }

    protected function maxEvents(?int $max): static
    {
        return $this->set('month.max_events', $max);
    }

    protected function nowIndicator(bool $show = true): static
    {
        return $this->set('now_indicator', $show);
    }

    protected function datesAboveResources(bool $above = true): static
    {
        return $this->set('time_grid.dates_above_resources', $above);
    }

    protected function eventOrder(string $order, bool $strict = false): static
    {
        return $this->set('event_order', $order)->set('event_order_strict', $strict);
    }

    /** Cualquier clave del config, con puntos: `->set('time.event_min_minutes', 20)`. */
    protected function set(string $key, mixed $value): static
    {
        $this->koreSettings[$key] = $value;

        return $this;
    }

    // ── Render ──────────────────────────────────────────────────────────

    public function render()
    {
        return view('kore-calendar::livewire.calendar', [
            'koreCalendar' => $this->koreRendered(),
            'koreViews' => $this->koreAvailableViews(),
        ]);
    }

    /** @return list<string> */
    protected function koreAvailableViews(): array
    {
        return CalendarViews::available($this->koreViews ?? config('kore-calendar.views'), $this->koreResources());
    }

    protected function koreResources(): ResourceSet
    {
        return $this->koreResourceCache ??= new ResourceSet($this->resources());
    }

    protected function koreSettingsResolved(): CalendarSettings
    {
        $views = $this->koreAvailableViews();
        $default = $this->koreSettings['default_view'] ?? config('kore-calendar.default_view');
        $view = in_array($this->view, $views, true) ? $this->view : (in_array($default, $views, true) ? $default : ($views[0] ?? 'month'));

        return CalendarSettings::make([
            ...$this->koreSettings,
            'view' => $view,
            'date' => $this->date !== '' ? $this->date : null,
            'timeline.row_start' => $this->timelineRow,
        ]);
    }

    protected function koreRange(): ViewRange
    {
        $settings = $this->koreSettingsResolved();

        return ViewRange::for($settings->view, $settings->date, $settings->viewOptions);
    }

    protected function koreRendered(): View\RenderedCalendar
    {
        return CalendarRenderer::render(
            $this->koreSettingsResolved(),
            [new CallbackSource(fn (CarbonImmutable $from, CarbonImmutable $to) => $this->events($from, $to))],
            $this->koreResources(),
        );
    }
}
