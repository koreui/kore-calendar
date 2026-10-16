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
 * funciona (`drag.test.js` es así). Sólo se ve con Alpine de verdad (el último bloque de este
 * archivo) o mirando el código (el primero).
 *
 * La regla, entonces: **toda propiedad que un componente se asigne a sí mismo tiene que estar
 * declarada en el objeto que devuelve la factoría**, aunque sea con `null`. Incluidas las
 * privadas (`_algo`): el guion bajo es una convención de estilo, no un escondite.
 */

// `new URL(...)` sería el de jsdom, que fileURLToPath no acepta: se resuelve con la ruta del archivo.
const JS_DIR = resolve(dirname(fileURLToPath(import.meta.url)), '../../resources/js');

// Todo `resources/js` salvo lo que no es un componente: el entry y la matemática pura.
const NO_COMPONENTES = ['index.js', 'geometry.js'];

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
        expect(FILES).toContain('resources/js/interaction/drag.js');
        expect(FILES).not.toContain('resources/js/index.js');
        expect(FILES).not.toContain('resources/js/interaction/geometry.js');
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
 * `drag.test.js` le pasa un objeto plano como `this` y por eso es incapaz de ver la fuga.
 * Aquí se monta lo que la provoca: dos calendarios anidados dentro de otro x-data (en una
 * app de verdad —un layout con `x-data="{ sidebarOpen: false }"`— nunca son el más externo),
 * y se hace un gesto completo en cada uno llamando a los métodos por el Proxy de Alpine.
 */
describe('KoreCalendarDrag montado con Alpine', () => {
    const RECT = { left: 0, top: 0, width: 400, height: 600 };
    const rejilla = (id) => document.querySelector(`#${id} [x-ref="grid"]`);
    let Alpine;
    let moveEvent;

    const calendario = (id) => `
        <div id="${id}" x-data="KoreCalendarDrag({ columns: 4, axisMinutes: 600, snapMinutes: 15 })">
            <div x-ref="grid">
                <div class="sombra" x-bind:style="shadowStyle" style="display: none"></div>
            </div>
        </div>`;

    const dataOf = (id) => Alpine.$data(document.getElementById(id));
    const pointer = (clientX, clientY) => ({ clientX, clientY, button: 0, preventDefault() {} });

    // Alpine se arranca UNA vez por archivo: vive en node_modules, así que `vi.resetModules()`
    // no lo reinicia, y un segundo `Alpine.start()` avisa y duplica el observador de mutaciones.
    // Después de arrancar, Alpine inicializa solo cada nodo nuevo que entra en el documento.
    beforeAll(async () => {
        delete window.Alpine;

        Alpine = (await import('alpinejs')).default;
        const KoreCalendarDrag = (await import('../../resources/js/interaction/drag.js')).default;

        Alpine.data('KoreCalendarDrag', KoreCalendarDrag);
        // Livewire registra `$wire` como magic; aquí, uno de mentira que lee el `moveEvent` del test.
        Alpine.magic('wire', () => ({ moveEvent }));

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

        // jsdom no maqueta: la rejilla mide 0 x 0 salvo que se le diga otra cosa.
        rejilla('cal-1').getBoundingClientRect = () => RECT;
        rejilla('cal-2').getBoundingClientRect = () => RECT;

        await new Promise((resolve) => setTimeout(resolve, 0));
    });

    it('cada calendario guarda SU gesto, no el del último que empezó', () => {
        dataOf('cal-1').startDrag(pointer(150, 100), 'a', 1, 60, 60);
        dataOf('cal-2').startDrag(pointer(250, 200), 'b', 2, 120, 30);

        expect(dataOf('cal-1').drag.id).toBe('a');
        expect(dataOf('cal-2').drag.id).toBe('b');
        expect(dataOf('cal-1')._origin.x).toBe(150);
        expect(dataOf('cal-2')._origin.x).toBe(250);
    });

    it('un gesto completo no escribe nada en el x-data del layout', async () => {
        const cal = dataOf('cal-1');

        cal.startDrag(pointer(150, 100), 'a', 1, 60, 60);
        cal.moveDrag(pointer(250, 130));
        await cal.endDrag();
        cal.startDrag(pointer(150, 100), 'a', 1, 60, 60);
        cal.cancelDrag();

        // Si `drag`, `pending`, `announcement` o `_origin` aparecen aquí, la fuga ha vuelto.
        // Se lee el objeto de datos crudo, no el $data: el Proxy que fusiona los scopes no
        // expone descriptores, así que Object.keys() sobre él siempre devuelve [].
        const layout = document.getElementById('layout')._x_dataStack[0];

        expect(Object.keys(layout)).toEqual(['sidebarOpen']);
    });

    it('soltar en un calendario no toca al otro', async () => {
        dataOf('cal-2').startDrag(pointer(250, 200), 'b', 2, 120, 30);

        const cal = dataOf('cal-1');
        cal.startDrag(pointer(150, 100), 'a', 1, 60, 60);
        cal.moveDrag(pointer(250, 130));
        await cal.endDrag();

        expect(moveEvent).toHaveBeenCalledTimes(1);
        expect(moveEvent).toHaveBeenCalledWith('a', 2, 90);
        expect(dataOf('cal-1').drag).toBeNull();
        expect(dataOf('cal-1').announcement).toBe('Evento movido');
        // El otro sigue agarrado, sin anuncio y sin esperar.
        expect(dataOf('cal-2').drag.id).toBe('b');
        expect(dataOf('cal-2').announcement).toBe('');
        expect(dataOf('cal-2').pending).toBe(false);
    });

    it('la sombra sigue al gesto a través de la reactividad de Alpine', async () => {
        const sombra = (id) => document.querySelector(`#${id} .sombra`);

        expect(sombra('cal-1').style.display).toBe('none');

        const cal = dataOf('cal-1');
        cal.startDrag(pointer(150, 100), 'a', 1, 60, 60);
        cal.moveDrag(pointer(250, 130)); // columna 2, 60 + 30 = 90 minutos
        await Alpine.nextTick();

        expect(sombra('cal-1').style.display).toBe('');
        expect(sombra('cal-1').style.left).toBe('50%');
        expect(sombra('cal-1').style.top).toBe('15%');
        // El otro calendario no se entera.
        expect(sombra('cal-2').style.display).toBe('none');

        cal.cancelDrag();
        await Alpine.nextTick();

        expect(sombra('cal-1').style.display).toBe('none');
    });
});
