import { describe, expect, it } from 'vitest';
import KoreCalendarInteraction from '../../resources/js/interaction/calendar.js';

/**
 * El anuncio de aria-live sigue al idioma del calendario: el servidor manda `locale` ('en' o
 * 'es', ya resuelto) y los textos traducidos, y la fecha se formatea con el mismo idioma.
 * Sin `locale` (el calendario nunca lo omite) se cae al inglés, el idioma de reserva del paquete.
 */

const DAY = 86400;
const JUEVES = Date.UTC(2026, 9, 15) / 1000; // jueves 15-oct-2026 00:00

const evento = (allDay = false) => ({
    event: { title: 'Reunión' },
    allDay,
    start: JUEVES + 14 * 3600,
    end: allDay ? JUEVES + 2 * DAY : JUEVES + 15 * 3600,
});

describe('anuncios en el idioma del calendario', () => {
    it('en inglés, la fecha y el texto salen en inglés', () => {
        const comp = KoreCalendarInteraction({
            locale: 'en',
            texts: { grabbed: 'Picked up.', resized: '“:title” now ends on :date' },
        });

        // El conector entre fecha y hora («at», «,») cambia con la versión de ICU de Node.
        expect(comp.describe(evento(), 'grabbed')).toMatch(/^Reunión: Thursday, October 15\D+02:00 PM\. Picked up\.$/);
        expect(comp.describe(evento(), 'grabbed', 'end')).toMatch(/^“Reunión” now ends on Thursday, October 15\D+03:00 PM\. Picked up\.$/);
    });

    it('en español, en español', () => {
        const comp = KoreCalendarInteraction({
            locale: 'es',
            texts: { grabbed: 'Agarrado.', resized: '«:title» ahora termina :date' },
        });

        expect(comp.describe(evento(), 'grabbed')).toContain('jueves, 15 de octubre');
        expect(comp.describe(evento(), 'grabbed', 'end')).toMatch(/^«Reunión» ahora termina jueves, 15 de octubre.*Agarrado\.$/);
    });

    it('un día completo se anuncia por su último día, no por el fin exclusivo', () => {
        const comp = KoreCalendarInteraction({ locale: 'en', texts: { resized: '“:title” now ends on :date' } });

        expect(comp.describe(evento(true), 'moving', 'end')).toBe('“Reunión” now ends on Friday, October 16.');
    });

    it('sin locale cae al inglés', () => {
        expect(KoreCalendarInteraction({}).locale).toBe('en');
    });
});
