<?php

namespace KoreUi\Calendar\Interaction;

/**
 * Los límites de fecha que el servidor acepta de un navegador.
 *
 * Un marker llega como un entero cualquiera. Sin tope, `PHP_INT_MAX` llegaría
 * a `WallClock::toInstant()` y de ahí a la base de datos del consumidor, donde
 * un DATETIME de MySQL no pasa del año 9999. Los años 1-9999 son los que PHP
 * formatea con cuatro cifras: dentro de ese rango todo lo que hace el motor
 * (sumar, redondear, formatear) es seguro.
 */
final class Bounds
{
    /** 0001-01-01 00:00. */
    public const MIN = -62_135_596_800;

    /** 10000-01-01 00:00 (exclusivo): el primer segundo que ya no cabe. */
    public const MAX = 253_402_300_800;

    public static function contains(int ...$markers): bool
    {
        foreach ($markers as $ts) {
            if ($ts < self::MIN || $ts >= self::MAX) {
                return false;
            }
        }

        return true;
    }
}
