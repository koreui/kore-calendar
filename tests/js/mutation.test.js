import { describe, expect, it } from 'vitest';
import { DAY, computeMove, computeResize, computeSelection, hitFrom, snapTo, startOfDay } from '../../resources/js/interaction/mutation.js';

const H = 3600;
// 2026-10-14 00:00 como marker (segundos de hora de pared "UTC").
const D = Date.UTC(2026, 9, 14) / 1000;

describe('computeMove', () => {
    it('en la rejilla horaria desplaza por la diferencia entre agarre y destino', () => {
        const ev = { start: D + 10 * H, end: D + 11 * H, allDay: false };
        const r = computeMove(ev, { kind: 'time', ts: D + 10.5 * H }, { kind: 'time', ts: D + DAY + 14.5 * H });

        expect(r).toEqual({ start: D + DAY + 14 * H, end: D + DAY + 15 * H, allDay: false, resource: null });
    });

    it('en el mes un evento con hora conserva su hora al cambiar de día', () => {
        const ev = { start: D + 9 * H, end: D + 10 * H, allDay: false };
        const r = computeMove(ev, { kind: 'day', ts: D }, { kind: 'day', ts: D + 2 * DAY });

        expect([r.start, r.allDay]).toEqual([D + 2 * DAY + 9 * H, false]);
    });

    it('de una columna horaria al carril de todo el día: día completo de un día', () => {
        const ev = { start: D + 9 * H, end: D + 12 * H, allDay: false };
        const r = computeMove(ev, { kind: 'time', ts: D + 9 * H }, { kind: 'allday', ts: D + DAY });

        expect(r).toEqual({ start: D + DAY, end: D + 2 * DAY, allDay: true, resource: null });
    });

    it('del carril de todo el día a una columna: empieza en el slot de destino, con la duración por defecto', () => {
        // #7533: el inicio es el del destino, no el del punto donde se agarró.
        const ev = { start: D, end: D + 2 * DAY, allDay: true };
        const r = computeMove(ev, { kind: 'allday', ts: D + DAY }, { kind: 'time', ts: D + 2 * H }, { defaultTimed: H });

        expect(r).toEqual({ start: D + 2 * H, end: D + 3 * H, allDay: false, resource: null });
    });

    it('lleva el recurso del destino', () => {
        const ev = { start: D + 9 * H, end: D + 10 * H, allDay: false };

        expect(computeMove(ev, { kind: 'timeline', ts: D + 9 * H, resource: 'a' }, { kind: 'timeline', ts: D + 11 * H, resource: 'b' }).resource).toBe('b');
    });
});

describe('computeResize', () => {
    it('con hora: el fin va al final del slot de destino, y nunca antes del inicio', () => {
        const ev = { start: D + 9 * H, end: D + 10 * H, allDay: false };

        // El slot de las 11:45 termina a las 12:00.
        expect(computeResize(ev, { kind: 'time', ts: D + 11.75 * H }, { step: 900 }).end).toBe(D + 12 * H);
        expect(computeResize(ev, { kind: 'time', ts: D + 8 * H }, { step: 900 }).end).toBe(D + 9 * H + 900);
    });

    it('de día completo: soltar sobre un día lo incluye (fin exclusivo)', () => {
        const ev = { start: D, end: D + DAY, allDay: true };

        expect(computeResize(ev, { kind: 'day', ts: D + 2 * DAY }).end).toBe(D + 3 * DAY);
    });

    it('desde el inicio', () => {
        const ev = { start: D + 9 * H, end: D + 10 * H, allDay: false };

        expect(computeResize(ev, { kind: 'time', ts: D + 7 * H }, { fromStart: true, step: 900 })).toEqual({ start: D + 7 * H, end: D + 10 * H });
    });
});

describe('computeSelection', () => {
    it('días: del primero al último incluido, en cualquier dirección', () => {
        expect(computeSelection({ kind: 'day', ts: D + 2 * DAY }, { kind: 'day', ts: D })).toEqual({ start: D, end: D + 3 * DAY, allDay: true, resource: null });
    });

    it('horas: del slot inicial al final del último slot', () => {
        expect(computeSelection({ kind: 'time', ts: D + 9 * H, resource: 'a' }, { kind: 'time', ts: D + 10 * H }, 1800))
            .toEqual({ start: D + 9 * H, end: D + 10.5 * H, allDay: false, resource: 'a' });
    });
});

describe('hitFrom', () => {
    const lane = { koreDrop: 'time', origin: String(D + 8 * H), axis: String(10 * H) };
    const rect = { top: 100, height: 1000, left: 0, width: 200 };

    it('convierte la posición vertical en el slot que hay debajo', () => {
        // 25 % de 10 h = 2 h 30 → 10:30; con snap de 30 min, el slot de las 10:30.
        expect(hitFrom(lane, rect, 50, 350, 1800).ts).toBe(D + 10.5 * H);
        // Un poco antes del borde del slot sigue siendo el anterior.
        expect(hitFrom(lane, rect, 50, 349, 1800).ts).toBe(D + 10 * H);
    });

    it('no se sale del eje', () => {
        expect(hitFrom(lane, rect, 50, 5000, 1800).ts).toBe(D + 18 * H - 1800);
        expect(hitFrom(lane, rect, 50, -50, 1800).ts).toBe(D + 8 * H);
    });

    it('en el timeline mide en horizontal', () => {
        const row = { koreDrop: 'timeline', origin: String(D), axis: String(DAY), resource: 'b' };

        expect(hitFrom(row, { top: 0, height: 40, left: 0, width: 2400 }, 1000, 20, H)).toEqual({ kind: 'timeline', ts: D + 10 * H, resource: 'b' });
    });

    it('una celda de día da su fecha', () => {
        expect(hitFrom({ koreDrop: 'day', date: String(D) }, rect, 0, 0, 0)).toEqual({ kind: 'day', ts: D, resource: null });
    });
});

it('snapTo y startOfDay', () => {
    expect(snapTo(D + 10 * H + 800, 900, D)).toBe(D + 10 * H + 900);
    expect(startOfDay(D + 15 * H)).toBe(D);
});
