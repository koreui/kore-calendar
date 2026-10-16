/**
 * La matemática del gesto, sin DOM: píxeles → minutos y columnas, con snap.
 *
 * Va aparte de los componentes para probarla en node sin Alpine ni jsdom. Es
 * solo previsualización: el servidor vuelve a ajustar y a validar lo que llega,
 * así que un redondeo distinto aquí no puede guardar un dato malo, solo
 * enseñar una sombra que luego salta.
 */

export function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
}

/** Redondea al múltiplo de `step` más cercano. */
export function snap(minutes, step) {
    if (!(step > 0)) return minutes;

    return Math.round(minutes / step) * step;
}

/** Desplazamiento vertical en píxeles → minutos del eje. */
export function minutesFromOffset(dy, height, axisMinutes) {
    if (!(height > 0)) return 0;

    return (dy / height) * axisMinutes;
}

/** Posición horizontal dentro de la rejilla → índice de columna. */
export function columnAt(x, width, columns) {
    if (!(width > 0) || columns < 1) return 0;

    return clamp(Math.floor((x / width) * columns), 0, columns - 1);
}

/**
 * Dónde queda el evento al soltarlo.
 *
 * `start` y `duration` en minutos desde el inicio del eje. El inicio se ajusta
 * a `snapMinutes` y se mantiene dentro del eje sin cambiar la duración: un
 * evento arrastrado contra el borde se para en el borde, no se encoge.
 */
export function placeMove({ start, duration, dy, height, axisMinutes, snapMinutes }) {
    const raw = start + minutesFromOffset(dy, height, axisMinutes);
    const snapped = snap(raw, snapMinutes);

    return clamp(snapped, 0, Math.max(0, axisMinutes - duration));
}
