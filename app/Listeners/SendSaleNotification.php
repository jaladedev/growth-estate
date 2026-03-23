<?php

namespace App\Listeners;

use App\Events\LandUnitsSold;
use App\Models\User;
use App\Models\Transaction;
use App\Notifications\SaleConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendSaleNotification implements ShouldQueue
{
    public int $tries   = 3;
    public int $backoff = 60;

    public function handle(LandUnitsSold $event): void
    {
        $user = User::find($event->userId);
        if (! $user) return;

        $transaction = Transaction::where('reference', $event->reference)->first();
        if (! $transaction) {
            Log::warning('SendPurchaseNotification: transaction not found', [
                'reference' => $event->reference,
                'user_id'   => $event->userId,
            ]);
            return;
        }
        
        $user->notify(new SaleConfirmed($transaction));
    }
}