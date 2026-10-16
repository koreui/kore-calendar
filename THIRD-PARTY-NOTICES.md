# Avisos de terceros

Este paquete incluye código derivado de software de terceros. Lo derivado conserva el aviso de copyright y la licencia de su origen, que se reproducen aquí.

## FullCalendar

- Proyecto: [FullCalendar](https://fullcalendar.io), v7.1.3 (paquete `@full-ui/preact`).
- Licencia: MIT.
- Qué se tomó: el algoritmo de colocación de eventos (el "kernel") y sus casos de prueba, portados de TypeScript a PHP. No se ha copiado nada de los paquetes Premium (timeline, recursos).

### Archivos portados

Código (en `src/Layout/Kernel/`):

| Archivo de FullCalendar | Port |
|---|---|
| `packages/preact/src/seg-placement/kernel.ts` | `PlacementKernel.php`, `ExtraPlacer.php`, `HiddenGroups.php`, `Slice.php`, `Source.php` |
| `packages/preact/src/seg-placement/span-math.ts` | `SpanMath.php`, `Lateral.php` |
| `packages/preact/src/seg-placement/print.ts` (solo como referencia del modo sin medición) | `DayRowPlacer.php` |
| `packages/preact/src/daygrid/seg-placement-adapter.ts` | `DayRowPlacer.php` |
| `packages/preact/src/timegrid/seg-placement-adapter.ts` | `TimeColumnPlacer.php` |
| `packages/preact/src/timegrid/components/TimeGridCol.tsx` (`computeSegHStyle`) | `TimeColumnPlacer.php` |

No se porta el modo de límite por píxeles (`buildPixelLimitedLayout`, `prunePixelLimitedSliceLevels`): mide alturas en el navegador y aquí el límite es numérico.

Pruebas (en `tests/Layout/Kernel/`), con los valores esperados de los originales:

| Archivo de FullCalendar | Port |
|---|---|
| `packages/preact/tests/seg-placement/kernel-correctness.test.ts` | `KernelCorrectnessTest.php` |
| `packages/preact/tests/seg-placement/kernel-fuzz.test.ts` (generador pseudoaleatorio y escenarios) | `KernelFuzzTest.php`, `Support/Fixtures.php` |
| `packages/preact/tests/seg-placement/daygrid-adapter.test.ts` | `DayRowPlacerTest.php` |
| `packages/preact/tests/seg-placement/minimal-footprint.test.ts` (regresiones #5883, #5790, #7447 y "mejor mitad") | `DayRowRegressionsTest.php` |
| `packages/preact/tests/seg-placement/timegrid-adapter.test.ts` (incluye #7914 y #7879) | `TimeColumnPlacerTest.php` |
| `packages/vanilla-tests/src/legacy/more-link.ts` y `packages/vanilla-tests/src/event-render/dayGrid-events.ts` (#7462): solo los datos y los resultados esperados de los ejemplos del enlace "+N más", comprobados sobre el modelo y no sobre el DOM | `DayRowMoreLinkTest.php` |

`PlacerPropertiesTest.php` reutiliza el generador pseudoaleatorio y los escenarios de `kernel-fuzz.test.ts` para probar las invariantes de los dos adaptadores.

### Licencia

```
MIT License

Copyright (c) 2026 Adam Shaw

Permission is hereby granted, free of charge, to any person obtaining
a copy of this software and associated documentation files (the
"Software"), to deal in the Software without restriction, including
without limitation the rights to use, copy, modify, merge, publish,
distribute, sublicense, and/or sell copies of the Software, and to
permit persons to whom the Software is furnished to do so, subject to
the following conditions:

The above copyright notice and this permission notice shall be
included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION
OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
```
