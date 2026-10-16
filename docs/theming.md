# Theming

Cómo se pinta el calendario y cómo cambiarlo: los tokens `--kore-calendar-*` con su valor por defecto, de qué tokens de kore-ui heredan, el color de los eventos, el dark mode y un ejemplo de personalización.

Documentación: [Calendar](getting-started.md) · [KoreCalendar](kore-calendar.md) · [Configuración](configuration.md) · **Theming** · [Accesibilidad](accessibility.md) · [iCalendar](ical.md)

## Qué hay que importar

El calendario no trae colores propios: usa los tokens `--kore-*` de kore-ui (tema claro y oscuro incluidos) y añade unos pocos `--kore-calendar-*` en `kore-calendar.css`. En `resources/css/app.css`, **después** del tema de kore-ui:

```css
@import 'tailwindcss';
@import '../../vendor/kore-ui/kore-ui/resources/css/kore-theme.css';
@import '../../vendor/kore-ui/calendar/resources/css/kore-calendar.css';

@source '../../vendor/kore-ui/kore-ui/resources/**/*.blade.php';
@source '../../vendor/kore-ui/kore-ui/src/**/*.php';
@source '../../vendor/kore-ui/calendar/resources/**/*.blade.php';
@source '../../vendor/kore-ui/calendar/src/**/*.php';
```

Los `@source` hacen falta porque las vistas del paquete usan clases de Tailwind (`bg-kore-bg`, `border-kore-border`, `text-kore-muted-fg`…). Sin ellos Tailwind no las genera y la rejilla sale sin estilo.

## Tokens del calendario

Declarados en `:root` por `kore-calendar.css`. Los de tamaño son un valor; los de color, una expresión sobre tokens de kore-ui.

