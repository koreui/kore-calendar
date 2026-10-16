import { columnAt, placeMove } from './geometry.js';

/**
 * Arrastrar eventos en una rejilla horaria (fase 1: prueba técnica).
 *
 * El reparto de papeles es el de toda la librería: el servidor calcula dónde va
 * cada evento y el navegador solo previsualiza. Mientras dura el gesto no hay
 * red: se pinta una sombra. Al soltar hay un único viaje a Livewire, que vuelve
 * a ajustar, valida y persiste; si lo rechaza, la sombra desaparece y el evento
 * sigue donde estaba, porque el servidor nunca lo movió.
 *
 * **Por qué el estado vive aquí y no en el evento.** Un morph de Livewire
 * (un poll, otra acción) puede tocar el nodo del evento en mitad del gesto. Por
 * eso los listeners de movimiento van en `window` (`x-on:pointermove.window`) y
 * no se captura el puntero sobre el evento: si el nodo cambiara, la captura se
 * perdería y el arrastre se quedaría colgado. El x-data de la rejilla sobrevive
 * al morph; los nodos de dentro pueden cambiar sin romper nada.
 */
export default function KoreCalendarDrag(config = {}) {
    return {
        method: config.method ?? 'moveEvent',
        columns: config.columns ?? 7,
        axisMinutes: config.axisMinutes ?? 1440,
        snapMinutes: config.snapMinutes ?? 15,
        minDistance: config.minDistance ?? 5,

        /** El gesto en curso: { id, column, start, duration, originColumn, originStart, moved }. */
        drag: null,
        /** Esperando la respuesta del servidor: la sombra sigue visible, sin más gestos. */
        pending: false,
        /** Texto para la región aria-live. */
        announcement: '',
        _origin: null,

        startDrag(event, id, column, start, duration) {
            if (this.pending || event.button > 0) return;

            const rect = this.$refs.grid.getBoundingClientRect();

            this._origin = { x: event.clientX, y: event.clientY, rect };
            this.drag = {
                id,
                column,
                start,
                duration,
                originColumn: column,
                originStart: start,
                moved: false,
            };

            event.preventDefault();
        },

        moveDrag(event) {
            if (!this.drag || this.pending) return;

            const { x, y, rect } = this._origin;
            const dx = event.clientX - x;
            const dy = event.clientY - y;

            if (!this.drag.moved && Math.hypot(dx, dy) < this.minDistance) return;

            this.drag.moved = true;
            this.drag.column = columnAt(event.clientX - rect.left, rect.width, this.columns);
            this.drag.start = placeMove({
                start: this.drag.originStart,
                duration: this.drag.duration,
                dy,
                height: rect.height,
                axisMinutes: this.axisMinutes,
                snapMinutes: this.snapMinutes,
            });
        },

        async endDrag() {
            if (!this.drag || this.pending) return;

            const { id, column, start, moved, originColumn, originStart } = this.drag;

            // Menos que el umbral es un clic, y soltar donde estaba no es un cambio.
            if (!moved || (column === originColumn && start === originStart)) {
                this.cancelDrag();

                return;
            }

            this.pending = true;

            try {
                const result = await this.$wire[this.method](id, column, start);

                this.announcement = result?.message ?? '';
            } catch (e) {
                // Un fallo de red se trata como un rechazo: el servidor no movió nada.
                this.announcement = '';
            } finally {
                this.pending = false;
                this.drag = null;
                this._origin = null;
            }
        },

        cancelDrag() {
            if (this.pending) return;

            this.drag = null;
            this._origin = null;
        },

        isDragging(id) {
            return this.drag?.moved === true && this.drag.id === id;
        },

        get shadowStyle() {
            if (!this.drag?.moved) return 'display: none';

            const left = (this.drag.column / this.columns) * 100;
            const top = (this.drag.start / this.axisMinutes) * 100;
            const height = (this.drag.duration / this.axisMinutes) * 100;

            return `left: ${left}%; width: ${100 / this.columns}%; top: ${top}%; height: ${height}%`;
        },
    };
}
