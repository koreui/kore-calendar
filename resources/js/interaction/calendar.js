import { clickedEventId, keyedEventId } from './click.js';
import { DAY, computeMove, computeResize, computeSelection, hitFrom } from './mutation.js';

/**
 * Arrastrar, redimensionar, seleccionar y mover con el teclado, en todas las vistas.
 *
 * Lo validó la prueba técnica de la fase 1 y se mantiene: el servidor calcula
 * dónde va todo y el navegador solo previsualiza. Mientras dura el gesto no hay
 * red; al soltar, UN viaje a Livewire (moveEvent, resizeEvent, selectRange), que
 * vuelve a ajustar, valida y persiste. Si lo rechaza, la previsualización
 * desaparece y el evento sigue donde estaba: el servidor nunca lo movió.
 *
 * - Los listeners de movimiento van en `window` y el estado vive aquí, no en el
 *   nodo del evento: un morph a mitad del gesto puede cambiar ese nodo.
 * - La previsualización se dibuja en una capa con `wire:ignore`
 *   ([data-kore-calendar-overlay]): el morph no la toca, y no hay que inventar
 *   nodos dentro de las celdas que Livewire borraría.
 * - Los destinos se describen con data-* en el HTML del servidor
 *   (data-kore-drop, data-date, data-origin, data-axis, data-resource), así que
 *   esto no sabe de vistas: mes, rejilla horaria, recursos y timeline son lo mismo.
 * - Pulsar un evento (clic, o Intro con el teclado) es un viaje más:
 *   `$wire.clickEvent(id)`. El servidor decide qué abre (el formulario, o lo que
 *   haga el `onEventClick()` del consumidor). Solo si el evento lleva
 *   `data-clickable`; uno con `href` navega solo.
 * - Los tres viajes mandan el `instanceId` del nodo (`data-kore-instance`), no el id
 *   del evento: las ocurrencias de una serie comparten id. Si el servidor responde
 *   `needsScope` (era una ocurrencia y abrió el diálogo de alcance), no se guardó
 *   nada y no hay nada que anunciar: solo se quita la previsualización, y la
 *   acción se completa por otro camino cuando el usuario elige.
 */
