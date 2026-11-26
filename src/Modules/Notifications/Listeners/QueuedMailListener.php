<?php

namespace Modules\Notifications\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;


abstract class QueuedMailListener implements ShouldQueue
{
    use InteractsWithQueue;

    
    public int $tries = 3;

  
    public function backoff(): array
    {
        return [10, 30];
    }

   
    protected function failIfSimulated(): void
    {
        if (config('notifications.simulate_mail_failure') && app()->environment('local')) {
            throw new RuntimeException('Simulated e-mail service failure.');
        }
    }
}