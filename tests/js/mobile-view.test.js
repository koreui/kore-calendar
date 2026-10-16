import { afterEach, describe, expect, it, vi } from 'vitest';
import KoreCalendarMobileView, { NARROW, applyMobileView, mobileViewTarget } from '../../resources/js/mobile-view.js';

/** Un `window` mínimo: el ancho (`narrow`), la URL y un `replace` que se puede espiar. */
function fakeWindow({ narrow = true, url = 'https://app.test/calendario' } = {}) {
    const location = new URL(url);

    return {
        matchMedia: vi.fn((query) => ({ matches: query === NARROW && narrow })),
        location: {
            search: location.search,
            href: location.href,
            replace: vi.fn(),
        },
    };
}

afterEach(() => vi.unstubAllGlobals());

describe('mobileViewTarget', () => {
    const base = { view: 'day', current: 'month', param: 'view', search: '', narrow: true };

    it('sin ?view= en la URL y con la pantalla estrecha, cambia a la vista móvil', () => {
        expect(mobileViewTarget(base)).toBe('day');
    });

    it('con ?view= en la URL no cambia: la elección explícita manda, sea cual sea', () => {
        expect(mobileViewTarget({ ...base, search: '?view=month' })).toBeNull();
        expect(mobileViewTarget({ ...base, search: '?date=2026-10-01&view=week' })).toBeNull();
        // Aunque el valor esté vacío o no sea una vista: la URL ya decidió.
        expect(mobileViewTarget({ ...base, search: '?view=' })).toBeNull();
    });

    it('otros parámetros de la URL no cuentan', () => {
        expect(mobileViewTarget({ ...base, search: '?date=2026-10-01&filtro=1' })).toBe('day');
    });

    it('con la pantalla ancha no hace nada', () => {
        expect(mobileViewTarget({ ...base, narrow: false })).toBeNull();
    });

    it('sin vista móvil, o si ya se está en ella, no hace nada', () => {
        expect(mobileViewTarget({ ...base, view: null })).toBeNull();
        expect(mobileViewTarget({ ...base, view: '' })).toBeNull();
        expect(mobileViewTarget({ ...base, current: 'day' })).toBeNull();
    });

    it('lee el parámetro con el nombre que se le diga (view-param)', () => {
        expect(mobileViewTarget({ ...base, param: 'vista', search: '?view=month' })).toBe('day');
        expect(mobileViewTarget({ ...base, param: 'vista', search: '?vista=month' })).toBeNull();
    });
});

