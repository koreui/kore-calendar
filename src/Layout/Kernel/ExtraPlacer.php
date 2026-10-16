<?php

namespace KoreUi\Calendar\Layout\Kernel;

/**
 * Reparte los trozos que no cupieron ("extras") sobre un conjunto fijo de
 * niveles lógicos. Es el corazón del límite de eventos por celda.
 *
 * Port de `placeExtraSlicesInLevels`, `computeLevelFence`, `findInsertionLevel`
 * y `findBestSlicePlan` de `seg-placement/kernel.ts` (FullCalendar v7.1.3, MIT).
 *
 * Lo que NO se porta, a propósito: los parámetros `requiresSlicing` y
 * `taxDeepestOccupiedLevel`. Solo los usa el modo por píxeles
 * (`buildPixelLimitedLayout`), que mide alturas en el navegador y no existe en
 * el servidor (docs/calendar.md §7.2: el límite es solo numérico).
 *
 * ITERATIVO, sin recursión. FullCalendar #7462 dio un bucle infinito con
 * `dayMaxEventRows` y muchas filas ocultas cuando este reparto era recursivo.
 * Aquí el trabajo pendiente vive en una pila explícita que alterna entre
 * "intentar colocar un trozo" y "reservar espacio para un enlace". Es LIFO a
 * propósito: las reservas recién creadas se resuelven antes que los extras
 * antiguos, de modo que un extra no puede meterse en un hueco que una reserva
 * está a punto de reclamar. Cada paso consume un elemento y solo apila trozos
 * estrictamente más pequeños (restos) o reservas sobre cobertura nueva, así que
 * termina siempre.
 *
 * @internal
 */
final class ExtraPlacer
{
    private const MAX_SLICES_PER_PLAN = 3;

    /** Cada tramo extra de un plan resta esto: menos fragmentos, mejor. */
    private const EXTRA_SLICE_PENALTY = 0.15;

    /** @var list<list<Slice>> */
    private array $levels;

    /** @var list<Slice> */
    private array $hidden = [];

    /** @var list<RawHiddenGroup> */
    private array $groups = [];

    /** @var list<LevelSpan> */
    private array $reservations = [];

    /** @var array<int, Slice> Conjunto con orden de inserción, por id de objeto. */
    private array $added = [];

    /** @var list<Slice|Lateral> Un Slice es "colocar"; un Lateral es "reservar el enlace". */
    private array $work = [];

    /**
     * @param  list<list<Slice>>  $sliceLevels
     */
    public function __construct(
        array $sliceLevels,
        private readonly bool $strict,
        private readonly bool $slicing,
        private readonly int $tax,
    ) {
        $this->levels = $sliceLevels;
    }

    /**
     * PRECONDICIÓN: `$extras` viene ordenado por orden de evento.
     *
     * @param  list<Slice>  $extras
     */
    public function run(array $extras): ExtraSlicePlacement
    {
        $this->pushFire($extras);

        while ($this->work !== []) {
            $item = array_pop($this->work);

            if ($item instanceof Slice) {
                $this->fire($item);
            } else {
                $this->fireMoreLink($item);
            }
        }

        return new ExtraSlicePlacement($this->levels, $this->hidden, array_values($this->added));
    }

    /** Prueba la inserción entera permitida antes que los planes de cortes puntuados. */
    private function fire(Slice $slice): void
    {
        $levelIndex = $this->findInsertionLevel($slice);

        if ($levelIndex !== null) {
            SpanMath::insertLaterally($this->levels[$levelIndex], $slice);
            $this->added[spl_object_id($slice)] = $slice;

            return;
        }

        if (! $this->slicing) {
            $this->hide($slice);

            return;
        }

        $plan = $this->findBestSlicePlan($slice);

        if ($plan === null) {
            $this->hide($slice);

            return;
        }

        foreach ($plan['slices'] as $visible) {
            SpanMath::insertLaterally($this->levels[$plan['levelIndex']], $visible);
            $this->added[spl_object_id($visible)] = $visible;
        }

        foreach ($this->subtractFromSlice($slice, $plan['slices']) as $hiddenSlice) {
            $this->hide($hiddenSlice);
        }
    }

