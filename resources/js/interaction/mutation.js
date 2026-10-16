/**
 * Qué cambia un gesto: la matemática de mover, redimensionar y seleccionar, sin DOM.
 *
 * Todo en "markers": segundos de hora de pared en la zona de la vista (los mismos
 * que usa PHP, Marker::ts). Es solo previsualización: el servidor vuelve a ajustar,
 * valida y decide (MutationBuilder / MutationValidator). Las reglas son las de
 * FullCalendar (EventDragging.computeEventMutation), resumidas:
 *
 * - Mismo tipo de destino que el de agarre: se desplaza por la diferencia.
 * - Cambio de tipo (de una columna horaria al carril de todo el día, o al revés):
 *   el inicio es el del destino, no el punto donde se agarró (#7533).
 *
 * Un "hit" es { kind, ts, resource }:
 *   day       celda de la vista mes: un día, conserva si el evento es de día completo
 *   allday    carril de todo el día de la rejilla horaria: lo vuelve de día completo
 *   time      columna horaria: lo vuelve evento con hora
 *   timeline  fila del timeline: eje continuo, conserva el tipo
 */

export const DAY = 86400;

export function startOfDay(ts) {
    return ts - (((ts % DAY) + DAY) % DAY);
}

export function snapTo(ts, step, origin = 0) {
    if (!(step > 0)) return ts;

    return origin + Math.round((ts - origin) / step) * step;
}

/** Dónde quedaría un evento movido de `fromHit` a `toHit`. */
export function computeMove(event, fromHit, toHit, { defaultTimed = 3600 } = {}) {
    const duration = event.end - event.start;
    const sameKind = fromHit.kind === toHit.kind
        || (isDayLike(fromHit.kind) && isDayLike(toHit.kind));

    let start;
    let allDay = event.allDay;

    if (sameKind) {
        start = event.start + (toHit.ts - fromHit.ts);
    } else if (toHit.kind === 'allday') {
        start = startOfDay(toHit.ts);
        allDay = true;
    } else {
        start = toHit.ts;
        allDay = toHit.kind === 'timeline' ? event.allDay : false;
    }

    if (toHit.kind === 'allday') allDay = true;
    if (toHit.kind === 'time') allDay = false;
    if (allDay) start = startOfDay(start);

    // Al cambiar de modalidad sin conservar la duración, la del nuevo tipo por
    // defecto (allDayMaintainDuration = false en FullCalendar).
    const changed = allDay !== event.allDay;
    const end = changed ? start + (allDay ? DAY : defaultTimed) : start + duration;

    return { start, end, allDay, resource: toHit.resource ?? null };
}

/** El nuevo borde al redimensionar; nunca deja el evento con duración <= 0. */
export function computeResize(event, toHit, { fromStart = false, step = 0 } = {}) {
    const unit = event.allDay || toHit.kind === 'day' || toHit.kind === 'allday' ? DAY : step;

    if (fromStart) {
        const start = event.allDay ? startOfDay(toHit.ts) : toHit.ts;

        return { start: Math.min(start, event.end - Math.max(unit, 1)), end: event.end };
    }

    // El fin es exclusivo: soltar sobre un día lo incluye entero, y soltar sobre
    // un slot lo incluye hasta su final (hit.end en FullCalendar).
    const edge = event.allDay || toHit.kind === 'day' || toHit.kind === 'allday'
        ? startOfDay(toHit.ts) + DAY
        : toHit.ts + Math.max(step, 0);

    return { start: event.start, end: Math.max(edge, event.start + Math.max(unit, 1)) };
}

/** El rango seleccionado entre dos hits, en orden, con el fin exclusivo. */
export function computeSelection(fromHit, toHit, step) {
    const dayLike = isDayLike(fromHit.kind);
    const a = Math.min(fromHit.ts, toHit.ts);
    const b = Math.max(fromHit.ts, toHit.ts);

    return dayLike
        ? { start: startOfDay(a), end: startOfDay(b) + DAY, allDay: true, resource: fromHit.resource ?? null }
        : { start: a, end: b + Math.max(step, 1), allDay: false, resource: fromHit.resource ?? null };
}

/**
 * El hit bajo el puntero dentro de un destino. `rect` es el del destino; `data`
 * sus atributos (data-kore-drop, data-date, data-origin, data-axis, data-resource).
 */
export function hitFrom(data, rect, x, y, snap) {
    const kind = data.koreDrop;
    const resource = data.resource ?? null;

    if (kind === 'day' || kind === 'allday') {
        return { kind, ts: Number(data.date), resource };
    }

    const origin = Number(data.origin);
    const axis = Number(data.axis);
    const fraction = kind === 'timeline'
        ? (x - rect.left) / Math.max(rect.width, 1)
        : (y - rect.top) / Math.max(rect.height, 1);
    const raw = origin + Math.min(Math.max(fraction, 0), 1) * axis;

    // Hacia abajo, no al más cercano: el slot es el que está bajo el puntero.
    const ts = snap > 0 ? origin + Math.floor((raw - origin) / snap) * snap : raw;

    return { kind, ts: Math.min(ts, origin + axis - Math.max(snap, 1)), resource };
}

function isDayLike(kind) {
    return kind === 'day' || kind === 'allday';
}
