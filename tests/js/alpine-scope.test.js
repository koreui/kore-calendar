// @vitest-environment jsdom
import { readdirSync, readFileSync } from 'fs';
import { basename, dirname, join, resolve } from 'path';
import { fileURLToPath } from 'url';
import { parse } from 'acorn';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * La fuga de scope de Alpine.
 *
 * Alpine no llama a los métodos de un componente con `this` = el objeto del componente. Los
 * llama con `this` = un Proxy que fusiona TODA la pila de scopes: [magics, componente,
 * ...ancestros x-data]. Y su trampa `set` (alpinejs/src/scope.js) hace esto:
 *
 *     let target;
 *     for (const obj of objects) { target = keyInPrototypeChain(obj, name); if (target) break; }
 *     if (!target) target = objects[objects.length - 1];   // ← el scope MÁS EXTERNO
 *
 * O sea: si asignas `this.foo = x` y `foo` NO está declarada en el objeto del componente, la
 * propiedad NO se guarda en el componente — se guarda en el **x-data ancestro más externo de
 * la página**, que es compartido por todo lo que cuelgue de él.
 *
 * Las consecuencias son silenciosas y desagradables:
 *   - Dos instancias del mismo componente se pisan la propiedad. Gana la última en `init()`.
 *     (En el calendario: dos calendarios en la misma página compartirían `_origin`, y soltar
 *     uno usaría el punto de agarre del otro.)
 *   - Dos componentes DISTINTOS que usen el mismo nombre se pisan entre sí.
 *   - No hay ningún error. Simplemente, un componente lee los datos de otro.
 *
 * Un test unitario NO puede ver esto: si le pasas un objeto plano como `this`, la asignación
 * funciona (los tests de `click.test.js` y `keyboard.test.js` son así). Sólo se ve con Alpine de
 * verdad (el último bloque de este archivo) o mirando el código (el primero).
 *
 * La regla, entonces: **toda propiedad que un componente se asigne a sí mismo tiene que estar
 * declarada en el objeto que devuelve la factoría**, aunque sea con `null`. Incluidas las
 * privadas (`_algo`): el guion bajo es una convención de estilo, no un escondite.
 */

// `new URL(...)` sería el de jsdom, que fileURLToPath no acepta: se resuelve con la ruta del archivo.
const JS_DIR = resolve(dirname(fileURLToPath(import.meta.url)), '../../resources/js');

// Todo `resources/js` salvo lo que no es un componente: el entry. (mutation.js y click.js son
// matemática pura, pero pasan igual: no se asignan nada con `this`.)
const NO_COMPONENTES = ['index.js'];

const FILES = readdirSync(JS_DIR, { recursive: true })
    .filter((f) => f.endsWith('.js') && !NO_COMPONENTES.includes(basename(f)))
    .map((f) => join('resources/js', f))
    .sort();

/** Las claves declaradas en el objeto que devuelve la factoría del componente. */
function declaredKeys(source) {
    const ast = parse(source, { ecmaVersion: 2023, sourceType: 'module' });
    const keys = new Set();

    // Se recorre el AST entero y se recogen las claves de TODOS los literales de objeto que
    // se devuelvan (`=> ({...})` o `return {...}`). Es generoso a propósito: el objetivo es
    // no dar falsos positivos, y un falso negativo aquí lo caza el test de Alpine de abajo.
    (function walk(node) {
        if (!node || typeof node !== 'object') return;

        if (node.type === 'ObjectExpression') {
            for (const prop of node.properties) {
                if (prop.key?.name) keys.add(prop.key.name);
            }
        }

        for (const key of Object.keys(node)) {
            const child = node[key];
            if (Array.isArray(child)) child.forEach(walk);
            else if (child && typeof child.type === 'string') walk(child);
        }
    })(ast);

    return keys;
}

