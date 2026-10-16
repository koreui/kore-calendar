{{--
    A qué alcanza el cambio de una ocurrencia, dentro del overlay de kore-ui.

    Solo componentes de kore-ui. Los textos salen de kore-calendar.translations.
    El primer botón lleva `autofocus`: el overlay enfoca el primer elemento que lo
    lleve, y «Solo este evento» es la opción que menos estropea si se pulsa Intro sin leer.
--}}
@php
    $t = fn (string $key, array $replace = []) => \KoreUi\Calendar\Interaction\Messages::get($key, $replace);
@endphp

<div class="flex flex-col gap-4 p-6 text-start">
    <div class="flex flex-col gap-1">
        <h2 class="text-lg font-semibold text-kore-fg">{{ $koreHeading }}</h2>
        <p class="text-sm text-kore-muted-fg">{{ $t('scope_question', ['title' => $eventTitle]) }}</p>
    </div>

    <div class="flex flex-col gap-2">
        <x-kore::button type="button" variant="outline" color="secondary" block autofocus wire:click="choose('this')" :label="$t('scope_this')" />
        <x-kore::button type="button" variant="outline" color="secondary" block wire:click="choose('following')" :label="$t('scope_following')" />
        <x-kore::button type="button" variant="outline" color="secondary" block wire:click="choose('all')" :label="$t('scope_all')" />
    </div>

    <div class="flex justify-end">
        <x-kore::button type="button" variant="ghost" color="secondary" wire:click="cancel" :label="$t('form_cancel')" />
    </div>
</div>
