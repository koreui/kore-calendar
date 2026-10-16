import { describe, expect, it } from 'vitest';
import { targetIndex } from '../../resources/js/grid.js';

// Rejilla de mes: 6 semanas × 7 días = 42 celdas.
describe('targetIndex', () => {
    it('flechas: un día a los lados, una semana arriba y abajo', () => {
        expect(targetIndex('ArrowRight', 10, 42, 7)).toBe(11);
        expect(targetIndex('ArrowLeft', 10, 42, 7)).toBe(9);
        expect(targetIndex('ArrowDown', 10, 42, 7)).toBe(17);
        expect(targetIndex('ArrowUp', 10, 42, 7)).toBe(3);
    });

    it('de un lado de la semana pasa a la siguiente o la anterior', () => {
        expect(targetIndex('ArrowRight', 6, 42, 7)).toBe(7);
        expect(targetIndex('ArrowLeft', 7, 42, 7)).toBe(6);
    });

    it('no sale de la rejilla', () => {
        expect(targetIndex('ArrowUp', 3, 42, 7)).toBeNull();
        expect(targetIndex('ArrowDown', 40, 42, 7)).toBeNull();
        expect(targetIndex('ArrowLeft', 0, 42, 7)).toBeNull();
        expect(targetIndex('ArrowRight', 41, 42, 7)).toBeNull();
    });

    it('Inicio y Fin van al principio y al final de la semana', () => {
        expect(targetIndex('Home', 10, 42, 7)).toBe(7);
        expect(targetIndex('End', 10, 42, 7)).toBe(13);
        expect(targetIndex('Home', 7, 42, 7)).toBeNull();
    });

    it('en RTL las flechas horizontales se invierten', () => {
        expect(targetIndex('ArrowRight', 10, 42, 7, true)).toBe(9);
        expect(targetIndex('ArrowLeft', 10, 42, 7, true)).toBe(11);
    });

    it('ignora otras teclas', () => {
        expect(targetIndex('Enter', 10, 42, 7)).toBeNull();
        expect(targetIndex('a', 10, 42, 7)).toBeNull();
    });

    it('una sola fila (cabecera de la rejilla horaria): arriba y abajo no hacen nada', () => {
        expect(targetIndex('ArrowDown', 2, 7, 7)).toBeNull();
        expect(targetIndex('ArrowRight', 2, 7, 7)).toBe(3);
    });
});
