// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest';
import KoreCalendarInteraction from '../../resources/js/interaction/calendar.js';

/**
 * Pulsar sobre un evento no empieza una selección, ni siquiera si el evento es de solo lectura.
 *
 * Un evento de solo lectura ya no lleva `data-kore-event` (solo lo llevan los que se pueden
 * mover, redimensionar o pulsar), así que `onPointerDown` no puede reconocerlo por ese atributo:
 * lo reconoce por su clase. Sin eso, arrastrar desde un evento de solo lectura en un calendario
 * `selectable` seleccionaría el rango por debajo en vez de no hacer nada.
 */

const DIA = Date.UTC(2026, 9, 14) / 1000;

function montar({ selectable = true } = {}) {
    document.body.innerHTML = `
        <div id="root">
            <div id="celda" data-kore-drop="day" data-date="${DIA}">
                <div id="vacio"></div>
                <div id="lectura" class="kore-calendar-event"><span id="texto">Solo lectura</span></div>
                <div id="punto" class="kore-calendar-event-dot"></div>
                <div id="movible" class="kore-calendar-event" data-kore-event="1" data-kore-instance="1:1" data-movable data-title="X" data-start="${DIA}" data-end="${DIA + 3600}"></div>
                <div id="pulsable" class="kore-calendar-event" data-kore-event="2" data-kore-instance="2:1" data-clickable></div>
            </div>
            <div data-kore-calendar-overlay></div>
        </div>`;

    const celda = document.getElementById('celda');
    celda.getBoundingClientRect = () => ({ left: 0, top: 0, width: 100, height: 100 });
    // jsdom no maqueta: el único destino bajo el puntero es la celda.
    document.elementsFromPoint = () => [celda];

    const comp = KoreCalendarInteraction({ selectable, snap: 900 });
    comp.$root = document.getElementById('root');

    return comp;
}

const pulsar = (comp, id) => comp.onPointerDown({
    target: document.getElementById(id),
    button: 0,
    clientX: 10,
    clientY: 10,
    pointerType: 'mouse',
    preventDefault: vi.fn(),
});

describe('onPointerDown sobre un evento', () => {
    let comp;

    beforeEach(() => {
        comp = montar();
    });

    it('sobre un hueco de la celda empieza una selección', () => {
        pulsar(comp, 'vacio');

        expect(comp.gesture?.type).toBe('select');
    });

    it('sobre un evento de solo lectura (sin data-kore-event) no empieza nada', () => {
        pulsar(comp, 'lectura');
        expect(comp.gesture).toBeNull();

        // Ni sobre el texto de dentro.
        pulsar(comp, 'texto');
        expect(comp.gesture).toBeNull();
    });

    it('sobre el punto de un evento con hora del mes (kore-calendar-event-dot) tampoco', () => {
        pulsar(comp, 'punto');

        expect(comp.gesture).toBeNull();
    });

    it('un evento pulsable que no se mueve tampoco empieza una selección', () => {
        pulsar(comp, 'pulsable');

        expect(comp.gesture).toBeNull();
    });

    it('un evento movible empieza un movimiento, como antes', () => {
        pulsar(comp, 'movible');

        expect(comp.gesture?.type).toBe('move');
        expect(comp.gesture.event).toMatchObject({ id: '1:1', title: 'X', start: DIA, end: DIA + 3600, allDay: false });
    });

    it('sin selectable no se selecciona en ningún sitio', () => {
        const sinSeleccion = montar({ selectable: false });
        pulsar(sinSeleccion, 'vacio');

        expect(sinSeleccion.gesture).toBeNull();
    });
});
