import { describe, expect, it } from 'vitest';
import { nextWindowStart } from '../../resources/js/timeline.js';

// Ventana de 60 filas sobre 300.
describe('nextWindowStart', () => {
    it('no pide nada mientras la ventana cubre lo visible con margen', () => {
        expect(nextWindowStart(0, 0, 60, 300)).toBeNull();
        expect(nextWindowStart(30, 0, 60, 300)).toBeNull();
    });

    it('pide la siguiente al acercarse al final, recentrada', () => {
        expect(nextWindowStart(45, 0, 60, 300)).toBe(25);
    });

    it('pide la anterior al subir', () => {
        expect(nextWindowStart(100, 120, 60, 300)).toBe(80);
    });

    it('no se pasa del final ni del principio', () => {
        expect(nextWindowStart(295, 200, 60, 300)).toBe(240);
        expect(nextWindowStart(2, 40, 60, 300)).toBe(0);
    });

    it('sin filas no hace nada', () => {
        expect(nextWindowStart(10, 0, 0, 0)).toBeNull();
    });
});
