// @vitest-environment jsdom
import { beforeEach, describe, expect, it } from 'vitest';
import KoreCalendarInteraction from '../../resources/js/interaction/calendar.js';

/**
 * La previsualización fuera del horario laboral: `draw()` marca con `data-invalid` el
 * rectángulo cuyo tramo no cabe en el `data-business` de su destino. Es solo una pista
 * (el servidor decide al soltar y vuelve a validar), así que lo que se prueba es que
 * marque cuando debe, que no marque sin horario ni sin restricción, y que no cambie
 * dónde se dibuja.
 *
 * Los tramos son markers (segundos de pared) `inicio:fin`, como los pone el servidor.
 */

const DAY = 86400;
const LUNES = Date.UTC(2026, 9, 12) / 1000;            // lunes 12-oct-2026 00:00
const h = (dia, horas) => LUNES + dia * DAY + horas * 3600;
const tramo = (dia, desde, hasta) => `${h(dia, desde)}:${h(dia, hasta)}`;

/** Una columna horaria del lunes (09-13 y 15-19) y una del sábado (sin tramos), más el carril de la capa. */
function montar(extra = '') {
    document.body.innerHTML = `
        <div id="root">
            <div id="lunes" data-kore-drop="time" data-origin="${h(0, 0)}" data-axis="${DAY}" data-business="${tramo(0, 9, 13)},${tramo(0, 15, 19)}" ${extra}></div>
            <div id="sabado" data-kore-drop="time" data-origin="${h(5, 0)}" data-axis="${DAY}" data-business=""></div>
            <div id="libre" data-kore-drop="time" data-origin="${h(1, 0)}" data-axis="${DAY}"></div>
            <div data-kore-calendar-overlay></div>
        </div>`;
}

function componente(config = {}) {
    const comp = KoreCalendarInteraction({ snap: 900, ...config });
    comp.$root = document.getElementById('root');

    return comp;
}

/** Los rectángulos dibujados, con su marca. */
const dibujados = () => [...document.querySelectorAll('.kore-calendar-preview')].map((n) => n.hasAttribute('data-invalid'));

const mover = { start: 0, end: 0, allDay: false, resource: null };

describe('previsualización fuera de horario', () => {
    beforeEach(() => montar());

    it('no marca un tramo que cabe entero en uno laborable', () => {
        componente({ constrainEvent: true }).draw({ ...mover, start: h(0, 10), end: h(0, 12) });

        expect(dibujados()).toEqual([false]);
    });

    it('marca el que se sale por el cierre, por la apertura o cae en el hueco entre tramos', () => {
        const comp = componente({ constrainEvent: true });

        for (const [desde, hasta] of [[12, 14], [8, 10], [13, 15], [18, 20]]) {
            comp.draw({ ...mover, start: h(0, desde), end: h(0, hasta) });
            expect(dibujados(), `${desde}-${hasta}`).toEqual([true]);
        }
    });

    it('el borde es semiabierto: acabar justo al cerrar cabe', () => {
        componente({ constrainEvent: true }).draw({ ...mover, start: h(0, 17), end: h(0, 19) });

        expect(dibujados()).toEqual([false]);
    });

    it('un destino sin tramos (sábado) marca todo; uno sin data-business no marca nada', () => {
        const comp = componente({ constrainEvent: true });

        comp.draw({ ...mover, start: h(5, 10), end: h(5, 11) });
        expect(dibujados()).toEqual([true]);

        comp.draw({ ...mover, start: h(1, 3), end: h(1, 4) });
        expect(dibujados()).toEqual([false]);
    });

    it('sin la restricción activada no marca nunca', () => {
        componente().draw({ ...mover, start: h(0, 20), end: h(0, 21) });

        expect(dibujados()).toEqual([false]);
    });

    it('seleccionar usa su propia restricción', () => {
        const comp = componente({ constrainEvent: false, constrainSelect: true });
        comp.gesture = { type: 'select' };

        comp.draw({ ...mover, start: h(0, 20), end: h(0, 21) });
        expect(dibujados()).toEqual([true]);

        comp.gesture = { type: 'move' };
        comp.draw({ ...mover, start: h(0, 20), end: h(0, 21) });
        expect(dibujados()).toEqual([false]);
    });

    it('un rango que cruza dos columnas se juzga por tramo de cada una', () => {
        const comp = componente({ constrainEvent: true });

        // Lunes 18:00 → martes 02:00: el trozo del lunes (18:00-24:00) no cabe en 15:00-19:00 y se marca;
        // la columna del martes no tiene data-business (sin horario), así que no marca nada.
        comp.draw({ ...mover, start: h(0, 18), end: h(1, 2) });

        expect(dibujados()).toEqual([true, false]);
    });

    it('un evento de día completo solo exige que el día se trabaje', () => {
        document.body.innerHTML = `
            <div id="root">
                <div data-kore-drop="day" data-date="${h(0, 0)}" data-business="${tramo(0, 9, 17)}"></div>
                <div data-kore-drop="day" data-date="${h(5, 0)}" data-business=""></div>
                <div data-kore-calendar-overlay></div>
            </div>`;
        const comp = componente({ constrainEvent: true });

        comp.draw({ start: h(0, 0), end: h(1, 0), allDay: true, resource: null });
        expect(dibujados()).toEqual([false]);

        comp.draw({ start: h(5, 0), end: h(6, 0), allDay: true, resource: null });
        expect(dibujados()).toEqual([true]);
    });

    it('no cambia dónde se dibuja: solo se añade la marca', () => {
        const comp = componente({ constrainEvent: true });

        comp.draw({ ...mover, start: h(0, 10), end: h(0, 12) });

        const caja = document.querySelector('.kore-calendar-preview');
        expect(caja.className).toBe('kore-calendar-preview');
        expect(caja.style.cssText).toContain('left:');
    });

    it('draw(null) limpia la capa', () => {
        const comp = componente({ constrainEvent: true });

        comp.draw({ ...mover, start: h(0, 10), end: h(0, 12) });
        comp.draw(null);

        expect(dibujados()).toEqual([]);
    });
});