    /** Añade la pertenencia oculta y dispara enlaces solo sobre la cobertura nueva del acumulador. */
    private function hide(Slice $slice): void
    {
        $this->hidden[] = $slice;

        // Solo la diferencia de conjuntos es territorio nuevo del enlace; puede
        // ser varios tramos disjuntos.
        $newMoreLinkSpans = HiddenGroups::add($this->groups, $slice);

        if ($this->tax > 0) {
            // Al revés, para que el primero salga antes de la pila LIFO.
            for ($i = count($newMoreLinkSpans) - 1; $i >= 0; $i--) {
                $this->work[] = $newMoreLinkSpans[$i];
            }
        }
    }

    /**
     * Reserva el ÚLTIMO nivel global sobre una cobertura de enlace nueva, y
     * desaloja a quien lo ocupe ahí: el enlace "+N más" cuenta como una fila más
     * (`dayMaxEventRows`). Con `slicing` el desalojado solo se oculta bajo el
     * enlace y el resto vuelve a la cola, que puede encontrarle otro hueco.
     */
    private function fireMoreLink(Lateral $span): void
    {
        if ($this->levels === []) {
            return;
        }

        $taxedLevelIndex = count($this->levels) - 1;
        $victims = SpanMath::findIntersections($this->levels[$taxedLevelIndex], $span);

        SpanMath::insertLaterally(
            $this->reservations,
            new LevelSpan($span->start, $span->end, $taxedLevelIndex),
        );

        foreach ($victims as $victim) {
            $position = array_search($victim, $this->levels[$taxedLevelIndex], true);
            array_splice($this->levels[$taxedLevelIndex], $position, 1);
            unset($this->added[spl_object_id($victim)]);

            $covered = $this->slicing ? $this->intersectSlice($victim, $span) : null;

            if ($covered === null) {
                $this->hide($victim);

                continue;
            }

            $this->hide($covered);
            // El resto ya cumplió el requisito de cortar: va a la cola como un extra más.
            $this->pushFire($this->subtractFromSlice($victim, [$span]));
        }
    }

    /**
     * Apilar al revés conserva el orden recibido en una pila LIFO.
     *
     * @param  list<Slice>  $slices
     */
    private function pushFire(array $slices): void
    {
        for ($i = count($slices) - 1; $i >= 0; $i--) {
            $this->work[] = $slices[$i];
        }
    }

    /** El nivel vacío más superficial dentro de la valla del trozo, si hay alguno. */
    private function findInsertionLevel(Slice $slice): ?int
    {
        [$min, $maxExclusive] = $this->computeLevelFence($slice);

        for ($levelIndex = $min; $levelIndex < $maxExclusive; $levelIndex++) {
            if (SpanMath::findIntersections($this->levels[$levelIndex], $slice) === []) {
                return $levelIndex;
            }
        }

        return null;
    }

    /**
     * El rango de niveles donde un trozo puede estar legalmente.
     *
     * La reserva de un enlace cierra su nivel y todos los más profundos sobre la
     * cobertura. El orden estricto añade una valla contra el orden de los vecinos
     * que intersecan, y es de DOS lados: reparar extras dispara trozos rechazados
     * cuando otros de orden posterior ya están comprometidos, así que "quédate por
     * debajo de todo lo que tocas" no basta (el kernel original, SegHierarchy,
     * insertaba todo en orden y solo necesitaba la valla inferior).
     *
     * @return array{0: int, 1: int} [mínimo, máximo exclusivo]
     */
    private function computeLevelFence(Slice $slice): array
    {
        $min = 0;
        $maxExclusive = count($this->levels);

        foreach (SpanMath::findIntersections($this->reservations, $slice) as $reservation) {
            $maxExclusive = min($maxExclusive, $reservation->levelIndex);
        }

        if ($this->strict) {
            foreach ($this->levels as $levelIndex => $level) {
                foreach (SpanMath::findIntersections($level, $slice) as $other) {
                    if ($other->source->orderIndex < $slice->source->orderIndex) {
                        $min = max($min, $levelIndex + 1);
                    } elseif ($other->source->orderIndex > $slice->source->orderIndex) {
                        $maxExclusive = min($maxExclusive, $levelIndex);
                    }
                }
            }
        }

        return [$min, $maxExclusive];
    }

