/**
 * Qué evento abre un clic o una tecla: la parte de «pulsar un evento» que no
 * necesita Alpine ni red, para poder probarla sin ninguno de los dos.
 *
 * Solo los eventos que el servidor pintó con `data-clickable` (un calendario con
 * `eventForm()` o un `onEventClick()` propio). Uno con `href` es un enlace y
 * navega solo: abrir además el formulario sería hacer dos cosas con un clic.
 */

/** El nodo de un evento pulsable que contiene `target`, o `null`. */
function clickableEl(target) {
    const el = target?.closest?.('[data-kore-event][data-clickable]');

    return el && !el.hasAttribute('href') ? el : null;
}

/**
 * El id del evento al que se hizo clic, o `null`.
 *
 * Un clic en el asa de redimensionar no abre nada: el asa es para arrastrar, y un
 * clic suelto sobre ella es un gesto fallido, no un «abrir».
 */
export function clickedEventId(target) {
    if (target?.closest?.('[data-kore-resize]')) return null;

    return clickableEl(target)?.dataset.koreEvent ?? null;
}

/**
 * El id del evento que una tecla abre, o `null`.
 *
 * Intro siempre. Espacio solo en un evento que NO se puede mover: en uno movible
 * Espacio lo agarra (KoreCalendarInteraction.onKeydown), pero un `role="button"`
 * que no se mueve tiene que responder a Espacio igual que a Intro. Tiene que ser
 * el propio evento el que tiene el foco, no algo enfocable dentro de él, y una
 * tecla mantenida (`repeat`) no abre dos veces.
 */
export function keyedEventId(e) {
    if (e.repeat || e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) return null;

    const el = clickableEl(e.target);
    if (!el || el !== e.target) return null;

    if (e.key === 'Enter') return el.dataset.koreEvent;
    if (e.key === ' ' && !el.hasAttribute('data-movable')) return el.dataset.koreEvent;

    return null;
}
