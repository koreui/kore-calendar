/**
 * Navegación con flechas por la rejilla del calendario (roving tabindex).
 *
 * FullCalendar se queda en Tab: cada celda y cada evento es una parada, y no hay
 * flechas. Aquí la rejilla entera es UNA parada de tabulación (la celda activa,
 * hoy al entrar) y las flechas mueven dentro: ←/→ un día, ↑/↓ una semana,
 * Inicio/Fin al principio o final de la semana. Los eventos siguen siendo
 * paradas propias, porque se activan.
 *
 * Solo actúa cuando el foco está EN una celda, no en algo de dentro (un "+N más",
 * un evento): esas flechas son suyas.
 */
export default function KoreCalendarGrid(config = {}) {
    return {
        columns: config.columns ?? 7,

        onKeydown(event) {
            const cell = event.target.closest?.('[data-kore-calendar-cell]');

            if (!cell || event.target !== cell || event.altKey || event.ctrlKey || event.metaKey) return;

            const cells = [...this.$root.querySelectorAll('[data-kore-calendar-cell]')];
            const index = cells.indexOf(cell);
            const next = targetIndex(event.key, index, cells.length, this.columns, isRtl(this.$root));

            if (next === null) return;

            event.preventDefault();
            cells[index].setAttribute('tabindex', '-1');
            cells[next].setAttribute('tabindex', '0');
            cells[next].focus();
        },
    };
}

/** El índice de destino, o null si la tecla no es de navegación o se sale de la rejilla. */
export function targetIndex(key, index, total, columns, rtl = false) {
    const forward = rtl ? -1 : 1;
    const rowStart = index - (index % columns);

    const next = {
        ArrowRight: index + forward,
        ArrowLeft: index - forward,
        ArrowDown: index + columns,
        ArrowUp: index - columns,
        Home: rowStart,
        End: Math.min(rowStart + columns - 1, total - 1),
    }[key];

    if (next === undefined || next < 0 || next >= total || next === index) return null;

    return next;
}

function isRtl(el) {
    return el.closest?.('[dir]')?.getAttribute('dir') === 'rtl';
}
