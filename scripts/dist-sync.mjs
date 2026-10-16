import { readFileSync } from 'node:fs';

/**
 * Verifica que el bundle versionado en dist/ registra lo mismo que las fuentes.
 *
 * dist/kore-calendar.js va dentro del paquete y el ServiceProvider lo sirve tal
 * cual: la aplicación que instala el calendario no construye nada. Si el dist se
 * queda atrás, la Blade llega con un x-data que el navegador no conoce. Le pasó a
 * kore-ui en la v1.7.0.
 *
 * Los componentes se registran desde el mapa COMPONENTES de index.js, así que se
 * leen sus claves en vez de buscar llamadas a Alpine.data().
 *
 * En CI corre ANTES de `npm run build`: después, el dist siempre está fresco.
 */
const ENTRY = 'resources/js/index.js';
const BUNDLE = 'dist/kore-calendar.js';

const source = readFileSync(ENTRY, 'utf8');
const bundle = readFileSync(BUNDLE, 'utf8');

const mapa = source.match(/const COMPONENTES = \{([\s\S]*?)\};/);
const registered = mapa ? [...mapa[1].matchAll(/^\s*(\w+)\s*[,:]/gm)].map((m) => m[1]) : [];

if (registered.length === 0) {
    console.error(`\n✗ No se encontró el mapa COMPONENTES en ${ENTRY}. ¿Cambió el formato del entry?\n`);
    process.exit(1);
}

// Minificado, el nombre con el que se registra sobrevive como clave del objeto.
const missing = registered.filter((name) => !new RegExp(`\\b${name}\\s*:`).test(bundle) && !bundle.includes(`"${name}"`));

if (missing.length > 0) {
    console.error(
        `\n✗ ${BUNDLE} está desincronizado de ${ENTRY}.\n` +
        `  Registrados en la fuente pero ausentes del bundle:\n` +
        missing.map((n) => `    - ${n}`).join('\n') +
        `\n\n  El dist se versiona: ejecuta \`npm run build\` y commitea ${BUNDLE}.\n`
    );
    process.exit(1);
}

console.log(`✓ ${BUNDLE}: los ${registered.length} componentes de ${ENTRY} están en el bundle`);
