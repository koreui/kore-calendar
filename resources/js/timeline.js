/**
 * Virtualización de filas del timeline.
 *
 * El servidor monta solo una ventana de filas; arriba y abajo hay espaciadores
 * con el alto estimado del resto. Al hacer scroll se calcula qué fila está
 * arriba del todo y, si la ventana actual ya no la cubre con margen, se pide la
 * nueva a Livewire (`timelineScroll`). Fuera de KoreCalendar (componente sin
 * estado) no hay a quién pedirla y todo se monta de una vez.
 *
 * **La ventana actual se lee del DOM en cada scroll** (`data-row-start`…), no de
 * la configuración del x-data: el morph de Livewire reescribe los atributos pero
 * NO reevalúa x-data, así que una copia guardada aquí se quedaría en la primera
 * ventana para siempre.
 */
export default function KoreCalendarTimeline(config = {}) {
    return {
        live: config.live ?? false,
        scrollTo: config.scrollTo ?? 0,
        _pending: false,

        init() {
            // scrollTime: el eje se abre en la hora de trabajo o en "ahora", no a
            // medianoche. Se mide quitando la columna de recursos, que es fija.
            if (!(this.scrollTo > 0)) return;

            const header = this.$root.querySelector('[role=columnheader]');
            const axis = this.$root.scrollWidth - (header?.offsetWidth ?? 0);
            this.$root.scrollLeft = Math.max(0, axis * (this.scrollTo / 100) - axis * 0.02);
        },

        onScroll() {
            if (!this.live || this._pending) return;

            const win = this.$root.querySelector('[data-kore-timeline-window]');
            if (!win) return;

            const start = Number(win.dataset.rowStart);
            const count = Number(win.dataset.rowCount);
            const total = Number(win.dataset.total);
            const first = Math.floor(this.$root.scrollTop / rowHeightOf(this.$root));
            const next = nextWindowStart(first, start, count, total);

            if (next === null) return;

            this._pending = true;
            Promise.resolve(this.$wire.timelineScroll(next)).finally(() => { this._pending = false; });
        },
    };
}

/**
 * Dónde empieza la ventana nueva, o null si la actual todavía cubre lo visible.
 * Se recentra con un tercio de margen arriba para no pedir otra al primer gesto.
 */
export function nextWindowStart(first, start, count, total) {
    const margin = Math.floor(count / 3);
    const covered = first >= start + (start > 0 ? margin / 2 : 0) && first + margin <= start + count;

    if (covered || count === 0) return null;

    const next = Math.max(0, Math.min(first - margin, total - count));

    return next === start ? null : next;
}

function rowHeightOf(root) {
    const value = getComputedStyle(root).getPropertyValue('--kore-calendar-timeline-row-min').trim();
    const rem = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;

    return value.endsWith('rem') ? parseFloat(value) * rem : parseFloat(value) || 40;
}