| Token | Valor por defecto | Para qué |
|---|---|---|
| `--kore-calendar-event-height` | `1.5rem` | Alto de una fila de evento en el mes, el carril de todo el día y el timeline. `1.5rem` = 24 px, el mínimo de un objetivo táctil (WCAG 2.5.8); por debajo, las filas dejan de cumplirlo |
| `--kore-calendar-slot-height` | `2.5rem` | Alto de una ranura de la rejilla horaria. El alto de la columna es ranuras × este valor |
| `--kore-calendar-column-min` | `4.5rem` | Ancho mínimo de una columna de la rejilla horaria; por debajo hay scroll horizontal |
| `--kore-calendar-resource-width` | `12rem` | Ancho de la columna de recursos del timeline |
| `--kore-calendar-resource-width-narrow` | `7rem` | Lo mismo en un timeline estrecho (menos de `40rem` de ancho, medido en el propio timeline). Un nombre que no cabe se trunca |
| `--kore-calendar-target-min` | `1.5rem` | Tamaño mínimo de lo que se pulsa con cualquier puntero (WCAG 2.5.8, 24 px). Ver [Accesibilidad](accessibility.md#objetivos-táctiles) |
| `--kore-calendar-target-coarse` | `2.75rem` | Tamaño o área de toque con un puntero grueso (`pointer: coarse`, 44 px) |
| `--kore-calendar-timeline-slot-min` | `4.5rem` | Ancho mínimo de cada ranura del timeline |
| `--kore-calendar-timeline-row-min` | `2.5rem` | Alto mínimo de una fila del timeline. El JS de la virtualización lo lee para estimar los espaciadores, así que va en `rem` o `px` |
| `--kore-calendar-today` | `color-mix(in oklch, var(--kore-primary) 8%, transparent)` | Fondo del día actual |
| `--kore-calendar-now` | `var(--kore-destructive)` | Línea y punto del indicador de «ahora» |
| `--kore-calendar-other-month` | `var(--kore-muted-fg)` | Días de relleno de la vista mes (los de los meses vecinos): el color de su número, y su celda se tiñe con un 5 % de este color. Un solo token para las dos cosas, en claro y en oscuro. El día de hoy y los días deshabilitados conservan su propio fondo |
| `--kore-calendar-non-business` | claro: `color-mix(in oklch, color-mix(in oklch, var(--kore-muted-fg) 12%, var(--kore-muted)) 75%, transparent)` · oscuro: `color-mix(in oklch, var(--kore-muted) 85%, transparent)` | Fondo de las horas y días que no son laborables |

Los `--kc-*` (`--kc-top`, `--kc-span`…) son posiciones que calcula PHP para cada evento. No son API: no los toques. Tampoco `--kc-resource-col`, la columna de recursos que resuelve el CSS entre `--kore-calendar-resource-width` y su variante `-narrow`: cambia esas dos.

## De qué hereda

Todo lo demás sale de tokens de kore-ui, así que un cambio de tema se propaga solo:

| Parte | Tokens de kore-ui |
|---|---|
| Fondo y texto de la rejilla | `--kore-bg`, `--kore-fg` |
| Líneas y bordes | `--kore-border` |
| Cabeceras y días de otros meses (vía `--kore-calendar-other-month`) | `--kore-muted-fg` |
| Fondo de cabeceras de grupo, día deshabilitado y hover del «+N más» | `--kore-muted` |
| Ventana del «+N más» | `--kore-surface`, `--kore-surface-fg` |
| Día actual (número) | `--kore-primary`, `--kore-primary-fg`, `--kore-primary-text` |
| Foco | `--kore-ring` |
| Iconos de conflicto y previsualización inválida | `--kore-destructive`, `--kore-destructive-text` |
| Esquinas | `--kore-radius-sm`, `--kore-radius-lg` |

## Color de los eventos

El color de un evento (o, si no lo tiene, el de su recurso) viaja como una variable, nunca como una clase: `style="--kore-event: var(--kore-chart-3)"`. Tailwind v4 no detecta clases compuestas en PHP.

`color` acepta:

- **Un token de kore-ui:** `primary`, `secondary`, `accent`, `success`, `warning`, `destructive`, `info`, `muted`, `chart-1` … `chart-8`. Se resuelve a `var(--kore-<token>)`, así que cambia con el tema. Es lo recomendable: las ocho `chart-*` son la paleta categórica de kore-ui.
- **Un color CSS:** `#ff8800`, `rgb()`, `rgba()`, `hsl()`, `hsla()`, `oklch()`, `oklab()`, `lab()`, `lch()` o un nombre (`tomato`). No cambia con el tema.
- Cualquier otra cosa se descarta y el evento sale con el color por defecto: el valor acaba en un atributo `style`, y `red; background: url(…)` no puede llegar ahí.

Cómo se pinta con ese color:

- Fondo: el color al 16 % sobre `--kore-bg` (24 % al pasar el ratón), y un borde de 3 px a la izquierda (a la derecha en RTL) con el color entero. Si el evento continúa desde la semana anterior, el borde es discontinuo.
- Texto: siempre `--kore-fg`. El color de categoría no se usa como texto porque no llega a contraste AA.
- Punto de los eventos «de lista» y asa de redimensionar: el mismo color.

Un evento sin color y sin recurso con color usa `--kore-event` si algún ancestro lo define, y `--kore-primary` si no:

```css
/* Todos los eventos sin color de este calendario, en teal */
.agenda-sala { --kore-event: var(--kore-chart-3); }
```

```blade
<x-kore::calendar class="agenda-sala" :events="$eventos" />
```

## Dark mode

El calendario no tiene lógica de tema: sigue a kore-ui, que activa el oscuro con la clase `.dark` o el atributo `data-theme="dark"` en `<html>` (el interruptor de tema y su store están en la documentación de kore-ui, carpeta `theme`).

- Casi todo cambia solo porque los tokens `--kore-*` cambian.
- `--kore-calendar-non-business` se redefine en `.dark` y `[data-theme='dark']`: en claro `--kore-muted` es casi igual que el fondo y se oscurece con `--kore-muted-fg`; en oscuro `--kore-muted` ya es más claro que el fondo y se usa tal cual.
- `--kore-calendar-other-month` también se declara en ese bloque, con el mismo valor que en `:root`, para que se resuelva con el `--kore-muted-fg` del tema oscuro aunque el oscuro esté en un contenedor.
- **Los tokens se calculan en `:root`.** Con `.dark` en `<html>` no hay nada que hacer. Si activas el oscuro en un contenedor y no en `<html>`, `--kore-calendar-today` y `--kore-calendar-now` ya llegan resueltos con los valores claros: redefínelos dentro del contenedor.

## Personalizar

Escribe las sobrescrituras **fuera de cualquier `@layer`** y después del `@import` de `kore-calendar.css`: ese archivo declara sus tokens sin capa, y una declaración sin capa gana a una dentro de `@layer base` aunque sea menos específica.

```css
/* resources/css/app.css, después de los @import */

:root {
    /* Rejilla horaria más alta y barras de evento más gruesas */
    --kore-calendar-slot-height: 3rem;
    --kore-calendar-event-height: 1.75rem;

    /* Columna de recursos más ancha (y algo más ancha también en un timeline estrecho) */
    --kore-calendar-resource-width: 14rem;
    --kore-calendar-resource-width-narrow: 8rem;

    /* El día actual, más marcado */
    --kore-calendar-today: color-mix(in oklch, var(--kore-primary) 14%, transparent);
}

.dark,
[data-theme='dark'] {
    --kore-calendar-non-business: color-mix(in oklch, var(--kore-muted) 60%, transparent);
}
```

Para cambiar un solo calendario, define los tokens en su clase (el `class` del componente va al `div` que lo envuelve):

```css
.agenda-compacta {
    --kore-calendar-slot-height: 1.75rem;
    --kore-calendar-column-min: 3.5rem;
}
```

Para cambiar algo que no es un token (el borde de un evento, por ejemplo), estas son las clases a las que se puede apuntar: `.kore-calendar-event`, `.kore-calendar-dot`, `.kore-calendar-month-slot`, `.kore-calendar-day-other` (celda de relleno del mes), `.kore-calendar-other-number` (su número), `.kore-calendar-timed`, `.kore-calendar-now`, `.kore-calendar-slots` (las líneas de las ranuras de una columna de la rejilla horaria: un degradado con `--kore-border`, la hora en punto continua y las demás ranuras discontinuas y tenues), `.kore-calendar-timeline-lane`, `.kore-calendar-non-business`, `.kore-calendar-conflict`, `.kore-calendar-preview`. Los atributos `data-continues-before`, `data-continues-after`, `data-conflict` y `data-invalid` (la previsualización fuera de horario) sirven de selector.

## Pantallas estrechas

El mes y el timeline son contenedores de CSS (`container-type: inline-size`, nombrados `kore-calendar-month` y `kore-calendar-timeline`) y se adaptan a **su** ancho, no al de la pantalla. La barra hace lo mismo con la utilidad `@container` de Tailwind v4 (por eso necesita `@source` de las vistas, como el resto). Los umbrales están en `kore-calendar.css` (`40rem`) y en la barra (`@2xl`, `42rem`): una container query no admite variables en su condición, así que no son tokens. Para moverlos, repite el bloque `@container kore-calendar-month (max-width: …)` del archivo con otro valor, después del `@import`.

Clases a las que apuntar en el mes estrecho: `.kore-calendar-event-dot` (un evento con hora, que se queda en el punto), `.kore-calendar-event-time` y `.kore-calendar-event-title` (lo que se recorta). Se recortan como un `sr-only`, no con `display: none`: no los quites del árbol de accesibilidad. Más: `.kore-calendar-target` y `.kore-calendar-hit` (objetivos táctiles), `.kore-calendar-resize` (el asa, con `.kore-calendar-resize-x` y `-y`) y `.kore-calendar-select` (el selector de vista de la barra estrecha).

Un contenedor de CSS no toma su ancho de lo que lleva dentro, así que el calendario lleva `w-full` (en un `flex` sin ancho propio llena el hueco y se encoge si hace falta). En una columna `auto` de un `grid` no hay ancho que llenar y se queda en 0: dale a la columna `1fr` o un ancho.

## Movimiento

La única transición propia es el fondo del evento al pasar el ratón (120 ms), y solo se aplica con `prefers-reduced-motion: no-preference`. El giro del botón de plegar un grupo del timeline usa `transition-transform` de Tailwind y no se desactiva con esa preferencia.
