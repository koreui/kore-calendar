import { describe, expect, it } from 'vitest';
import { clamp, columnAt, minutesFromOffset, placeMove, snap } from '../../resources/js/interaction/geometry.js';

/**
 * La matemática del gesto: píxeles → minutos y columnas. Sin DOM ni Alpine.
 *
 * Es solo previsualización (el servidor vuelve a ajustar y a validar), pero una
 * sombra que se sale del eje o que salta de columna en un borde se nota al
 * instante. Lo que importa aquí son los bordes: cero, negativo, fuera de rango.
 */

describe('clamp', () => {
    it('deja pasar lo que está dentro', () => {
        expect(clamp(5, 0, 10)).toBe(5);
    });

    it('recorta por abajo y por arriba', () => {
        expect(clamp(-3, 0, 10)).toBe(0);
        expect(clamp(42, 0, 10)).toBe(10);
    });

    it('los extremos son válidos', () => {
        expect(clamp(0, 0, 10)).toBe(0);
        expect(clamp(10, 0, 10)).toBe(10);
    });
});

describe('snap', () => {
    it('redondea al múltiplo más cercano', () => {
        expect(snap(0, 15)).toBe(0);
        expect(snap(7, 15)).toBe(0);
        expect(snap(8, 15)).toBe(15);
        expect(snap(22, 15)).toBe(15);
        expect(snap(23, 15)).toBe(30);
    });

    it('lo que ya está en el paso no se mueve', () => {
        expect(snap(45, 15)).toBe(45);
        expect(snap(60, 30)).toBe(60);
    });

    it('a mitad de camino redondea hacia arriba', () => {
        expect(snap(7.5, 15)).toBe(15);
        expect(snap(22.5, 15)).toBe(30);
    });

    it('con minutos negativos sigue ajustando al múltiplo más cercano', () => {
        expect(snap(-8, 15)).toBe(-15);
        expect(snap(-22, 15)).toBe(-15);
        // Math.round(-0.47) es -0, y -0 no es 0 para toBe: se compara por valor.
        expect(snap(-7, 15)).toBeCloseTo(0);
    });

    it('con un paso 0 devuelve los minutos tal cual', () => {
        expect(snap(7.3, 0)).toBe(7.3);
        expect(snap(0, 0)).toBe(0);
    });

    it('con un paso negativo, también', () => {
        expect(snap(7.3, -15)).toBe(7.3);
    });

    it('con un paso que no es un número, también (sin NaN)', () => {
        expect(snap(7.3, NaN)).toBe(7.3);
        expect(snap(7.3, undefined)).toBe(7.3);
    });
});

describe('minutesFromOffset', () => {
    it('reparte la altura entera entre los minutos del eje', () => {
        expect(minutesFromOffset(300, 600, 600)).toBe(300);
        expect(minutesFromOffset(100, 400, 600)).toBe(150);
    });

    it('sin desplazamiento son 0 minutos', () => {
        expect(minutesFromOffset(0, 600, 600)).toBe(0);
    });

    it('hacia arriba da minutos negativos', () => {
        expect(minutesFromOffset(-60, 600, 600)).toBe(-60);
    });

    it('con una rejilla sin altura devuelve 0, no Infinity ni NaN', () => {
        expect(minutesFromOffset(50, 0, 600)).toBe(0);
        expect(minutesFromOffset(0, 0, 600)).toBe(0);
    });

    it('con una altura negativa o inválida devuelve 0', () => {
        expect(minutesFromOffset(50, -10, 600)).toBe(0);
        expect(minutesFromOffset(50, NaN, 600)).toBe(0);
    });
});

