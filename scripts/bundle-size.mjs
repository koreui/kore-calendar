import { gzipSync } from 'node:zlib';
import { readFileSync } from 'node:fs';

/**
 * El presupuesto del bundle del calendario, en bytes gzip.
 *
 * Fijado en la fase 0 (docs/calendar.md §10), antes de escribir el primer
 * componente: un presupuesto que se pone cuando el código ya existe se ajusta
 * a lo que hay, no a lo que debería haber. El motor de layout vive en PHP; aquí
 * solo entra la interacción (arrastrar, redimensionar, seleccionar, teclado).
 *
 * Subirlo es una DECISIÓN, no un trámite: se anota debajo con el motivo medido.
 */
// Historial:
//   15 kB → presupuesto inicial (fase 0).
const BUDGET = 15_360;   // 15 kB

const FILE = 'dist/kore-calendar.js';
const kb = (n) => `${(n / 1024).toFixed(2)} kB`;
const size = gzipSync(readFileSync(FILE), { level: 9 }).length;

if (size > BUDGET) {
    console.error(
        `\n✗ ${FILE}: ${kb(size)} gzip — se pasa del presupuesto en ${kb(size - BUDGET)}.\n` +
        `  Presupuesto: ${kb(BUDGET)} (scripts/bundle-size.mjs).\n`
    );
    process.exit(1);
}

console.log(`✓ ${FILE}: ${kb(size)} gzip de ${kb(BUDGET)} (queda ${kb(BUDGET - size)})`);
