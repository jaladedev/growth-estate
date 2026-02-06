<?php

namespace App\Listeners;

use App\Events\LandUnitsSold;
use App\Models\User;
use App\Notifications\SaleConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendSaleNotification implements ShouldQueue
{
    public function handle(LandUnitsSold $event): void
    {
        $user = User::find($event->userId);
        if (! $user) return;

        $user->notify(new SaleConfirmed(
            $event->landId,
            $event->units,
            $event->amountKobo
        ));
    }
}
