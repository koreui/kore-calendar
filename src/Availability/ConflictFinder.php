<?php

namespace KoreUi\Calendar\Availability;

use KoreUi\Calendar\Events\EventInstance;

/**
 * Qué eventos ya se pisan entre sí. Puro: recibe instancias y devuelve pares, sin
 * tocar la configuración, la base de datos ni la vista.
 *
 * Las reglas son las de `MutationValidator` (están en `Collision`): hay conflicto
 * entre dos eventos si se solapan en el tiempo, comparten carril (el mismo recurso;
 * los que no tienen ninguno, entre sí) y uno de los dos bloquea al otro. Quedan
 * fuera el propio evento (las ocurrencias de una serie comparten id) y los del
 * mismo `groupId`, que se mueven juntos y no colisionan.
 *
 * Por defecto cualquier solape entre eventos que ocupan sitio es un conflicto,
 * aunque el calendario permita solapar (`eventOverlap`): quien pregunta por los
 * conflictos quiere verlos. Con `$overlapAllowed = true` solo cuentan los que
 * declaran `overlap: false`, que es lo que el servidor impediría al soltar.
 *
 * Un evento de fondo (`display: background` o `none`) no ocupa sitio: solo está en
 * conflicto si dice `overlap: false`.
 */
final class ConflictFinder
{
    /**
     * Más allá de este margen un evento no puede solaparse con otro. Los markers
     * son horas de pared y en el pliegue de otoño dos instantes que SÍ se solapan
     * pueden tener markers que no; un día sobra para cualquier zona.
     */
    private const SLACK = 86_400;

    /**
     * Cada par una sola vez, ordenado por el inicio del primero y luego del segundo.
     *
     * @param  list<EventInstance>  $instances
     * @return list<array{0: EventInstance, 1: EventInstance}>
     */
    public static function find(array $instances, bool $overlapAllowed = false): array
    {
        usort($instances, fn (EventInstance $a, EventInstance $b) => [$a->range->start->ts, $a->instanceId] <=> [$b->range->start->ts, $b->instanceId]);

        $pairs = [];
        $total = count($instances);

        for ($i = 0; $i < $total; $i++) {
            $a = $instances[$i];

            for ($j = $i + 1; $j < $total; $j++) {
                $b = $instances[$j];

                // Ordenados por inicio: pasado el fin de `$a` (con margen), ningún siguiente lo toca.
                if ($b->range->start->ts >= $a->range->end->ts + self::SLACK) {
                    break;
                }

                if (self::conflicts($a, $b, $overlapAllowed)) {
                    $pairs[] = [$a, $b];
                }
            }
        }

        return $pairs;
    }

    /**
     * Dos instancias en conflicto, sin mirar el resto.
     */
    public static function conflicts(EventInstance $a, EventInstance $b, bool $overlapAllowed = false): bool
    {
        $x = $a->event;
        $y = $b->event;

        // Las ocurrencias de una serie, y los trozos de un evento con varios recursos, comparten id.
        if ((string) $x->id === (string) $y->id || Collision::sameGroup($x, $y)) {
            return false;
        }

        if (! Collision::overlap($a, $b)) {
            return false;
        }

        // `overlap: false` choca con todo lo que comparte su carril, sea de día
        // completo o con hora: es la forma de decir «aquí no se pone nada».
        if (Collision::sharesLane($x->resourceIds, $y->resourceIds) && ($x->overlap === false || $y->overlap === false)) {
            return Collision::conflict($x, $y, ! $overlapAllowed);
        }

        // Solape «de agenda»: mismo recurso, o los dos sin recurso. Un evento de día
        // completo (vacaciones, un congreso) es contexto, no una cita: no choca con lo
        // que pasa ese día. Sin esto, unas vacaciones marcaban en rojo media semana.
        if ($a->allDay || $b->allDay) {
            return false;
        }

        return Collision::sharesLane($x->resourceIds, $y->resourceIds)
            && Collision::conflict($x, $y, ! $overlapAllowed);
    }

    /**
     * Los pares vistos desde cada evento: `instanceId` → los eventos con los que choca.
     * Es lo que necesita una vista para marcar un evento y decir con quién.
     *
     * @param  list<array{0: EventInstance, 1: EventInstance}>  $pairs
     * @return array<string, list<EventInstance>>
     */
    public static function byInstance(array $pairs): array
    {
        $map = [];

        foreach ($pairs as [$a, $b]) {
            $map[$a->instanceId][] = $b;
            $map[$b->instanceId][] = $a;
        }

        return $map;
    }
}
