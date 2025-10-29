<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SaleConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    protected $purchase;

    public function __construct(Model $purchase)
    {
        $this->purchase = $purchase;
    }

    // Delivery channels
    public function via($notifiable)
    {
        return ['mail', 'database', 'broadcast'];
    }

    // Database representation
    public function toDatabase($notifiable)
    {
        return [
            'purchase_id' => $this->purchase->id,
            'units' => $this->purchase->units,
            'total_amount_received' => $this->purchase->amount,
            'message' => 'Your sale has been confirmed!',
            'created_at' => now(),
        ];
    }

    // Optional: Email representation
    public function toMail($notifiable)
    {
        return (new MailMessage)
                    ->subject('Sale Confirmation')
                    ->line('Your sale of ' . $this->purchase->units . ' units has been confirmed!')
                    ->action('View Sale', url('/purchases/' . $this->purchase->id))
                    ->line('Thank you for your transacting with us!');
    }

    public function toArray($notifiable)
    {
        return $this->toDatabase($notifiable);
    }
    // public function toBroadcast($notifiable)
    // {
    //     return new BroadcastMessage([
    //         'message' => 'Your purchase of ' . $this->purchase->units . ' units has been confirmed!'
    //     ]);
    // }
}