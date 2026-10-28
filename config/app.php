<?php

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Redis;
use Laracasts\Flash\Flash;

return [

    'aliases' => Facade::defaultAliases()->merge([
        'Flash' => Flash::class,
        'Menu' => Lavary\Menu\Facade::class,
        'Pusher' => Pusher\Pusher::class,
        'Redis' => Redis::class,
    ])->toArray(),

];
