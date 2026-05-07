<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\SanctionsScreeningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScreenUserJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    public int $timeout = 30;

    public function __construct(
        public User   $user,
        public string $trigger = 'scheduled',
    ) {}

    public function handle(SanctionsScreeningService $service): void
    {
        $service->screen($this->user, $this->trigger);
    }
}

