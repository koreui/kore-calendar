<?php

namespace KoreUi\Calendar\Interaction;

use RuntimeException;

/**
 * Un hook de persistencia dijo que no (devolvió `false` o un texto).
 *
 * Existe para deshacer la transacción: `DB::transaction()` solo hace rollback
 * cuando el cierre lanza, y el hook puede haber escrito algo antes de negarse.
 * El mensaje es el motivo, ya listo para mostrar.
 */
final class MutationRejected extends RuntimeException {}
