<?php

/*
 * Configuración de kore-ui/calendar.
 *
 * Archivo propio y no una sección de `kore-ui.php`: un segundo paquete no debe
 * depender de que la aplicación haya publicado la configuración de otro.
 *
 * Esquema completo en la spec (docs/calendar.md §13). En la fase 1 solo existe la
 * prueba técnica, que todavía no lee estas claves: cada una se conecta en la fase
 * que la usa, con su test. Las que no aparecen aquí aún no existen.
 */

return [

    /*
    | Vista con la que arranca un calendario que no dice otra cosa.
    */
    'default_view' => 'month',

    /*
    | Zona horaria de la vista. null = config('app.timezone').
    */
    'timezone' => null,

    /*
    | Primer día de la semana (0 = domingo … 6 = sábado). null = el mismo que el
    | datepicker de kore-ui, para que la rejilla y el selector de fecha coincidan.
    */
    'first_day' => null,

    'time' => [
        'slot_duration' => '30m',
        'snap' => null,           // null = slot_duration
        'slot_min_time' => '00:00',
        'slot_max_time' => '24:00',
    ],

    /*
    | Textos de interfaz. Ninguna vista lleva texto fijo (lo vigila
    | tests/Ui/TextosEnInglesTest.php).
    */
    'translations' => [
        // Vacío hasta la fase 3: la prueba técnica no tiene texto de interfaz.
    ],

];
