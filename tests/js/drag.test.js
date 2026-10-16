import { beforeEach, describe, expect, it, vi } from 'vitest';
import KoreCalendarDrag from '../../resources/js/interaction/drag.js';

/**
 * La lógica de la factoría `KoreCalendarDrag`, sin Alpine ni DOM.
 *
 * El componente se usa como un objeto normal: se le cuelgan a mano los magics que
 * Alpine daría (`$refs`, `$wire`) y se llaman sus métodos con `this` = el objeto.
 * Eso basta para la lógica del gesto. Lo que NO ve (la fuga de scope de Alpine)
 * lo cubre `alpine-scope.test.js`.
 *
 * Geometría de los tests: rejilla de 400 x 600 px en (100, 50), 4 columnas de
 * 100 px y un eje de 600 minutos → 1 px de alto = 1 minuto. Paso de 15.
 */

const RECT = { left: 100, top: 50, width: 400, height: 600 };

/** Una promesa que se resuelve o se rechaza cuando el test lo decide. */
function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => {
        resolve = res;
        reject = rej;
    });

    return { promise, resolve, reject };
}

function setup(config = {}, moveEvent = vi.fn().mockResolvedValue({ message: 'ok' })) {
    const comp = KoreCalendarDrag({ columns: 4, axisMinutes: 600, snapMinutes: 15, ...config });

    comp.$refs = { grid: { getBoundingClientRect: () => RECT } };
    comp.$wire = { moveEvent };

    return { comp, moveEvent };
}

/** Un PointerEvent de mentira con lo único que lee el componente. */
const pointer = (clientX, clientY, button = 0) => ({ clientX, clientY, button, preventDefault: vi.fn() });

// Evento 'e1' en la columna 1, empieza en el minuto 60 y dura 60. Se agarra en
// (250, 150): dentro de la columna 1 (x entre 200 y 300).
const AGARRE = { x: 250, y: 150 };

function agarrar(comp, id = 'e1') {
    const ev = pointer(AGARRE.x, AGARRE.y);
    comp.startDrag(ev, id, 1, 60, 60);

    return ev;
}

describe('valores por defecto', () => {
    it('sin configuración usa una semana de 24 horas con paso de 15', () => {
        const comp = KoreCalendarDrag();

        expect(comp.method).toBe('moveEvent');
        expect(comp.columns).toBe(7);
        expect(comp.axisMinutes).toBe(1440);
        expect(comp.snapMinutes).toBe(15);
        expect(comp.minDistance).toBe(5);
        expect(comp.drag).toBeNull();
        expect(comp.pending).toBe(false);
        expect(comp.announcement).toBe('');
    });

    it('respeta lo que llega de la configuración', () => {
        const comp = KoreCalendarDrag({ method: 'mover', columns: 5, axisMinutes: 600, snapMinutes: 30, minDistance: 8 });

        expect([comp.method, comp.columns, comp.axisMinutes, comp.snapMinutes, comp.minDistance])
            .toEqual(['mover', 5, 600, 30, 8]);
    });

    it('cada llamada devuelve un objeto nuevo: dos calendarios no comparten estado', () => {
        const a = KoreCalendarDrag();
        const b = KoreCalendarDrag();
        a.drag = { id: 1 };

        expect(b.drag).toBeNull();
    });
});

describe('iniciar el gesto', () => {
    it('el botón principal inicia el gesto y evita el comportamiento por defecto', () => {
        const { comp } = setup();
        const ev = agarrar(comp);

        expect(comp.drag).toEqual({
            id: 'e1',
            column: 1,
            start: 60,
            duration: 60,
            originColumn: 1,
            originStart: 60,
            moved: false,
        });
        expect(ev.preventDefault).toHaveBeenCalledOnce();
    });

    it.each([1, 2])('el botón %i (central o derecho) no inicia ningún gesto', (button) => {
        const { comp } = setup();
        const ev = pointer(AGARRE.x, AGARRE.y, button);

        comp.startDrag(ev, 'e1', 1, 60, 60);

        expect(comp.drag).toBeNull();
        // Sin gesto, el menú contextual del navegador tiene que seguir funcionando.
        expect(ev.preventDefault).not.toHaveBeenCalled();
    });

    it('un clic derecho en mitad de un gesto tampoco lo toca', () => {
        const { comp } = setup();
        agarrar(comp);

        comp.startDrag(pointer(0, 0, 2), 'e2', 3, 0, 30);

        expect(comp.drag.id).toBe('e1');
    });
});

