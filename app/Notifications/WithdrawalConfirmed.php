<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use App\Models\Withdrawal;

class WithdrawalConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    public int $backoff = 60;

    protected array $withdrawalData;

    public function __construct(Withdrawal $withdrawal)
    {
        $this->withdrawalData = [
            'id'          => $withdrawal->id,
            'reference'   => $withdrawal->reference,
            'amount_kobo' => $withdrawal->amount_kobo ?? 0,
            'bank_name'   => $withdrawal->user?->bank_name ?? null,
            'account'     => $withdrawal->user?->account_number
                             ? '****' . substr($withdrawal->user->account_number, -4)
                             : null,
            'date'        => now()->toFormattedDateString(),
        ];
    }

    public function via($notifiable): array
    {
        return ['mail', 'database', 'broadcast'];
    }

    public function toMail($notifiable): MailMessage
    {
        $amount    = number_format($this->withdrawalData['amount_kobo'] / 100, 2);
        $reference = $this->withdrawalData['reference'];
        $bank      = $this->withdrawalData['bank_name'];
        $account   = $this->withdrawalData['account'];
        $date      = $this->withdrawalData['date'];
        $appName   = config('app.name');
        $walletUrl = rtrim(config('app.frontend_url'), '/') . '/wallet';

        $mail = (new MailMessage)
            ->subject("Withdrawal of ₦{$amount} Processed")
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line("Your withdrawal has been successfully processed. Here are the details:")
            ->line("**Amount:** ₦{$amount}")
            ->line("**Reference:** {$reference}")
            ->line("**Date:** {$date}");

        if ($bank && $account) {
            $mail->line("**Sent to:** {$bank} — {$account}");
        }

        return $mail
            ->action('View Wallet', $walletUrl)
            ->line("Please allow 1–3 business days for the funds to appear in your bank account.")
            ->line("If you did not initiate this withdrawal, please contact support immediately.")
            ->salutation("Best regards, The {$appName} Team");
    }

    public function toDatabase($notifiable): array
    {
        $amount = number_format($this->withdrawalData['amount_kobo'] / 100, 2);

        return [
            'withdrawal_id' => $this->withdrawalData['id'],
            'reference'     => $this->withdrawalData['reference'],
            'amount_kobo'   => $this->withdrawalData['amount_kobo'],
            'status'        => 'completed',
            'message'       => "Your withdrawal of ₦{$amount} has been successfully processed.",
            'processed_at'  => now(),
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        $amount = number_format($this->withdrawalData['amount_kobo'] / 100, 2);

        return new BroadcastMessage([
            'withdrawal_id' => $this->withdrawalData['id'],
            'reference'     => $this->withdrawalData['reference'],
            'amount_kobo'   => $this->withdrawalData['amount_kobo'],
            'status'        => 'completed',
            'message'       => "Your withdrawal of ₦{$amount} has been successfully processed.",
            'processed_at'  => now(),
        ]);
    }

    public function toArray($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}