describe('applyMobileView', () => {
    it('modo Livewire: llama a changeView con la vista móvil', () => {
        const env = fakeWindow();
        const wire = { changeView: vi.fn() };

        const changed = applyMobileView({ view: 'day', current: 'month', param: 'view', live: true, wire }, env);

        expect(changed).toBe(true);
        expect(wire.changeView).toHaveBeenCalledTimes(1);
        expect(wire.changeView).toHaveBeenCalledWith('day');
        expect(env.location.replace).not.toHaveBeenCalled();
    });

    it('modo enlaces: location.replace a la misma URL con ?view=, conservando el resto', () => {
        const env = fakeWindow({ url: 'https://app.test/calendario?date=2026-10-01&filtro=a#hoy' });

        const changed = applyMobileView({ view: 'list', current: 'month', param: 'view', live: false, wire: null }, env);

        expect(changed).toBe(true);
        expect(env.location.replace).toHaveBeenCalledTimes(1);
        expect(env.location.replace).toHaveBeenCalledWith('https://app.test/calendario?date=2026-10-01&filtro=a&view=list#hoy');
    });

    it('modo enlaces con otro nombre de parámetro', () => {
        const env = fakeWindow();

        applyMobileView({ view: 'day', current: 'month', param: 'vista', live: false, wire: null }, env);

        expect(env.location.replace).toHaveBeenCalledWith('https://app.test/calendario?vista=day');
    });

    it('con ?view= en la URL no cambia nada, en ninguno de los dos modos', () => {
        const env = fakeWindow({ url: 'https://app.test/calendario?view=month' });
        const wire = { changeView: vi.fn() };

        expect(applyMobileView({ view: 'day', current: 'month', param: 'view', live: true, wire }, env)).toBe(false);
        expect(applyMobileView({ view: 'day', current: 'month', param: 'view', live: false, wire: null }, env)).toBe(false);

        expect(wire.changeView).not.toHaveBeenCalled();
        expect(env.location.replace).not.toHaveBeenCalled();
    });

    it('con la pantalla ancha no cambia nada', () => {
        const env = fakeWindow({ narrow: false });
        const wire = { changeView: vi.fn() };

        expect(applyMobileView({ view: 'day', current: 'month', param: 'view', live: true, wire }, env)).toBe(false);
        expect(wire.changeView).not.toHaveBeenCalled();
    });

    it('mide el ancho con la media query (max-width: 640px)', () => {
        const env = fakeWindow();

        applyMobileView({ view: 'day', current: 'month', param: 'view', live: false, wire: null }, env);

        expect(NARROW).toBe('(max-width: 640px)');
        expect(env.matchMedia).toHaveBeenCalledWith(NARROW);
    });

    it('sin matchMedia (un entorno raro) se trata como pantalla ancha', () => {
        const env = { location: { search: '', href: 'https://app.test/', replace: vi.fn() } };

        expect(applyMobileView({ view: 'day', current: 'month', param: 'view', live: false, wire: null }, env)).toBe(false);
        expect(env.location.replace).not.toHaveBeenCalled();
    });

    it('en modo Livewire sin $wire no revienta', () => {
        expect(applyMobileView({ view: 'day', current: 'month', param: 'view', live: true, wire: null }, fakeWindow())).toBe(false);
    });
});

describe('KoreCalendarMobileView (el componente de Alpine)', () => {
    /** Lo que Alpine hace: el objeto de la factoría con `$wire` como magic. */
    function mount(config, env, wire = { changeView: vi.fn() }) {
        vi.stubGlobal('location', env.location);
        vi.stubGlobal('matchMedia', env.matchMedia);

        return { component: { ...KoreCalendarMobileView(config), $wire: wire }, wire };
    }

    it('sin ?view= y en estrecho cambia al iniciar', () => {
        const { component, wire } = mount({ view: 'day', current: 'month', param: 'view', live: true }, fakeWindow());

        component.init();

        expect(wire.changeView).toHaveBeenCalledTimes(1);
        expect(wire.changeView).toHaveBeenCalledWith('day');
    });

    it('con ?view= no cambia', () => {
        const { component, wire } = mount({ view: 'day', current: 'month', param: 'view', live: true }, fakeWindow({ url: 'https://app.test/?view=week' }));

        component.init();

        expect(wire.changeView).not.toHaveBeenCalled();
    });

    it('una sola vez: un segundo init() no vuelve a decidir', () => {
        const { component, wire } = mount({ view: 'day', current: 'month', param: 'view', live: true }, fakeWindow());

        component.init();
        component.init();
        component.init();

        expect(wire.changeView).toHaveBeenCalledOnce();
    });

    it('una sola vez también en modo enlaces', () => {
        const env = fakeWindow();
        const { component } = mount({ view: 'day', current: 'month', param: 'view', live: false }, env);

        component.init();
        component.init();

        expect(env.location.replace).toHaveBeenCalledOnce();
    });

    it('no toca $wire en modo enlaces (fuera de Livewire el magic lanzaría)', () => {
        const env = fakeWindow();
        const { component } = mount({ view: 'day', current: 'month', param: 'view', live: false }, env);

        // Como el magic de Livewire fuera de un componente: leerlo lanza.
        Object.defineProperty(component, '$wire', {
            get() {
                throw new Error('$wire no existe en modo enlaces');
            },
        });

        expect(() => component.init()).not.toThrow();
        expect(env.location.replace).toHaveBeenCalledOnce();
    });

    it('declara todo lo que se asigna (Alpine guardaría lo demás en el x-data más externo)', () => {
        expect(Object.keys(KoreCalendarMobileView({ view: 'day' })).sort()).toEqual(['_done', 'current', 'init', 'live', 'param', 'view']);
    });
});
