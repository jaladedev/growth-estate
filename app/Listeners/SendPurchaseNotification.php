<?php

namespace App\Listeners;

use App\Events\LandUnitsPurchased;
use App\Models\User;
use App\Notifications\PurchaseConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendPurchaseNotification implements ShouldQueue
{
    public function handle(LandUnitsPurchased $event): void
    {
        $user = User::find($event->userId);
        if (! $user) return;

        $user->notify(new PurchaseConfirmed(
            $event->landId,
            $event->units,
            $event->amountKobo
        ));
    }
}
