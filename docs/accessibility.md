# Accesibilidad

Qué hace el calendario con el teclado, con lectores de pantalla y con el dedo, y qué todavía no cubre. Todo lo de esta página está en el código; lo que falta está en la última sección.

Documentación: [Calendar](getting-started.md) · [KoreCalendar](kore-calendar.md) · [Configuración](configuration.md) · [Theming](theming.md) · **Accesibilidad** · [iCalendar](ical.md)

Los atajos, el teclado de los eventos y los anuncios de movimiento son de `KoreCalendar` (el componente con Livewire). `<x-kore::calendar>` sin estado trae la estructura, las flechas por la rejilla y el «+N más», pero no se puede editar.

## Estructura para lectores de pantalla

- **Rejilla.** Mes, semana, día, recursos y timeline son un `role="table"` con `rowgroup`, `row`, `columnheader`, `rowheader` y `cell`, y el título del periodo como nombre. Es `table` y no `grid` a propósito: con `grid` muchos lectores salen del modo lectura.
- **Mes.** Cada evento está en la lista de la celda donde empieza, y se extiende por las demás columnas con CSS. Cada celda empieza con la fecha completa («viernes, 16 de octubre de 2026») para lectores de pantalla; el número visible es `aria-hidden`. Con `week_numbers`, cada fila lleva «Semana N» en texto oculto.
- **Semana, día y recursos.** Las cabeceras de columna dicen la fecha completa y, si hay, el recurso; la etiqueta corta visible es `aria-hidden`. El eje de horas y las líneas de las ranuras son `aria-hidden`: la hora de un evento se lee en el texto del propio evento. El carril de todo el día tiene su `rowheader`.
- **Agenda.** Una lista con el título como nombre, un `h3` con la fecha completa por día y la franja horaria de cada evento en texto. Sin eventos, un mensaje con `role="status"`.
- **Timeline.** Las ranuras de tiempo son `columnheader`, cada recurso es el `rowheader` de su fila y los grupos tienen un botón con `aria-expanded` y nombre («Mostrar u ocultar …», traducción `toggle_group`).
- **Hoy** lleva `aria-current="date"` (la celda, la cabecera de columna o el `h3` de la agenda).
- **Eventos.** Con `url` son enlaces (`<a>`). Si se pueden mover o pulsar, son `role="button"` con `tabindex="0"`. Los demás son texto de la celda, no enfocables.
- **Decoración sin semántica** (`aria-hidden`): el horario no laborable, la línea de «ahora», la capa de previsualización del arrastre, el asa de redimensionar, los puntos de color y los iconos.
- **Conflictos** (`highlightConflicts()`): además del icono rojo y el borde, el evento lleva un texto solo para lectores de pantalla («En conflicto con «Reunión B»», traducción `conflict`). El aviso no depende del color.
- **Color de los eventos.** El texto es siempre el primer plano del tema sobre un tinte del color; el color de categoría no se usa como texto.
- **Textos.** Ninguna vista lleva texto fijo: todo, también los nombres accesibles, sale de las [traducciones](configuration.md#traducciones), así que están en el idioma que configures.

## Teclado

### Navegar por la rejilla

La rejilla entera es **una sola parada de tabulación**: al entrar con Tab, el foco cae en la celda de hoy (o la primera) y las flechas se mueven dentro. Solo actúan cuando el foco está en la celda, no en algo de su interior (un evento, un «+N más»). Con Alt, Ctrl o Meta pulsadas no hacen nada.

| Vista | Qué enfoca | Teclas |
|---|---|---|
| Mes | Cada día | ←/→ un día, ↑/↓ una semana, Inicio/Fin primer o último día de la semana |
| Semana, día y recursos | Las cabeceras de columna | ←/→ una columna, Inicio/Fin primera o última. ↑/↓ no hacen nada |
| Agenda y timeline | — | Sin flechas: se recorre con Tab (eventos, enlaces y botones de grupo) |

En RTL (`dir="rtl"` en el calendario o en un ancestro) ←/→ se invierten. También al [mover un evento](#mover-y-redimensionar-un-evento): las flechas siguen el sentido visual.

### Atajos del calendario (`KoreCalendar`)

Con el foco en cualquier punto del calendario:

| Tecla | Hace |
|---|---|
| RePág / AvPág | Periodo anterior / siguiente |
| T | Volver a hoy (no cuando el foco está en un campo de texto, ni con Ctrl, Alt o Meta) |

No funcionan mientras un evento está agarrado con el teclado.

### Pulsar un evento

Si el calendario tiene `eventForm()` o un `onEventClick()` propio, el evento se abre con **Intro**, y con **Espacio** si no se puede mover (en uno movible, Espacio lo agarra). Solo si el foco está en el propio evento y sin Mayúsculas, Ctrl, Alt ni Meta; una tecla mantenida no abre dos veces.

### Mover y redimensionar un evento

Con `editable()` y el foco en un evento:

| Tecla | Hace |
|---|---|
| Espacio | Agarra el evento. Se anuncia («Reunión: viernes, 16 de octubre, 10:00. Agarrado.») |
| ←/→ | Mueve un día. En RTL, → va al día anterior y ← al siguiente: el sentido visual, como en la rejilla |
| ↑/↓ | Mueve un paso (`snap`) en las vistas con hora; un día en un evento de día completo; una semana en el mes |
| Mayúsculas + ←/→ | Cambia el fin un día. En RTL, ← lo alarga y → lo acorta (el fin está a la izquierda) |
| Mayúsculas + ↑/↓ | Cambia el fin un paso (un día en el mes y en eventos de día completo). La duración mínima es un paso |
| Intro | Suelta: un solo viaje al servidor, que valida y guarda |
| Escape o Tab | Cancela (se anuncia «Cancelado.») |

- Mayúsculas + flechas solo cambia el fin si el evento se puede redimensionar (`durationEditable`); si no, mueve el evento.
- Cada paso se anuncia con el título y la fecha a la que quedaría: el **inicio** al mover y el **nuevo fin** con Mayúsculas («Reunión» ahora termina viernes, 16 de octubre, 11:15), con el texto de `event_resized`. En un evento de día completo se anuncia el último día, no el fin exclusivo. La previsualización es la misma que con el ratón.
- Al soltar, el resultado se anuncia («… movido al …») o, si el servidor lo rechaza, el motivo. Después el foco vuelve al evento aunque Livewire haya repintado su nodo.
- Cada evento movible apunta con `aria-describedby` a un texto de ayuda oculto con las instrucciones (traducción `kbd_help`). Su `id` es propio de cada calendario (lleva el del componente de Livewire), así que varios calendarios en la misma página no se pisan.
- Con el evento agarrado, el ratón no inicia otro gesto y un clic no abre nada.
- El teclado mueve en el tiempo, no entre recursos ni entre el carril de todo el día y las columnas horarias.

### Ventana del «+N más»

El botón declara `aria-haspopup="dialog"`, `aria-expanded` y `aria-controls`, y su nombre dice cuántos eventos y de qué día. La ventana es un `role="dialog"` con nombre y el foco atrapado; **Escape** o el botón de cerrar la cierran y devuelven el foco al botón. Un clic fuera también la cierra.

### Barra

Los botones de anterior y siguiente tienen nombre accesible (son solo icono). El grupo de vistas es un `role="group"` con nombre; la vista activa se marca con `aria-pressed` en `KoreCalendar` y con `aria-current="page"` en el componente de enlaces.

### Formulario y diálogo de alcance

Son overlays de kore-ui (foco, Escape y apilado los gestiona kore-ui). El diálogo de alcance enfoca «Solo este evento». El formulario no usa la validación del navegador (`novalidate`): los errores salen del servidor, en el idioma del calendario.

## Lectores de pantalla: anuncios

- **Navegación.** El título (`h2`) es una región `aria-live="polite"`: al pulsar «siguiente» se anuncia «Noviembre 2026».
- **Mover y redimensionar.** Una región oculta `aria-live="assertive"` anuncia el agarrar, cada paso, cancelar y el resultado del servidor (aceptado o motivo del rechazo). También con el ratón y con el dedo. Antes de cada anuncio se vacía la región, para que dos iguales seguidos se repitan.
- **Toasts.** Los rechazos salen además en un toast de kore-ui. Los éxitos de mover y redimensionar solo se anuncian (con `undoable()` también en un toast con «Deshacer»); crear, editar y eliminar con el formulario sacan un toast de éxito.
- **Series recurrentes.** Tras elegir el alcance en el diálogo, el gesto original ya terminó: el resultado sale solo en un toast.

## Táctil

- **Pulsación larga (450 ms) para arrastrar** o seleccionar un rango. Un deslizamiento normal sigue siendo scroll: si el dedo se mueve más de 8 px antes de que acabe la pulsación, el gesto se descarta. Una vez armado, el scroll se bloquea hasta soltar.
- Los eventos movibles llevan `touch-action: manipulation`.
- Un toque corto sobre un evento pulsable lo abre; sobre un enlace, navega.
- Con el ratón, un movimiento de menos de 5 px es un clic.
- Si el navegador cancela el gesto (`pointercancel`), se descarta la previsualización y no se guarda nada.

## Movimiento y foco

- El foco se ve en eventos y celdas con un contorno de 2 px del color `--kore-ring`; en los botones, el de kore-ui.
- La única transición propia (el fondo del evento al pasar el ratón) solo se aplica con `prefers-reduced-motion: no-preference`. El giro del botón de plegar un grupo del timeline no se desactiva con esa preferencia.

## Lo que no cubre todavía

- **Crear eventos con el teclado.** Seleccionar un rango (`selectable()`) es un gesto de puntero o de dedo; no hay forma de hacerlo con el teclado ni de abrir el formulario de un evento nuevo desde él. Hace falta un botón propio en la aplicación.
- **Cambiar de recurso o de tipo con el teclado.** Mover con el teclado solo cambia las fechas; no pasa de una columna o fila de recurso a otra, ni entre día completo y con hora.
- **Redimensionar desde el inicio.** El asa existe solo en el borde final del evento, con el ratón y el dedo (6 px), y el teclado solo cambia el fin.
- **Flechas en la agenda y el timeline.** No hay navegación por celdas ahí; se recorre con Tab.
- **Flechas verticales en semana y día.** Las cabeceras de columna son una sola fila.
- **RTL de punta a punta.** Las flechas se invierten en la rejilla y al mover o redimensionar con el teclado, pero parte del diseño usa clases físicas (`border-l`, `text-right`) y el eje del timeline se coloca con `left`.
- **Atajos de vista** (cambiar de vista con una tecla): no hay.
- **El texto de ayuda de un evento movible** solo existe dentro de `KoreCalendar`; no hay forma de mover eventos en el componente sin estado.
- **Alto contraste y colores forzados.** No hay reglas específicas. El horario no laborable es solo un fondo (decorativo).