describe('umbral de distancia', () => {
    it('menos de 5 px es un clic: no marca movimiento ni llama al servidor', async () => {
        const { comp, moveEvent } = setup();
        agarrar(comp);

        // hypot(3, 4) = 5 es justo el umbral; aquí hypot(3, 3) ≈ 4,24.
        comp.moveDrag(pointer(AGARRE.x + 3, AGARRE.y + 3));

        expect(comp.drag.moved).toBe(false);
        expect(comp.drag.column).toBe(1);
        expect(comp.drag.start).toBe(60);

        await comp.endDrag();

        expect(moveEvent).not.toHaveBeenCalled();
        expect(comp.drag).toBeNull();
        expect(comp.pending).toBe(false);
    });

    it('a 5 px justos ya es un arrastre', () => {
        const { comp } = setup();
        agarrar(comp);

        comp.moveDrag(pointer(AGARRE.x + 3, AGARRE.y + 4));

        expect(comp.drag.moved).toBe(true);
    });

    it('respeta un umbral distinto de la configuración', () => {
        const { comp } = setup({ minDistance: 20 });
        agarrar(comp);

        comp.moveDrag(pointer(AGARRE.x, AGARRE.y + 19));
        expect(comp.drag.moved).toBe(false);

        comp.moveDrag(pointer(AGARRE.x, AGARRE.y + 20));
        expect(comp.drag.moved).toBe(true);
    });

    it('una vez superado, volver cerca del origen sigue siendo un arrastre', () => {
        const { comp } = setup();
        agarrar(comp);

        comp.moveDrag(pointer(AGARRE.x, AGARRE.y + 100));
        comp.moveDrag(pointer(AGARRE.x, AGARRE.y + 1));

        expect(comp.drag.moved).toBe(true);
        expect(comp.drag.start).toBe(60); // 61 minutos, ajustado a 60
    });

    it('mover sin gesto iniciado no hace nada ni lanza', () => {
        const { comp } = setup();

        expect(() => comp.moveDrag(pointer(10, 10))).not.toThrow();
        expect(comp.drag).toBeNull();
    });
});

describe('soltar sin cambio', () => {
    it('soltar en el mismo sitio tras un arrastre largo no llama al servidor', async () => {
        const { comp, moveEvent } = setup();
        agarrar(comp);

        comp.moveDrag(pointer(AGARRE.x, AGARRE.y + 200)); // se aleja…
        comp.moveDrag(pointer(AGARRE.x, AGARRE.y));       // …y vuelve al punto de agarre.

        expect(comp.drag.moved).toBe(true);
        expect(comp.drag.start).toBe(60);
        expect(comp.drag.column).toBe(1);

        await comp.endDrag();

        expect(moveEvent).not.toHaveBeenCalled();
        expect(comp.drag).toBeNull();
    });

    it('moverse dentro de la misma columna y por debajo del paso tampoco cuenta', async () => {
        const { comp, moveEvent } = setup();
        agarrar(comp);

        // 6 px a la derecha (supera el umbral), misma columna, misma hora tras el snap.
        comp.moveDrag(pointer(AGARRE.x + 6, AGARRE.y + 3));

        expect(comp.drag.moved).toBe(true);
        expect(comp.drag.column).toBe(1);
        expect(comp.drag.start).toBe(60);

        await comp.endDrag();

        expect(moveEvent).not.toHaveBeenCalled();
    });
});

