# kore-calendar — instrucciones de sesión

> Las instrucciones generales del monorepo viven en `../CLAUDE.md`. La spec de este paquete es `../docs/calendar.md`: consúltala antes de implementar cualquier fase.

- Paquete `kore-ui/calendar`, namespace `KoreUi\Calendar`. Depende de kore-ui (path repository `../kore-ui`).
- El layout se calcula en PHP; el JS solo previsualiza gestos. Lo que manda el navegador se vuelve a validar en el servidor.
- `dist/kore-calendar.js` se versiona: tras tocar `resources/js/`, `npm run build` y commitear el dist.
- Presupuesto del bundle en `scripts/bundle-size.mjs`. Subirlo es una decisión anotada, no un trámite.
- Los tests "cepo" de `tests/Ui/` son los mismos invariantes que kore-ui: no se relajan para que pase un componente.
- `fullcalendar/` (raíz del monorepo) es referencia de solo lectura. Lo portado lleva aviso en `THIRD-PARTY-NOTICES.md`.
