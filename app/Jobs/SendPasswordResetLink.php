<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Password;

class SendPasswordResetLink implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $email)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        Password::sendResetLink(['email' => $this->email]);
    }
}
