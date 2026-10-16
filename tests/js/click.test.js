// @vitest-environment jsdom
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import KoreCalendarInteraction from '../../resources/js/interaction/calendar.js';
import { clickedEventId, keyedEventId } from '../../resources/js/interaction/click.js';

/**
 * Pulsar un evento: qué lo abre (`click.js`, puro) y cómo se cablea al servidor
 * (`KoreCalendarInteraction`, con `$wire` de mentira).
 *
 * Lo que decide el HTML del servidor, no el JS:
 *   data-kore-event   el id
 *   data-clickable    el calendario tiene clic y el evento no es un enlace
 *   data-movable      Espacio lo agarra
 *   href              es un enlace: navega solo
 */

const evento = (atributos = '', id = '7') => `
    <div id="root">
        <div id="ev" data-kore-event="${id}" ${atributos} tabindex="0">
            <span id="titulo">Reunión</span>
            <span id="asa" data-kore-resize="end"></span>
        </div>
        <a id="enlace" href="/x" data-kore-event="8" data-clickable>Enlace</a>
        <div id="otro" data-kore-event="9">Sin clic</div>
    </div>`;

const $ = (id) => document.getElementById(id);
const tecla = (target, key, extra = {}) => ({ key, target, repeat: false, altKey: false, ctrlKey: false, metaKey: false, shiftKey: false, ...extra });

describe('clickedEventId', () => {
    beforeEach(() => { document.body.innerHTML = evento('data-clickable'); });

    it('un clic en el evento, o en algo de dentro, da su id', () => {
        expect(clickedEventId($('ev'))).toBe('7');
        expect(clickedEventId($('titulo'))).toBe('7');
    });

    it('un evento sin data-clickable no se abre: el calendario no tiene clic activo', () => {
        expect(clickedEventId($('otro'))).toBeNull();
    });

    it('un enlace navega solo, aunque se hubiera marcado', () => {
        expect(clickedEventId($('enlace'))).toBeNull();
    });

    it('el asa de redimensionar no abre nada', () => {
        expect(clickedEventId($('asa'))).toBeNull();
    });

    it('fuera de un evento, nada', () => {
        expect(clickedEventId($('root'))).toBeNull();
        expect(clickedEventId(null)).toBeNull();
        expect(clickedEventId(document.body)).toBeNull();
    });
});

describe('keyedEventId', () => {
    it('Intro abre el evento enfocado', () => {
        document.body.innerHTML = evento('data-clickable');

        expect(keyedEventId(tecla($('ev'), 'Enter'))).toBe('7');
    });

    it('Intro también en uno movible: ahí no hay conflicto (agarrado es otro camino)', () => {
        document.body.innerHTML = evento('data-clickable data-movable');

        expect(keyedEventId(tecla($('ev'), 'Enter'))).toBe('7');
    });

    it('Espacio abre uno que no se mueve, pero en uno movible lo agarra y no abre', () => {
        document.body.innerHTML = evento('data-clickable');
        expect(keyedEventId(tecla($('ev'), ' '))).toBe('7');

        document.body.innerHTML = evento('data-clickable data-movable');
        expect(keyedEventId(tecla($('ev'), ' '))).toBeNull();
    });

    it('solo si el foco está EN el evento, no en algo enfocable de dentro', () => {
        document.body.innerHTML = evento('data-clickable');

        expect(keyedEventId(tecla($('titulo'), 'Enter'))).toBeNull();
    });

    it('con modificadores o una tecla mantenida no abre', () => {
        document.body.innerHTML = evento('data-clickable');

        for (const extra of [{ ctrlKey: true }, { metaKey: true }, { altKey: true }, { shiftKey: true }, { repeat: true }]) {
            expect(keyedEventId(tecla($('ev'), 'Enter', extra))).toBeNull();
        }
    });

    it('otras teclas no abren, ni un evento sin clic ni un enlace', () => {
        document.body.innerHTML = evento('data-clickable');

        expect(keyedEventId(tecla($('ev'), 'a'))).toBeNull();
        expect(keyedEventId(tecla($('ev'), 'ArrowDown'))).toBeNull();
        expect(keyedEventId(tecla($('otro'), 'Enter'))).toBeNull();
        expect(keyedEventId(tecla($('enlace'), 'Enter'))).toBeNull();
    });
});

/**
 * El componente, con el servidor de mentira. Sin Alpine ni DOM de verdad en el `this`:
 * se le cuelgan a mano los magics que Alpine daría. Lo que Alpine hace con el scope
 * lo cubre alpine-scope.test.js.
 */