export default function KoreCalendarInteraction(config = {}) {
    return {
        snap: config.snap ?? 1800,
        selectable: config.selectable ?? false,
        defaultTimed: config.defaultTimed ?? 3600,
        locale: config.locale ?? 'es',
        texts: config.texts ?? {},

        gesture: null,
        keyboard: null,
        pending: false,
        announcement: '',
        _suppressClick: false,
        _longPress: null,
        _blockScroll: null,

        // ── Puntero ────────────────────────────────────────────────────

        onPointerDown(e) {
            if (this.pending || e.button > 0 || this.keyboard) return;

            const eventEl = e.target.closest('[data-kore-event]');
            const handle = e.target.closest('[data-kore-resize]');
            const drop = e.target.closest('[data-kore-drop]');

            let type = null;
            if (handle && eventEl?.hasAttribute('data-resizable')) type = 'resize';
            else if (eventEl?.hasAttribute('data-movable')) type = 'move';
            else if (!eventEl && drop && this.selectable && !e.target.closest('button, a')) type = 'select';
            if (!type) return;

            const fromHit = this.hitAt(e.clientX, e.clientY);
            if (!fromHit) return;

            this.gesture = {
                type,
                event: eventEl ? readEvent(eventEl) : null,
                fromResize: handle?.dataset.koreResize === 'start',
                fromHit,
                hit: fromHit,
                x: e.clientX,
                y: e.clientY,
                moved: false,
                // Táctil: pulsación larga antes de arrastrar, o el gesto sería un scroll.
                armed: e.pointerType !== 'touch',
            };

            if (e.pointerType === 'touch') {
                this._longPress = setTimeout(() => {
                    if (!this.gesture) return;
                    this.gesture.armed = true;
                    // Armado: el dedo ya no hace scroll. touch-action no se puede
                    // cambiar a mitad de gesto; un touchmove no pasivo sí lo frena.
                    this._blockScroll = (ev) => ev.preventDefault();
                    window.addEventListener('touchmove', this._blockScroll, { passive: false });
                }, 450);
            } else {
                e.preventDefault();
            }
        },

        onPointerMove(e) {
            const g = this.gesture;
            if (!g) return;

            const distance = Math.hypot(e.clientX - g.x, e.clientY - g.y);

            if (!g.armed) {
                // Se movió antes de la pulsación larga: era un scroll, no un arrastre.
                if (distance > 8) this.cancel();

                return;
            }

            if (!g.moved && distance < 5) return;

            g.moved = true;
            e.preventDefault?.();

            const hit = this.hitAt(e.clientX, e.clientY);
            if (!hit) return;

            g.hit = hit;
            this.draw(this.preview());
        },

        async onPointerUp() {
            const g = this.gesture;
            clearTimeout(this._longPress);
            if (!g) return;

            if (!g.moved) {
                this.cancel();

                return;
            }

            // El clic que sigue a un arrastre no es un clic: no abre el enlace del evento.
            this._suppressClick = true;
            setTimeout(() => { this._suppressClick = false; }, 0);

            await this.commit(this.preview(), g);
        },

        onClickCapture(e) {
            if (this._suppressClick) {
                e.preventDefault();
                e.stopPropagation();

                return;
            }

            // Con un evento agarrado con el teclado, un clic no es «abrir».
            const id = this.keyboard ? null : clickedEventId(e.target);
            if (id !== null) this.openEvent(id);
        },

        // ── Teclado ────────────────────────────────────────────────────

        /**
         * Espacio agarra el evento enfocado; flechas lo mueven (←/→ un día, ↑/↓ un
         * slot en las vistas con hora o una semana en el mes); Mayúsculas + flechas
         * cambian el fin; Intro confirma y Escape cancela. FullCalendar no tiene nada
         * de esto: sin ratón no se puede mover un evento.
         */
        onKeydown(e) {
            const eventEl = e.target.closest?.('[data-kore-event][data-movable]');

            if (!this.keyboard) {
                if (eventEl && e.key === ' ' && !this.pending) {
                    e.preventDefault();
                    const ev = readEvent(eventEl);
                    this.keyboard = { event: ev, start: ev.start, end: ev.end, allDay: ev.allDay, resource: ev.resource };
                    this.say(this.describe(this.keyboard, 'grabbed'));

                    return;
                }

                // Intro (o Espacio, si no se puede mover) sobre un evento pulsable.
                const id = keyedEventId(e);
                if (id !== null) {
                    e.preventDefault();
                    this.openEvent(id);
                }

                return;
            }

            const k = this.keyboard;
            const stepTime = k.allDay || this.isMonth() ? DAY : this.snap;
            const vertical = this.isMonth() ? 7 * DAY : stepTime;
            const delta = { ArrowLeft: -DAY, ArrowRight: DAY, ArrowUp: -vertical, ArrowDown: vertical }[e.key];

            if (delta !== undefined) {
                e.preventDefault();
                if (e.shiftKey && eventEl?.hasAttribute('data-resizable')) {
                    k.end = Math.max(k.start + stepTime, k.end + (Math.abs(delta) === DAY ? delta : Math.sign(delta) * stepTime));
                } else {
                    k.start += delta;
                    k.end += delta;
                }
                this.draw(k);
                this.say(this.describe(k, 'moving'));

                return;
            }

            if (e.key === 'Escape' || e.key === 'Tab') {
                if (e.key === 'Escape') e.preventDefault();
                this.say(this.describe(k, 'cancelled'));
                this.cancel();

                return;
            }

            if (e.key === 'Enter') {
                e.preventDefault();
                const resized = k.start === k.event.start && k.end !== k.event.end;
                this.commit(k, { type: resized ? 'resize' : 'move', event: k.event, fromResize: false });
            }
        },

        // ── Servidor ───────────────────────────────────────────────────

        /** Pulsar un evento: un viaje, y el servidor decide qué se abre. */
        async openEvent(id) {
            if (this.pending) return;

            this.pending = true;

            try {
                await this.$wire.clickEvent(id);
            } catch (e) {
                // Un fallo de red: no se abrió nada, y no hay estado que deshacer.
            } finally {
                this.pending = false;
            }
        },

        async commit(target, g) {
            if (!target) {
                this.cancel();

                return;
            }

            this.pending = true;

            try {
                let result = null;

                if (g.type === 'move') {
                    if (target.start !== g.event.start || target.allDay !== g.event.allDay || (target.resource ?? null) !== (g.event.resource ?? null)) {
                        result = await this.$wire.moveEvent(g.event.id, target.start, target.allDay, target.resource ?? null, g.event.resource ?? null);
                    }
                } else if (g.type === 'resize') {
                    const edge = g.fromResize ? target.start : target.end;
                    result = await this.$wire.resizeEvent(g.event.id, edge, g.fromResize);
                } else if (g.type === 'select') {
                    await this.$wire.selectRange(target.start, target.end, target.allDay, target.resource ?? null);
                }

                if (result?.message && !result.needsScope) this.say(result.message);
            } catch (e) {
                // Un fallo de red es un rechazo: el servidor no movió nada.
            } finally {
                const fromKeyboard = this.keyboard !== null;
                this.pending = false;
                this.cancel();

                // Con el teclado, el foco vuelve al evento: el morph pudo cambiar su
                // nodo, y sin esto el foco cae al body y se pierde el sitio.
                if (fromKeyboard && g.event) {
                    this.$nextTick(() => focusEvent(this.$root, g.event));
                }
            }
        },

        cancel() {
            clearTimeout(this._longPress);
            if (this._blockScroll) {
                window.removeEventListener('touchmove', this._blockScroll);
                this._blockScroll = null;
            }
            this.gesture = null;
            this.keyboard = null;
            this.draw(null);
        },

        // ── Geometría ──────────────────────────────────────────────────

        preview() {
            const g = this.gesture;
            if (!g) return null;

            if (g.type === 'move') {
                return computeMove(g.event, g.fromHit, g.hit, { defaultTimed: this.defaultTimed });
            }

            if (g.type === 'resize') {
                const r = computeResize(g.event, g.hit, { fromStart: g.fromResize, step: this.snap });

                return { ...r, allDay: g.event.allDay, resource: g.event.resource };
            }

            return computeSelection(g.fromHit, g.hit, this.snap);
        },

        hitAt(x, y) {
            const el = document.elementsFromPoint(x, y).find((n) => n.matches?.('[data-kore-drop]') && this.$root.contains(n));

            return el ? hitFrom(el.dataset, el.getBoundingClientRect(), x, y, this.snap) : null;
        },

        isMonth() {
            return this.$root.querySelector('[data-kore-drop="day"]') !== null;
        },

        /** Pinta la previsualización de un rango: un rectángulo por destino que toca. */
        draw(range) {
            const layer = this.$root.querySelector('[data-kore-calendar-overlay]');
            if (!layer) return;

            layer.replaceChildren();
            if (!range) return;

            const base = layer.getBoundingClientRect();

            for (const rect of rectsFor(this.$root, range)) {
                const box = document.createElement('div');
                box.className = 'kore-calendar-preview';
                box.style.cssText = `left:${rect.left - base.left}px;top:${rect.top - base.top}px;width:${rect.width}px;height:${rect.height}px`;
                layer.appendChild(box);
            }
        },

        // ── Anuncios ───────────────────────────────────────────────────

        say(text) {
            // Vaciar antes: dos anuncios iguales seguidos no se repetirían.
            this.announcement = '';
            this.$nextTick(() => { this.announcement = text; });
        },

        describe(k, state) {
            const fmt = new Intl.DateTimeFormat(this.locale, k.allDay
                ? { timeZone: 'UTC', weekday: 'long', day: 'numeric', month: 'long' }
                : { timeZone: 'UTC', weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });

            // Los textos llegan traducidos del servidor (translations.kbd_*).
            return `${k.event.title}: ${fmt.format(new Date(k.start * 1000))}. ${this.texts[state] ?? ''}`.trim();
        },
    };
}