describe('mover', () => {
    it('llama a $wire.moveEvent(id, columna, inicio) una sola vez con valores ajustados', async () => {
        const { comp, moveEvent } = setup();
        agarrar(comp);

        // Varios movimientos intermedios: solo cuenta el último.
        comp.moveDrag(pointer(AGARRE.x + 40, AGARRE.y + 10));
        comp.moveDrag(pointer(AGARRE.x + 120, AGARRE.y + 25));
        // clientX 450 → 350 px dentro de la rejilla (empieza en 100) → columna 3.
        // dy = 37 → 60 + 37 = 97 minutos → snap a 90.
        comp.moveDrag(pointer(450, AGARRE.y + 37));

        expect(moveEvent).not.toHaveBeenCalled(); // durante el gesto no hay red

        await comp.endDrag();

        expect(moveEvent).toHaveBeenCalledTimes(1);
        expect(moveEvent).toHaveBeenCalledWith('e1', 3, 90);
        expect(comp.drag).toBeNull();
        expect(comp.pending).toBe(false);
    });

    it('usa el nombre de método de la configuración', async () => {
        const mover = vi.fn().mockResolvedValue(null);
        const { comp } = setup({ method: 'mover' });
        comp.$wire = { mover, moveEvent: vi.fn() };
        agarrar(comp);

        comp.moveDrag(pointer(AGARRE.x + 100, AGARRE.y));
        await comp.endDrag();

        expect(mover).toHaveBeenCalledWith('e1', 2, 60);
        expect(comp.$wire.moveEvent).not.toHaveBeenCalled();
    });

    it('las coordenadas se miden contra la rejilla, no contra la ventana', () => {
        const { comp } = setup();
        agarrar(comp);

        // clientX 100 es el borde izquierdo de la rejilla → columna 0.
        comp.moveDrag(pointer(100, AGARRE.y + 20));
        expect(comp.drag.column).toBe(0);

        // clientX 499 es el último píxel de la rejilla → columna 3.
        comp.moveDrag(pointer(499, AGARRE.y + 20));
        expect(comp.drag.column).toBe(3);

        // Fuera de la rejilla se queda en la columna de la punta.
        comp.moveDrag(pointer(5000, AGARRE.y + 20));
        expect(comp.drag.column).toBe(3);
        comp.moveDrag(pointer(-5000, AGARRE.y + 20));
        expect(comp.drag.column).toBe(0);
    });

    it('arrastrado contra el borde, el evento se para sin encogerse', async () => {
        const { comp, moveEvent } = setup();
        agarrar(comp);

        comp.moveDrag(pointer(AGARRE.x, AGARRE.y + 5000));
        await comp.endDrag();

        // Eje 600, duración 60 → el inicio máximo es 540.
        expect(moveEvent).toHaveBeenCalledWith('e1', 1, 540);
    });

    it('un solo cambio de columna, con la misma hora, también se envía', async () => {
        const { comp, moveEvent } = setup();
        agarrar(comp);

        comp.moveDrag(pointer(AGARRE.x + 100, AGARRE.y));
        await comp.endDrag();

        expect(moveEvent).toHaveBeenCalledWith('e1', 2, 60);
    });

    it('endDrag sin gesto no hace nada', async () => {
        const { comp, moveEvent } = setup();

        await comp.endDrag();

        expect(moveEvent).not.toHaveBeenCalled();
        expect(comp.pending).toBe(false);
    });
});

describe('pending', () => {
    /** Un arrastre ya soltado, con el servidor todavía sin contestar. */
    async function enVuelo() {
        const espera = deferred();
        const { comp, moveEvent } = setup({}, vi.fn().mockReturnValue(espera.promise));
        agarrar(comp);
        comp.moveDrag(pointer(AGARRE.x + 100, AGARRE.y + 30));
        const fin = comp.endDrag();

        return { comp, moveEvent, espera, fin };
    }

    it('mientras espera, pending es true y la sombra sigue ahí', async () => {
        const { comp, espera, fin } = await enVuelo();

        expect(comp.pending).toBe(true);
        expect(comp.drag).not.toBeNull();
        expect(comp.shadowStyle).not.toBe('display: none');

        espera.resolve({});
        await fin;
    });

    it('bloquea un segundo gesto mientras espera', async () => {
        const { comp, moveEvent, espera, fin } = await enVuelo();
        const antes = { ...comp.drag };

        const ev = pointer(0, 0);
        comp.startDrag(ev, 'otro', 0, 0, 30);

        expect(comp.drag).toEqual(antes);
        expect(ev.preventDefault).not.toHaveBeenCalled();

        // Ni mover, ni soltar, ni cancelar tocan el gesto en vuelo.
        comp.moveDrag(pointer(AGARRE.x + 300, AGARRE.y + 300));
        expect(comp.drag).toEqual(antes);

        comp.cancelDrag();
        expect(comp.drag).toEqual(antes);

        await comp.endDrag();
        expect(moveEvent).toHaveBeenCalledTimes(1);

        espera.resolve({});
        await fin;
    });

    it('al contestar el servidor se libera y se puede empezar otro gesto', async () => {
        const { comp, espera, fin } = await enVuelo();

        espera.resolve({ message: 'Evento movido' });
        await fin;

        expect(comp.pending).toBe(false);
        expect(comp.drag).toBeNull();

        agarrar(comp, 'e2');
        expect(comp.drag.id).toBe('e2');
    });

    it('dos endDrag seguidos envían una sola petición', async () => {
        const { comp, moveEvent, espera, fin } = await enVuelo();

        const segundo = comp.endDrag();
        espera.resolve({});
        await Promise.all([fin, segundo]);

        expect(moveEvent).toHaveBeenCalledTimes(1);
    });
});

