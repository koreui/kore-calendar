// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest';
import KoreCalendarInteraction from '../../resources/js/interaction/calendar.js';

/**
 * Mover y redimensionar con el teclado (Espacio agarra, flechas mueven, Mayúsculas
 * cambia el fin). Dos cosas que tienen que ir como la rejilla y como lo que dice el servidor:
 *
 * - En RTL, ←/→ van en el sentido visual. La navegación de la rejilla ya los invertía
 *   (`targetIndex`); mover un evento no, y una flecha derecha lo llevaba al día anterior.
 * - Mayúsculas + flechas cambia el FIN, así que el anuncio dice el nuevo fin y no el
 *   inicio (que no se mueve): con el inicio, un usuario de lector de pantalla no se
 *   enteraba de qué hacía la tecla.
 */

const DAY = 86400;
const LUNES = Date.UTC(2026, 9, 12) / 1000; // lunes 12-oct-2026 00:00
const h = (horas, dias = 0) => LUNES + dias * DAY + horas * 3600;

const TEXTOS = {
    grabbed: 'Agarrado.',
    moving: '',
    cancelled: 'Cancelado.',
    resized: '«:title» ahora termina :date',
};

/** Un evento movible y redimensionable dentro de un contenedor (con `dir` opcional, en él o en un ancestro). */
function montar({ dir = null, enAncestro = false, allDay = false, texts = TEXTOS } = {}) {
    const inicio = allDay ? h(0) : h(10);
    const fin = allDay ? h(0, 1) : h(11);
    const atributo = dir ? `dir="${dir}"` : '';

    document.body.innerHTML = `
        <div id="pagina" ${enAncestro ? atributo : ''}>
            <div id="root" ${enAncestro ? '' : atributo}>
                <div id="ev" data-kore-event="7" data-movable data-resizable tabindex="0"
                     data-title="Reunión" data-start="${inicio}" data-end="${fin}" data-all-day="${allDay ? 1 : 0}"></div>
                <div data-kore-calendar-overlay></div>
            </div>
        </div>`;

    const comp = KoreCalendarInteraction({ snap: 900, locale: 'es', texts });
    comp.$root = document.getElementById('root');
    comp.$nextTick = (fn) => fn();
    comp.$wire = { moveEvent: vi.fn(), resizeEvent: vi.fn() };

    return comp;
}

const tecla = (key, extra = {}) => ({ key, target: document.getElementById('ev'), shiftKey: false, preventDefault: vi.fn(), ...extra });

/** Agarra el evento con Espacio. */
function agarrar(comp) {
    comp.onKeydown(tecla(' '));
    expect(comp.keyboard).not.toBeNull();

    return comp;
}

describe('flechas horizontales al mover un evento', () => {
    it('en LTR, → lleva el evento al día siguiente y ← al anterior', () => {
        const comp = agarrar(montar());

        comp.onKeydown(tecla('ArrowRight'));
        expect(comp.keyboard.start).toBe(h(10, 1));
        expect(comp.keyboard.end).toBe(h(11, 1));

        comp.onKeydown(tecla('ArrowLeft'));
        comp.onKeydown(tecla('ArrowLeft'));
        expect(comp.keyboard.start).toBe(h(10, -1));
    });

    it('en RTL (dir en el contenedor), → va al día anterior y ← al siguiente: el sentido visual', () => {
        const comp = agarrar(montar({ dir: 'rtl' }));

        comp.onKeydown(tecla('ArrowRight'));
        expect(comp.keyboard.start).toBe(h(10, -1));
        expect(comp.keyboard.end).toBe(h(11, -1));

        comp.onKeydown(tecla('ArrowLeft'));
        comp.onKeydown(tecla('ArrowLeft'));
        expect(comp.keyboard.start).toBe(h(10, 1));
    });

    it('lo mismo con el dir="rtl" en un ancestro, como lo lee la rejilla', () => {
        const comp = agarrar(montar({ dir: 'rtl', enAncestro: true }));

        comp.onKeydown(tecla('ArrowRight'));

        expect(comp.keyboard.start).toBe(h(10, -1));
    });

    it('↑/↓ no cambian con la dirección: un slot arriba o abajo', () => {
        for (const dir of [null, 'rtl']) {
            const comp = agarrar(montar({ dir }));

            comp.onKeydown(tecla('ArrowDown'));
            expect(comp.keyboard.start, `dir=${dir}`).toBe(h(10) + 900);
            comp.onKeydown(tecla('ArrowUp'));
            comp.onKeydown(tecla('ArrowUp'));
            expect(comp.keyboard.start, `dir=${dir}`).toBe(h(10) - 900);
        }
    });

    it('con Mayúsculas en RTL, ← alarga el evento y → lo acorta: el fin está en el lado izquierdo', () => {
        const comp = agarrar(montar({ dir: 'rtl', allDay: true }));

        comp.onKeydown(tecla('ArrowLeft', { shiftKey: true }));
        expect(comp.keyboard.start).toBe(h(0));
        expect(comp.keyboard.end).toBe(h(0, 2));

        comp.onKeydown(tecla('ArrowRight', { shiftKey: true }));
        expect(comp.keyboard.end).toBe(h(0, 1));
    });
});