/** Las propiedades que el componente se asigna a sí mismo: `this.foo = ...`. */
function assignedKeys(source) {
    const ast = parse(source, { ecmaVersion: 2023, sourceType: 'module' });
    const keys = new Set();

    (function walk(node) {
        if (!node || typeof node !== 'object') return;

        if (
            node.type === 'AssignmentExpression' &&
            node.left.type === 'MemberExpression' &&
            node.left.object.type === 'ThisExpression' &&
            !node.left.computed &&
            node.left.property.name
        ) {
            keys.add(node.left.property.name);
        }

        for (const key of Object.keys(node)) {
            const child = node[key];
            if (Array.isArray(child)) child.forEach(walk);
            else if (child && typeof child.type === 'string') walk(child);
        }
    })(ast);

    return keys;
}

/** Lo que se asigna y no está declarado (menos los magics `$…`, que Alpine inyecta). */
const leakedKeys = (source) => {
    const declared = declaredKeys(source);

    return [...assignedKeys(source)].filter((key) => !declared.has(key) && !key.startsWith('$'));
};

describe('ningún componente escribe fuera de su propio scope', () => {
    it('encuentra los componentes (si no, el resto no mira nada)', () => {
        expect(FILES).toContain('resources/js/interaction/calendar.js');
        expect(FILES).toContain('resources/js/timeline.js');
        expect(FILES).not.toContain('resources/js/index.js');
        // La prueba técnica de la fase 1 se retiró: que no vuelva a colarse en el bundle.
        expect(FILES.filter((f) => /drag|geometry/.test(f))).toEqual([]);
    });

    it.each(FILES)('%s declara todo lo que se asigna', (file) => {
        const leaked = leakedKeys(readFileSync(file, 'utf8'));

        expect(leaked, `Estas propiedades acabarían en el x-data ancestro, no en el componente. Decláralas en el objeto que devuelve la factoría (con null basta): ${leaked.join(', ')}`)
            .toEqual([]);
    });

    it('el detector ve una fuga cuando la hay', () => {
        const conFuga = `export default () => ({
            ok: null,
            init() { this.ok = 1; this._olvidada = 2; this.$el = 3; },
        });`;

        expect(leakedKeys(conFuga)).toEqual(['_olvidada']);
    });
});

/**
 * El componente, montado con Alpine DE VERDAD.
 *
 * Los tests con un objeto plano como `this` son incapaces de ver la fuga. Aquí se monta lo que
 * la provoca: dos calendarios anidados dentro de otro x-data (en una app de verdad —un layout con
 * `x-data="{ sidebarOpen: false }"`— nunca son el más externo), y se hace un gesto completo con el
 * teclado en cada uno llamando a los métodos por el Proxy de Alpine. Con el teclado y no con el
 * puntero porque jsdom no maqueta (`elementsFromPoint` no encuentra destinos); el estado que se
 * asigna (`keyboard`, `pending`, `announcement`, `gesture`…) es el mismo.
 */