describe('rechazo y fallos del servidor', () => {
    it('una promesa rechazada deja drag en null y libera pending', async () => {
        const { comp, moveEvent } = setup({}, vi.fn().mockRejectedValue(new Error('Network')));
        agarrar(comp);
        comp.moveDrag(pointer(AGARRE.x + 100, AGARRE.y));

        await expect(comp.endDrag()).resolves.toBeUndefined(); // no propaga el error

        expect(moveEvent).toHaveBeenCalledOnce();
        expect(comp.drag).toBeNull();
        expect(comp.pending).toBe(false);
        expect(comp.announcement).toBe('');
    });

    it('un throw síncrono de $wire se trata igual', async () => {
        const { comp } = setup({}, vi.fn(() => { throw new Error('boom'); }));
        agarrar(comp);
        comp.moveDrag(pointer(AGARRE.x + 100, AGARRE.y));

        await expect(comp.endDrag()).resolves.toBeUndefined();

        expect(comp.drag).toBeNull();
        expect(comp.pending).toBe(false);
    });

    it('un método que no existe en $wire tampoco deja el gesto colgado', async () => {
        const { comp } = setup();
        comp.$wire = {};
        agarrar(comp);
        comp.moveDrag(pointer(AGARRE.x + 100, AGARRE.y));

        await comp.endDrag();

        expect(comp.drag).toBeNull();
        expect(comp.pending).toBe(false);
    });

    it('tras un fallo se puede empezar otro gesto', async () => {
        const { comp } = setup({}, vi.fn().mockRejectedValue(new Error('x')));
        agarrar(comp);
        comp.moveDrag(pointer(AGARRE.x + 100, AGARRE.y));
        await comp.endDrag();

        agarrar(comp, 'e2');

        expect(comp.drag.id).toBe('e2');
    });

    it('un fallo borra el anuncio anterior en vez de dejar uno falso', async () => {
        const moveEvent = vi.fn()
            .mockResolvedValueOnce({ message: 'Evento movido' })
            .mockRejectedValueOnce(new Error('x'));
        const { comp } = setup({}, moveEvent);

        agarrar(comp);
        comp.moveDrag(pointer(AGARRE.x + 100, AGARRE.y));
        await comp.endDrag();
        expect(comp.announcement).toBe('Evento movido');

        agarrar(comp);
        comp.moveDrag(pointer(AGARRE.x + 100, AGARRE.y));
        await comp.endDrag();
        expect(comp.announcement).toBe('');
    });
});

describe('announcement', () => {
    async function soltarConRespuesta(respuesta) {
        const { comp } = setup({}, vi.fn().mockResolvedValue(respuesta));
        agarrar(comp);
        comp.moveDrag(pointer(AGARRE.x + 100, AGARRE.y));
        await comp.endDrag();

        return comp;
    }

    it('toma result.message', async () => {
        expect((await soltarConRespuesta({ message: 'Evento movido a las 09:00' })).announcement)
            .toBe('Evento movido a las 09:00');
    });

    it('si el servidor no manda mensaje, queda vacío', async () => {
        expect((await soltarConRespuesta({})).announcement).toBe('');
        expect((await soltarConRespuesta(undefined)).announcement).toBe('');
        expect((await soltarConRespuesta(null)).announcement).toBe('');
    });

    it('un rechazo explícito del servidor anuncia su mensaje y limpia el gesto', async () => {
        const comp = await soltarConRespuesta({ ok: false, message: 'No se pudo mover el evento' });

        expect(comp.announcement).toBe('No se pudo mover el evento');
        expect(comp.drag).toBeNull();
        expect(comp.pending).toBe(false);
    });
});

