// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest';
import KoreCalendarInteraction, { readEvent } from '../../resources/js/interaction/calendar.js';
import { clickedEventId, keyedEventId } from '../../resources/js/interaction/click.js';

/**
 * Las ocurrencias de una serie.
 *
 * Todas comparten `data-kore-event` (el id del evento, que es el de la serie), así que el
 * servidor no sabría cuál se tocó. Lo que se le manda es `data-kore-instance`: moveEvent,
 * resizeEvent y clickEvent reciben ESE valor. Sin él (HTML anterior a las series), el id.
 *
 * Y si el servidor responde `needsScope` (abrió el diálogo de alcance, no guardó nada), el
 * cliente no anuncia nada y quita la previsualización.
 */

const DAY = 86400;

// jsdom no trae `CSS.escape` (todos los navegadores sí): lo que el componente usa para devolver el foco.
globalThis.CSS ??= { escape: (value) => String(value).replace(/([^\w-])/g, '\\$1') };

// Dos ocurrencias de la serie «s», un evento normal y un evento sin instanceId.
const html = `
    <div id="root">
        <div data-resource="a">
            <div id="lun" data-kore-event="s" data-kore-instance="s:1000" data-title="Standup" data-start="1000" data-end="2800" data-all-day="0" data-movable data-resizable data-clickable tabindex="0">
                <span id="titulo-lun">Standup</span>
            </div>
            <div id="mar" data-kore-event="s" data-kore-instance="s:87400" data-title="Standup" data-start="87400" data-end="89200" data-all-day="0" data-movable data-resizable data-clickable tabindex="0"></div>
        </div>
        <div id="viejo" data-kore-event="7" data-title="Viejo" data-start="500" data-end="900" data-all-day="0" data-movable data-clickable tabindex="0"></div>
        <div data-kore-calendar-overlay></div>
    </div>`;

const $ = (id) => document.getElementById(id);
const tecla = (target, key, extra = {}) => ({ key, target, repeat: false, altKey: false, ctrlKey: false, metaKey: false, shiftKey: false, preventDefault: vi.fn(), ...extra });

describe('qué id se manda', () => {
    beforeEach(() => { document.body.innerHTML = html; });

    it('readEvent lee el instanceId como id, y conserva el id del evento aparte', () => {
        expect(readEvent($('mar'))).toMatchObject({ id: 's:87400', eventId: 's', start: 87400, end: 89200 });
    });

    it('sin data-kore-instance, el id del evento: el HTML de antes de las series sigue valiendo', () => {
        expect(readEvent($('viejo'))).toMatchObject({ id: '7', eventId: '7' });
    });

    it('un clic o Intro en una ocurrencia dan su instanceId, no el id de la serie', () => {
        expect(clickedEventId($('mar'))).toBe('s:87400');
        expect(clickedEventId($('titulo-lun'))).toBe('s:1000');
        expect(keyedEventId(tecla($('lun'), 'Enter'))).toBe('s:1000');
        // Y uno sin instanceId, el id.
        expect(clickedEventId($('viejo'))).toBe('7');
    });
});