    /**
     * Puntúa el mejor plan de uno, dos o tres tramos que ofrece cada nivel. Los
     * tramos de niveles distintos nunca se mezclan en un plan: un evento cortado
     * entre filas distintas se leería como varios eventos.
     *
     * `puntuación = visible/total − 0.15·(tramos−1)`. Ejemplo (kernel-correctness):
     * `base[1,2)` en el nivel 0 y `extra[0,3)` sobrante; dos tramos exponen 2/3
     * (0.667 − 0.15 = 0.517) y uno solo 1/3: gana cortar en dos.
     *
     * @return array{levelIndex: int, slices: list<Slice>, score: float}|null
     */
    private function findBestSlicePlan(Slice $slice): ?array
    {
        $selected = null;
        $sourceLength = SpanMath::length($slice);

        foreach (array_keys($this->levels) as $levelIndex) {
            // findIntersections devuelve una lista nueva ordenada por inicio y
            // addToUnion reemplaza su contenido sin tocar a los miembros: se
            // pueden mezclar las reservas sin tocar el nivel real.
            $blockers = SpanMath::findIntersections($this->levels[$levelIndex], $slice);

            foreach ($this->reservations as $reservation) {
                if ($levelIndex >= $reservation->levelIndex) {
                    SpanMath::addToUnion($blockers, $reservation);
                }
            }

            $runs = array_values(array_filter(
                $this->subtractFromSlice($slice, $blockers),
                fn (Slice $run): bool => $this->isWithinLevelFence($run, $levelIndex),
            ));
            // Los tramos más largos primero; a igual largo, el que empieza antes.
            usort($runs, fn (Slice $a, Slice $b): int => (SpanMath::length($b) <=> SpanMath::length($a))
                ?: ($a->start <=> $b->start));

            $visibleLength = 0;

            for ($sliceCount = 1, $max = min(self::MAX_SLICES_PER_PLAN, count($runs)); $sliceCount <= $max; $sliceCount++) {
                $visibleLength += SpanMath::length($runs[$sliceCount - 1]);

                $candidate = [
                    'levelIndex' => $levelIndex,
                    'slices' => array_slice($runs, 0, $sliceCount),
                    'score' => ($sourceLength > 0 ? $visibleLength / $sourceLength : 0.0)
                        - self::EXTRA_SLICE_PENALTY * ($sliceCount - 1),
                ];

                if (self::isBetterPlan($candidate, $selected)) {
                    $selected = $candidate;
                }
            }
        }

        if ($selected !== null) {
            usort($selected['slices'], PlacementKernel::compareByEventOrder(...));
        }

        return $selected;
    }

    /** ¿Puede este trozo estar en este nivel, según su propia valla? */
    private function isWithinLevelFence(Slice $slice, int $levelIndex): bool
    {
        [$min, $maxExclusive] = $this->computeLevelFence($slice);

        return $levelIndex >= $min && $levelIndex < $maxExclusive;
    }

    /**
     * Desempate entre planes: la puntuación, luego menos fragmentación, luego el
     * nivel más superficial. El orden es determinista: un mismo render siempre
     * produce el mismo plan.
     *
     * @param  array{levelIndex: int, slices: list<Slice>, score: float}  $candidate
     * @param  array{levelIndex: int, slices: list<Slice>, score: float}|null  $current
     */
    private static function isBetterPlan(array $candidate, ?array $current): bool
    {
        if ($current === null || $candidate['score'] > $current['score']) {
            return true;
        }
        if ($candidate['score'] < $current['score']) {
            return false;
        }
        if (count($candidate['slices']) !== count($current['slices'])) {
            return count($candidate['slices']) < count($current['slices']);
        }

        return $candidate['levelIndex'] < $current['levelIndex'];
    }

    /**
     * Resta tramos cubiertos a un trozo y devuelve los restos como trozos que
     * conservan la fuente. Los cubiertos van ordenados por inicio y sin solapes.
     *
     * @param  list<object>  $covered
     * @return list<Slice>
     */
    private function subtractFromSlice(Slice $slice, array $covered): array
    {
        return array_map(
            fn (Lateral $span): Slice => $slice->narrower($span->start, $span->end),
            SpanMath::subtractCovered($slice, $covered),
        );
    }

    /** La intersección estricta con una barrera, conservando la fuente. */
    private function intersectSlice(Slice $slice, object $barrier): ?Slice
    {
        $intersection = SpanMath::intersect($slice, $barrier);

        return $intersection === null ? null : $slice->narrower($intersection->start, $intersection->end);
    }
}