describe('KoreCalendarInteraction: pulsar un evento', () => {
    let comp;
    let clickEvent;

    beforeEach(() => {
        document.body.innerHTML = evento('data-clickable');
        clickEvent = vi.fn().mockResolvedValue(undefined);

        comp = KoreCalendarInteraction({ snap: 900 });
        comp.$wire = { clickEvent };
        comp.$nextTick = (fn) => fn();
        comp.$root = $('root');
    });

    const clic = (target) => ({ target, preventDefault: vi.fn(), stopPropagation: vi.fn() });

    it('un clic llama a clickEvent con el id del evento', () => {
        comp.onClickCapture(clic($('titulo')));

        expect(clickEvent).toHaveBeenCalledTimes(1);
        expect(clickEvent).toHaveBeenCalledWith('7');
    });

    it('un clic fuera de un evento, o en uno sin clic o en un enlace, no llama al servidor', () => {
        for (const id of ['root', 'otro', 'enlace', 'asa']) comp.onClickCapture(clic($(id)));

        expect(clickEvent).not.toHaveBeenCalled();
    });

    it('el clic que sigue a un arrastre no abre nada y se traga', () => {
        comp._suppressClick = true;
        const e = clic($('ev'));

        comp.onClickCapture(e);

        expect(clickEvent).not.toHaveBeenCalled();
        expect(e.preventDefault).toHaveBeenCalled();
        expect(e.stopPropagation).toHaveBeenCalled();
    });

    it('un clic con un evento agarrado con el teclado no abre nada', () => {
        comp.keyboard = { event: { id: '7' } };

        comp.onClickCapture(clic($('ev')));

        expect(clickEvent).not.toHaveBeenCalled();
    });

    it('mientras hay un viaje en curso no se lanza otro (un doble clic no abre dos formularios)', async () => {
        let acaba;
        clickEvent.mockReturnValue(new Promise((resolve) => { acaba = resolve; }));

        comp.onClickCapture(clic($('ev')));
        comp.onClickCapture(clic($('ev')));

        expect(clickEvent).toHaveBeenCalledTimes(1);
        expect(comp.pending).toBe(true);

        acaba();
        await Promise.resolve();
        await Promise.resolve();

        expect(comp.pending).toBe(false);

        comp.onClickCapture(clic($('ev')));
        expect(clickEvent).toHaveBeenCalledTimes(2);
    });

    it('un fallo de red no deja el componente bloqueado', async () => {
        clickEvent.mockRejectedValue(new Error('offline'));

        await comp.openEvent('7');

        expect(comp.pending).toBe(false);
    });

    it('Intro abre el evento enfocado y no deja que el navegador haga su cosa', () => {
        const e = { ...tecla($('ev'), 'Enter'), preventDefault: vi.fn() };

        comp.onKeydown(e);

        expect(clickEvent).toHaveBeenCalledTimes(1);
        expect(clickEvent).toHaveBeenCalledWith('7');
        expect(e.preventDefault).toHaveBeenCalled();
    });

    it('Espacio en un evento movible lo agarra: no abre nada', () => {
        document.body.innerHTML = evento('data-clickable data-movable data-start="3600" data-end="7200" data-all-day="0" data-title="Reunión"');
        comp.$root = $('root');
        comp.$wire = { clickEvent };

        const e = { ...tecla($('ev'), ' '), preventDefault: vi.fn() };
        comp.onKeydown(e);

        expect(comp.keyboard).not.toBeNull();
        expect(clickEvent).not.toHaveBeenCalled();
    });

    it('con un evento agarrado, Intro lo suelta y no abre nada', () => {
        document.body.innerHTML = evento('data-clickable data-movable');
        comp.$root = $('root');
        comp.keyboard = { event: { id: '7', start: 0, end: 3600, allDay: false, resource: null }, start: 0, end: 3600, allDay: false, resource: null };
        comp.commit = vi.fn();

        comp.onKeydown({ ...tecla($('ev'), 'Enter'), preventDefault: vi.fn() });

        expect(comp.commit).toHaveBeenCalledTimes(1);
        expect(clickEvent).not.toHaveBeenCalled();
    });
});

/**
 * Y montado con Alpine DE VERDAD, que es lo único que ve la fuga de scope
 * (alpine-scope.test.js explica cuál): `pending` se escribe al pulsar un evento, y si
 * no estuviera declarada acabaría en el x-data del layout, compartido con todo.
 */
describe('KoreCalendarInteraction montado con Alpine: pulsar un evento', () => {
    let Alpine;
    let clickEvent;

    beforeAll(async () => {
        delete window.Alpine;

        Alpine = (await import('alpinejs')).default;
        Alpine.data('KoreCalendarInteraction', KoreCalendarInteraction);
        // Livewire registra `$wire` como magic; aquí, uno de mentira que lee el `clickEvent` del test.
        Alpine.magic('wire', () => ({ clickEvent: (...args) => clickEvent(...args) }));

        Alpine.start();
    });

    beforeEach(async () => {
        clickEvent = vi.fn().mockResolvedValue(undefined);

        document.body.innerHTML = `
            <div id="layout" x-data="{ sidebarOpen: false }">
                <div id="cal" x-data="KoreCalendarInteraction({ snap: 900 })"
                     x-on:click.capture="onClickCapture($event)" x-on:keydown="onKeydown($event)">
                    <div id="ev" data-kore-event="7" data-clickable tabindex="0">Reunión</div>
                    <div data-kore-calendar-overlay></div>
                </div>
            </div>`;

        await new Promise((resolve) => setTimeout(resolve, 0));
    });

    const layoutKeys = () => Object.keys(document.getElementById('layout')._x_dataStack[0]);

    it('un clic llama al servidor y no escribe nada fuera del componente', async () => {
        $('ev').click();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(clickEvent).toHaveBeenCalledTimes(1);
        expect(clickEvent).toHaveBeenCalledWith('7');
        expect(Alpine.$data($('cal')).pending).toBe(false);
        expect(layoutKeys()).toEqual(['sidebarOpen']);
    });

    it('Intro sobre el evento enfocado también', async () => {
        $('ev').dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(clickEvent).toHaveBeenCalledTimes(1);
        expect(clickEvent).toHaveBeenCalledWith('7');
        expect(layoutKeys()).toEqual(['sidebarOpen']);
    });
});