describe('KoreCalendarInteraction con ocurrencias, con el servidor de mentira', () => {
    let comp;
    let moveEvent;
    let resizeEvent;
    let clickEvent;

    beforeEach(() => {
        document.body.innerHTML = html;
        moveEvent = vi.fn().mockResolvedValue({ ok: true, message: 'Movido' });
        resizeEvent = vi.fn().mockResolvedValue({ ok: true, message: 'Redimensionado' });
        clickEvent = vi.fn().mockResolvedValue(undefined);

        comp = KoreCalendarInteraction({ snap: 900 });
        comp.$wire = { moveEvent, resizeEvent, clickEvent };
        comp.$nextTick = (fn) => fn();
        comp.$root = $('root');
        comp.say = vi.fn();
        comp.draw = vi.fn();
    });

    /**
     * Agarra la ocurrencia con Espacio, la mueve un día con → y la suelta con Intro. Agarrar
     * y mover también se anuncian: el spy se vacía antes de soltar, que es lo que se prueba.
     */
    const soltar = async (id, ...teclas) => {
        comp.onKeydown(tecla($(id), ' '));
        for (const t of teclas) comp.onKeydown(tecla($(id), t.key, t.extra));
        comp.say.mockClear();
        comp.onKeydown(tecla($(id), 'Enter'));
        await vi.waitFor(() => expect(comp.pending).toBe(false));
    };
    const moverUnDia = (id) => soltar(id, { key: 'ArrowRight' });
    const alargar = (id) => soltar(id, { key: 'ArrowDown', extra: { shiftKey: true } });

    it('moveEvent recibe el instanceId de la ocurrencia que se movió, no el id de la serie', async () => {
        await moverUnDia('mar');

        expect(moveEvent).toHaveBeenCalledTimes(1);
        expect(moveEvent).toHaveBeenCalledWith('s:87400', 87400 + DAY, false, 'a', 'a');
    });

    it('resizeEvent recibe el instanceId (Mayúsculas + ↓ cambia el fin)', async () => {
        await alargar('lun');

        expect(resizeEvent).toHaveBeenCalledTimes(1);
        expect(resizeEvent).toHaveBeenCalledWith('s:1000', 2800 + 900, false);
    });

    it('clickEvent recibe el instanceId', async () => {
        comp.onClickCapture({ target: $('titulo-lun'), preventDefault: vi.fn(), stopPropagation: vi.fn() });
        await vi.waitFor(() => expect(comp.pending).toBe(false));

        expect(clickEvent).toHaveBeenCalledWith('s:1000');
    });

    it('un evento sin instanceId sigue mandando su id', async () => {
        await moverUnDia('viejo');

        expect(moveEvent).toHaveBeenCalledWith('7', 500 + DAY, false, null, null);
    });

    it('un resultado normal se anuncia', async () => {
        await moverUnDia('mar');

        expect(comp.say).toHaveBeenCalledWith('Movido');
    });

    it('needsScope: el servidor abrió el diálogo y no guardó nada, así que no se anuncia nada', async () => {
        moveEvent.mockResolvedValue({ ok: false, needsScope: true, message: '' });

        await moverUnDia('mar');

        expect(moveEvent).toHaveBeenCalledTimes(1);
        expect(comp.say).not.toHaveBeenCalled();
        // La previsualización se quita y el componente queda libre para el siguiente gesto.
        expect(comp.draw).toHaveBeenLastCalledWith(null);
        expect(comp.keyboard).toBeNull();
        expect(comp.gesture).toBeNull();
        expect(comp.pending).toBe(false);
    });

    it('needsScope no se anuncia aunque el servidor mande un texto', async () => {
        resizeEvent.mockResolvedValue({ ok: false, needsScope: true, message: 'Algo' });

        await alargar('lun');

        expect(comp.say).not.toHaveBeenCalled();
    });

    it('un rechazo normal sí se anuncia: es el motivo', async () => {
        moveEvent.mockResolvedValue({ ok: false, message: 'Se solapa con «Comida»' });

        await moverUnDia('mar');

        expect(comp.say).toHaveBeenCalledWith('Se solapa con «Comida»');
    });

    it('con el teclado, el foco vuelve a la ocurrencia por su instanceId aunque comparta id con otras', async () => {
        const foco = vi.spyOn($('mar'), 'focus');
        const otra = vi.spyOn($('lun'), 'focus');

        await moverUnDia('mar');

        expect(foco).toHaveBeenCalledTimes(1);
        expect(otra).not.toHaveBeenCalled();
    });

    it('si el instanceId cambió con el morph (uno normal que se movió), el foco vuelve por el id del evento', async () => {
        const foco = vi.spyOn($('viejo'), 'focus');
        // Tras el morph el nodo ya no tiene el instanceId de antes.
        moveEvent.mockImplementation(async () => {
            $('viejo').setAttribute('data-kore-instance', '7:99999');

            return { ok: true, message: 'Movido' };
        });
        // El evento se agarró con un instanceId que ya no existe.
        $('viejo').setAttribute('data-kore-instance', '7:500');

        await moverUnDia('viejo');

        expect(moveEvent).toHaveBeenCalledWith('7:500', 500 + DAY, false, null, null);
        expect(foco).toHaveBeenCalledTimes(1);
    });
});
