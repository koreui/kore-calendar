/**
 * Vista para pantallas estrechas (`mobile_view`).
 *
 * El servidor no sabe el ancho de la pantalla, así que la elección se hace aquí, UNA vez, al
 * iniciar el componente: si la pantalla es estrecha y la URL no trae `?view=`, se pasa a la
 * vista móvil. Provoca un render extra la primera vez (el servidor pinta la vista normal y el
 * cliente la cambia).
 *
 * Respeta lo que el usuario eligió: con `?view=` en la URL —un enlace compartido, una recarga
 * después de elegir una vista, el selector de la barra— no hace nada. Y no hay bucle posible:
 * al cambiar, la URL ya lleva `?view=` (en el modo Livewire lo escribe `#[Url]`; en el de enlaces,
 * la propia redirección), y una vista que ya es la móvil tampoco se cambia.
 *
 * - Modo Livewire (`live`): `$wire.changeView(vista)`. La URL se actualiza sin añadir una entrada
 *   al historial.
 * - Modo enlaces: `location.replace` a la misma URL con `?view=`. `replace` y no `assign`: la
 *   página sin `?view=` no se queda en el historial, así que Atrás no devuelve a la que acaba
 *   de redirigir (un bucle para el usuario, aunque no para el código).
 */

/** El mismo corte que `sm` de Tailwind. */
export const NARROW = '(max-width: 640px)';

/**
 * ¿A qué vista hay que cambiar? `null` si a ninguna: pantalla ancha, sin vista móvil, la URL ya
 * trae `view` (elección explícita) o ya se está en la vista móvil.
 */
export function mobileViewTarget({ view, current, param = 'view', search = '', narrow = false }) {
    if (!view || !narrow || view === current) return null;

    return new URLSearchParams(search).has(param) ? null : view;
}

/**
 * Cambia a la vista móvil si procede y devuelve si lo hizo. `env` es el global (`window`); se
 * recibe para poder probarlo sin navegador.
 *
 * @param {{ view: ?string, current: ?string, param: string, live: boolean, wire: ?{ changeView: Function } }} cfg
 */
export function applyMobileView(cfg, env = globalThis) {
    const target = mobileViewTarget({
        view: cfg.view,
        current: cfg.current,
        param: cfg.param,
        search: env.location?.search ?? '',
        narrow: env.matchMedia?.(NARROW)?.matches === true,
    });

    if (target === null) return false;

    if (cfg.live) {
        if (typeof cfg.wire?.changeView !== 'function') return false;

        cfg.wire.changeView(target);

        return true;
    }

    const url = new URL(env.location.href);
    url.searchParams.set(cfg.param, target);
    env.location.replace(url.toString());

    return true;
}

export default function KoreCalendarMobileView(config = {}) {
    return {
        view: config.view ?? null,
        current: config.current ?? null,
        param: config.param ?? 'view',
        live: config.live ?? false,
        _done: false,

        init() {
            // Una sola vez por componente: aunque Alpine lo vuelva a iniciar, no se vuelve a decidir.
            if (this._done) return;
            this._done = true;

            // `$wire` solo existe (y solo se toca) dentro de un componente Livewire.
            applyMobileView({
                view: this.view,
                current: this.current,
                param: this.param,
                live: this.live,
                wire: this.live ? this.$wire : null,
            });
        },
    };
}