describe('el anuncio al redimensionar con el teclado', () => {
    it('Mayúsculas + ↓ anuncia el nuevo fin, no el inicio', () => {
        const comp = agarrar(montar());

        comp.onKeydown(tecla('ArrowDown', { shiftKey: true }));

        // 10:00-11:00 → 10:00-11:15
        expect(comp.keyboard.end).toBe(h(11) + 900);
        expect(comp.announcement).toContain('11:15');
        expect(comp.announcement).not.toContain('10:00');
        // Con el texto traducido del servidor (event_resized): dice que es el fin.
        expect(comp.announcement).toContain('«Reunión» ahora termina');
    });

    it('mover sigue anunciando el inicio', () => {
        const comp = agarrar(montar());

        comp.onKeydown(tecla('ArrowDown'));

        expect(comp.announcement).toContain('10:15');
        expect(comp.announcement).not.toContain('ahora termina');
    });

    it('en un evento de todo el día anuncia el último día (el fin guardado es exclusivo), como el servidor', () => {
        const comp = agarrar(montar({ allDay: true }));

        comp.onKeydown(tecla('ArrowRight', { shiftKey: true }));

        // 12-oct (1 día) → hasta el 13 inclusive: «martes», no «miércoles» (el 14, exclusivo).
        expect(comp.keyboard.end).toBe(h(0, 2));
        expect(comp.announcement).toContain('martes');
        expect(comp.announcement).toContain('13');
        expect(comp.announcement).not.toContain('miércoles');
    });

    it('sin el texto traducido anuncia igualmente el fin', () => {
        const comp = agarrar(montar({ texts: { grabbed: 'Agarrado.' } }));

        comp.onKeydown(tecla('ArrowDown', { shiftKey: true }));

        expect(comp.announcement).toContain('11:15');
        expect(comp.announcement).not.toContain('10:00');
    });

    it('un título con «:date» no se sustituye dentro del texto ya insertado', () => {
        const comp = agarrar(montar());
        document.getElementById('ev').dataset.title = 'Cita :date';
        comp.keyboard.event.title = 'Cita :date';

        comp.onKeydown(tecla('ArrowDown', { shiftKey: true }));

        expect(comp.announcement).toContain('«Cita :date» ahora termina');
    });

    it('si el evento no se puede redimensionar, Mayúsculas + flecha lo mueve y anuncia el inicio', () => {
        const comp = agarrar(montar());
        document.getElementById('ev').removeAttribute('data-resizable');

        comp.onKeydown(tecla('ArrowDown', { shiftKey: true }));

        expect(comp.keyboard.start).toBe(h(10) + 900);
        expect(comp.announcement).toContain('10:15');
    });
});