describe('KoreCalendarInteraction montado con Alpine', () => {
    let Alpine;
    let moveEvent;

    const calendario = (id) => `
        <div id="${id}" x-data="KoreCalendarInteraction({ snap: 900, texts: { grabbed: 'Agarrado.' } })">
            <div id="${id}-ev" data-kore-event="${id}-ev" data-movable data-title="Reunión ${id}"
                 data-start="3600" data-end="7200" data-all-day="0" tabindex="0"></div>
            <div data-kore-calendar-overlay></div>
            <p class="anuncio" x-text="announcement"></p>
        </div>`;

    const dataOf = (id) => Alpine.$data(document.getElementById(id));
    const tecla = (id, key) => ({ key, target: document.getElementById(`${id}-ev`), shiftKey: false, preventDefault() {} });

    // Alpine se arranca UNA vez por archivo: vive en node_modules, así que `vi.resetModules()`
    // no lo reinicia, y un segundo `Alpine.start()` avisa y duplica el observador de mutaciones.
    // Después de arrancar, Alpine inicializa solo cada nodo nuevo que entra en el documento.
    beforeAll(async () => {
        delete window.Alpine;
        // Tras soltar, el componente devuelve el foco al evento con CSS.escape, que jsdom no trae.
        globalThis.CSS ??= { escape: (valor) => String(valor) };

        Alpine = (await import('alpinejs')).default;
        const KoreCalendarInteraction = (await import('../../resources/js/interaction/calendar.js')).default;

        Alpine.data('KoreCalendarInteraction', KoreCalendarInteraction);
        // Livewire registra `$wire` como magic; aquí, uno de mentira que lee el `moveEvent` del test.
        Alpine.magic('wire', () => ({ moveEvent: (...args) => moveEvent(...args) }));

        Alpine.start();
    });

    beforeEach(async () => {
        moveEvent = vi.fn().mockResolvedValue({ message: 'Evento movido' });

        // ⚠️ Lo que provoca la fuga: los calendarios NO son el x-data más externo.
        document.body.innerHTML = `
            <div id="layout" x-data="{ sidebarOpen: false }">
                ${calendario('cal-1')}
                ${calendario('cal-2')}
            </div>`;

        await new Promise((resolve) => setTimeout(resolve, 0));
    });

    it('cada calendario guarda SU evento agarrado, no el del último que empezó', () => {
        dataOf('cal-1').onKeydown(tecla('cal-1', ' '));
        dataOf('cal-2').onKeydown(tecla('cal-2', ' '));
        dataOf('cal-1').onKeydown(tecla('cal-1', 'ArrowRight'));

        expect(dataOf('cal-1').keyboard.event.id).toBe('cal-1-ev');
        expect(dataOf('cal-2').keyboard.event.id).toBe('cal-2-ev');
        // El movimiento de uno no se ve en el otro.
        expect(dataOf('cal-1').keyboard.start).toBe(3600 + 86400);
        expect(dataOf('cal-2').keyboard.start).toBe(3600);
    });

    it('un gesto completo no escribe nada en el x-data del layout', async () => {
        const cal = dataOf('cal-1');

        cal.onKeydown(tecla('cal-1', ' '));
        cal.onKeydown(tecla('cal-1', 'ArrowDown'));
        cal.onKeydown(tecla('cal-1', 'Enter'));
        await vi.waitFor(() => expect(cal.pending).toBe(false));
        cal.onKeydown(tecla('cal-1', ' '));
        cal.onKeydown(tecla('cal-1', 'Escape'));

        // Si `keyboard`, `pending`, `announcement` o `gesture` aparecen aquí, la fuga ha vuelto.
        // Se lee el objeto de datos crudo, no el $data: el Proxy que fusiona los scopes no
        // expone descriptores, así que Object.keys() sobre él siempre devuelve [].
        const layout = document.getElementById('layout')._x_dataStack[0];

        expect(Object.keys(layout)).toEqual(['sidebarOpen']);
    });

    it('soltar en un calendario no toca al otro', async () => {
        dataOf('cal-2').onKeydown(tecla('cal-2', ' '));

        const cal = dataOf('cal-1');
        cal.onKeydown(tecla('cal-1', ' '));
        cal.onKeydown(tecla('cal-1', 'ArrowRight'));
        cal.onKeydown(tecla('cal-1', 'Enter'));
        await vi.waitFor(() => expect(cal.pending).toBe(false));

        expect(moveEvent).toHaveBeenCalledTimes(1);
        expect(moveEvent).toHaveBeenCalledWith('cal-1-ev', 3600 + 86400, false, null, null);
        expect(dataOf('cal-1').keyboard).toBeNull();
        expect(dataOf('cal-1').announcement).toBe('Evento movido');
        // El otro sigue agarrado, con su propio anuncio (el de agarrar) y sin esperar.
        expect(dataOf('cal-2').keyboard.event.id).toBe('cal-2-ev');
        expect(dataOf('cal-2').announcement).not.toContain('Evento movido');
        expect(dataOf('cal-2').pending).toBe(false);
    });

    it('el anuncio llega al DOM a través de la reactividad de Alpine, solo al calendario que lo dijo', async () => {
        const anuncio = (id) => document.querySelector(`#${id} .anuncio`).textContent;

        expect(anuncio('cal-1')).toBe('');

        dataOf('cal-1').onKeydown(tecla('cal-1', ' '));
        await Alpine.nextTick();
        await Alpine.nextTick();

        expect(anuncio('cal-1')).toContain('Reunión cal-1');
        expect(anuncio('cal-1')).toContain('Agarrado.');
        // El otro calendario no se entera.
        expect(anuncio('cal-2')).toBe('');
    });
});