/**
 * Devuelve el foco al evento tras el morph. Por `instanceId` (es lo que distingue una
 * ocurrencia de las demás de su serie); si no está, por el id del evento: al mover uno
 * normal su `instanceId` cambia con el inicio, pero el id no.
 */
function focusEvent(root, ev) {
    const by = (attr, value) => root.querySelector(`[${attr}="${CSS.escape(String(value))}"]`);

    (by('data-kore-instance', ev.id) ?? by('data-kore-event', ev.eventId ?? ev.id))?.focus();
}

/** Los datos de un evento desde su nodo (los pone el servidor). */
export function readEvent(el) {
    return {
        // El `instanceId`, que es lo que se manda al servidor; `eventId`, para devolver el foco.
        id: el.dataset.koreInstance ?? el.dataset.koreEvent,
        eventId: el.dataset.koreEvent,
        title: el.dataset.title ?? '',
        start: Number(el.dataset.start),
        end: Number(el.dataset.end),
        allDay: el.dataset.allDay === '1',
        resource: el.closest('[data-resource]')?.dataset.resource ?? null,
    };
}

/**
 * Los rectángulos de pantalla que cubre un rango: las celdas de día que toca, o
 * el tramo de cada columna horaria / fila de timeline. Solo los destinos del
 * mismo recurso (si lo hay).
 */
function rectsFor(root, range) {
    const rects = [];
    const sameResource = (el) => !range.resource || el.dataset.resource === String(range.resource);

    for (const el of root.querySelectorAll('[data-kore-drop]')) {
        if (!sameResource(el)) continue;

        const d = el.dataset;
        const r = el.getBoundingClientRect();

        if (d.koreDrop === 'day' || d.koreDrop === 'allday') {
            if (!range.allDay && d.koreDrop === 'allday') continue;
            const day = Number(d.date);
            if (day + DAY > range.start && day < range.end) rects.push(r);
            continue;
        }

        if (range.allDay && d.koreDrop === 'time') continue;

        const origin = Number(d.origin);
        const axis = Number(d.axis);
        const from = Math.max(range.start, origin);
        const to = Math.min(range.end, origin + axis);
        if (to <= from) continue;

        const a = (from - origin) / axis;
        const b = (to - origin) / axis;

        rects.push(d.koreDrop === 'timeline'
            ? { left: r.left + a * r.width, top: r.top + 2, width: (b - a) * r.width, height: r.height - 4 }
            : { left: r.left + 2, top: r.top + a * r.height, width: r.width - 4, height: (b - a) * r.height });
    }

    return rects;
}