describe('cancelar', () => {
    it('cancelDrag descarta el gesto sin llamar al servidor', async () => {
        const { comp, moveEvent } = setup();
        agarrar(comp);
        comp.moveDrag(pointer(AGARRE.x + 100, AGARRE.y + 50));

        comp.cancelDrag();

        expect(comp.drag).toBeNull();
        expect(comp.shadowStyle).toBe('display: none');

        await comp.endDrag();
        expect(moveEvent).not.toHaveBeenCalled();
    });
});

describe('isDragging', () => {
    it('solo es true para el evento que se está moviendo, y solo tras superar el umbral', () => {
        const { comp } = setup();

        expect(comp.isDragging('e1')).toBe(false);

        agarrar(comp);
        expect(comp.isDragging('e1')).toBe(false); // agarrado, aún no es un arrastre

        comp.moveDrag(pointer(AGARRE.x + 50, AGARRE.y));
        expect(comp.isDragging('e1')).toBe(true);
        expect(comp.isDragging('e2')).toBe(false);
    });
});

describe('shadowStyle', () => {
    /** Los números de un `left: 25%; width: 25%; …` como objeto. */
    const medidas = (css) => Object.fromEntries(
        [...css.matchAll(/(left|width|top|height):\s*([\d.]+)%/g)].map(([, k, v]) => [k, Number(v)]),
    );

    it('está oculta sin gesto', () => {
        const { comp } = setup();

        expect(comp.shadowStyle).toBe('display: none');
    });

    it('está oculta con el evento agarrado pero sin movimiento', () => {
        const { comp } = setup();
        agarrar(comp);

        expect(comp.shadowStyle).toBe('display: none');

        comp.moveDrag(pointer(AGARRE.x + 2, AGARRE.y + 2)); // por debajo del umbral
        expect(comp.shadowStyle).toBe('display: none');
    });

    it('al mover lleva los porcentajes de columna, inicio y duración', () => {
        const { comp } = setup();
        agarrar(comp);

        // clientX 350 → 250 px dentro de la rejilla → columna 2.
        // dy = 30 → 60 + 30 = 90 minutos, ya múltiplo de 15.
        comp.moveDrag(pointer(350, AGARRE.y + 30));

        expect(comp.drag.column).toBe(2);
        expect(comp.drag.start).toBe(90);

        const m = medidas(comp.shadowStyle);

        expect(m.left).toBeCloseTo(50);     // 2 de 4 columnas
        expect(m.width).toBeCloseTo(25);    // 1 de 4 columnas
        expect(m.top).toBeCloseTo(15);      // 90 de 600 minutos
        expect(m.height).toBeCloseTo(10);   // 60 de 600 minutos
    });

    it('el formato exacto es el que consume el x-bind:style de la vista', () => {
        const { comp } = setup();
        agarrar(comp);
        comp.moveDrag(pointer(350, AGARRE.y + 30));

        expect(comp.shadowStyle).toMatch(
            /^left: [\d.]+%; width: [\d.]+%; top: [\d.]+%; height: [\d.]+%$/,
        );
    });

    it('sigue al puntero: cambia de columna y de hora con cada movimiento', () => {
        const { comp } = setup();
        agarrar(comp);

        comp.moveDrag(pointer(150, AGARRE.y + 60)); // columna 0, inicio 120
        const a = medidas(comp.shadowStyle);
        expect(a.left).toBeCloseTo(0);
        expect(a.top).toBeCloseTo(20);

        comp.moveDrag(pointer(480, AGARRE.y - 30)); // columna 3, inicio 30
        const b = medidas(comp.shadowStyle);
        expect(b.left).toBeCloseTo(75);
        expect(b.top).toBeCloseTo(5);
    });

    it('contra el borde inferior la sombra acaba en el 100% del eje', () => {
        const { comp } = setup();
        agarrar(comp);
        comp.moveDrag(pointer(AGARRE.x, AGARRE.y + 5000));

        const m = medidas(comp.shadowStyle);

        expect(m.top + m.height).toBeCloseTo(100);
    });
});
