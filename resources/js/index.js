/**
 * Entry del bundle del calendario.
 *
 * Llega por un `<script defer>` que el propio componente inyecta con `@assets`,
 * así que puede ejecutarse antes o después de que Alpine arranque, y antes o
 * después del bundle de kore-ui. Mismo patrón que `kore-ui/resources/js/editor.js`:
 *
 * - Si Alpine todavía no arrancó, basta con esperar a `alpine:init`.
 * - Si ya arrancó, registrar no basta: Alpine ya recorrió el DOM y dejó muerto
 *   cada `x-data` que no conocía. Hay que volver a inicializar esos nodos.
 *
 * El flag `window.__koreAlpineIniciado` lo escribe kore-ui (dependencia
 * obligatoria) en su `alpine:init`. Como respaldo, con la página ya cargada del
 * todo (`readyState === 'complete'`) Alpine ha arrancado seguro: Livewire lo
 * arranca en DOMContentLoaded, que ocurre antes.
 */
import KoreCalendarInteraction from './interaction/calendar.js';
import KoreCalendarGrid from './grid.js';
import KoreCalendarTimeline from './timeline.js';

const COMPONENTES = {
    KoreCalendarGrid,
    KoreCalendarTimeline,
    KoreCalendarInteraction,
};

const registrar = () => {
    if (!window.Alpine) return false;

    for (const [nombre, factoria] of Object.entries(COMPONENTES)) {
        window.Alpine.data(nombre, factoria);
    }

    return true;
};

/** Los nodos que se quedaron sin componente por llegar tarde. */
const rescatar = () => {
    const selector = Object.keys(COMPONENTES).map((n) => `[x-data^="${n}"]`).join(',');

    [...document.querySelectorAll(selector)]
        .filter((el) => !el._x_dataStack)
        .forEach((el) => window.Alpine.initTree(el));
};

const alpineYaArranco = () => window.__koreAlpineIniciado === true
    || (Boolean(window.Alpine) && document.readyState === 'complete');

if (alpineYaArranco()) {
    if (registrar()) rescatar();
} else {
    document.addEventListener('alpine:init', registrar);
}

window.KoreUi = Object.assign(window.KoreUi || {}, {
    calendar: Object.freeze({ version: '0.1.0-dev' }),
});
