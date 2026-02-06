<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

use App\Events\LandUnitsPurchased;
use App\Events\LandUnitsSold;
use App\Events\LandPriceChanged;

use App\Listeners\ClearLandCache;
use App\Listeners\SendPurchaseNotification;
use App\Listeners\SendSaleNotification;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [

        LandUnitsPurchased::class => [
            ClearLandCache::class,
            SendPurchaseNotification::class,
        ],

        LandUnitsSold::class => [
            ClearLandCache::class,
            SendSaleNotification::class,
        ],

        LandPriceChanged::class => [
            ClearLandCache::class,
        ],
    ];
}