describe('columnAt', () => {
    // 7 columnas de 100 px en una rejilla de 700.
    const col = (x) => columnAt(x, 700, 7);

    it('el centro de cada columna es esa columna', () => {
        [0, 1, 2, 3, 4, 5, 6].forEach((i) => expect(col(i * 100 + 50)).toBe(i));
    });

    it('el borde izquierdo pertenece a la columna que empieza ahí', () => {
        expect(col(0)).toBe(0);
        expect(col(100)).toBe(1);
        expect(col(600)).toBe(6);
    });

    it('un píxel antes del borde sigue en la columna anterior', () => {
        expect(col(99)).toBe(0);
        expect(col(599)).toBe(5);
    });

    it('el borde derecho de la rejilla no crea una columna 7', () => {
        expect(col(700)).toBe(6);
        expect(col(699.99)).toBe(6);
    });

    it('fuera de rango se queda en la primera o la última', () => {
        expect(col(-50)).toBe(0);
        expect(col(-1e9)).toBe(0);
        expect(col(5000)).toBe(6);
    });

    it('con una rejilla sin ancho devuelve 0, no NaN', () => {
        expect(columnAt(50, 0, 7)).toBe(0);
        expect(columnAt(0, 0, 7)).toBe(0);
        expect(columnAt(50, -10, 7)).toBe(0);
    });

    it('con menos de una columna devuelve 0', () => {
        expect(columnAt(50, 700, 0)).toBe(0);
        expect(columnAt(50, 700, -3)).toBe(0);
    });

    it('con una sola columna siempre es la 0', () => {
        expect(columnAt(0, 700, 1)).toBe(0);
        expect(columnAt(699, 700, 1)).toBe(0);
        expect(columnAt(9999, 700, 1)).toBe(0);
    });
});

describe('placeMove', () => {
    // Eje de 600 minutos sobre 600 px (1 px = 1 minuto), paso de 15.
    const base = { start: 60, duration: 60, height: 600, axisMinutes: 600, snapMinutes: 15 };
    const place = (extra) => placeMove({ ...base, ...extra });

    it('sin desplazamiento el evento se queda donde estaba', () => {
        expect(place({ dy: 0 })).toBe(60);
    });

    it('ajusta el inicio al paso de 15 minutos', () => {
        // 60 + 37 = 97 → el múltiplo de 15 más cercano es 90.
        expect(place({ dy: 37 })).toBe(90);
        // 60 + 38 = 98 → 105.
        expect(place({ dy: 38 })).toBe(105);
        // 60 - 22 = 38 → 45.
        expect(place({ dy: -22 })).toBe(45);
    });

    it('el resultado siempre es múltiplo del paso lejos de los bordes', () => {
        for (let dy = -50; dy <= 300; dy += 7) {
            expect(place({ dy }) % 15).toBe(0);
        }
    });

    it('usa la altura real: la misma distancia en píxeles son otros minutos', () => {
        // 300 px de alto para 600 minutos: 1 px = 2 minutos. 60 + 30*2 = 120.
        expect(place({ dy: 30, height: 300 })).toBe(120);
    });

    it('no sale del eje por arriba', () => {
        expect(place({ dy: -1000 })).toBe(0);
        expect(place({ dy: -60 })).toBe(0);
    });

    it('no sale del eje por abajo: acaba justo al final', () => {
        // El inicio máximo es 600 - 60 = 540.
        expect(place({ dy: 1000 })).toBe(540);
        expect(place({ dy: 480 })).toBe(540);
    });

    it('la duración no cambia contra el borde: el evento se para, no se encoge', () => {
        for (const duration of [15, 60, 90, 240]) {
            const start = place({ duration, dy: 5000 });

            expect(start).toBe(600 - duration);
            expect(start + duration).toBe(600);
        }
    });

    it('si el paso se pasaría del borde, manda el borde aunque no sea múltiplo', () => {
        // Duración 70 → inicio máximo 530. Cerca del borde el snap daría 540 (> 530).
        expect(place({ duration: 70, dy: 5000 })).toBe(530);
        expect(place({ duration: 70, start: 500, dy: 38 })).toBe(530);
    });

    it('un evento tan largo como el eje solo cabe en el inicio', () => {
        expect(place({ start: 0, duration: 600, dy: 200 })).toBe(0);
        expect(place({ start: 0, duration: 600, dy: -200 })).toBe(0);
    });

    it('un evento más largo que el eje no da un inicio negativo', () => {
        expect(place({ start: 0, duration: 900, dy: 100 })).toBe(0);
    });

    it('con la rejilla sin altura solo ajusta el inicio', () => {
        expect(place({ start: 67, dy: 500, height: 0 })).toBe(60);
    });

    it('con un paso 0 no ajusta, pero sigue sin salirse del eje', () => {
        expect(place({ dy: 37.5, snapMinutes: 0 })).toBe(97.5);
        expect(place({ dy: 5000, snapMinutes: 0 })).toBe(540);
        expect(place({ dy: -5000, snapMinutes: 0 })).toBe(0);
    });
});
