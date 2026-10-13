<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StoreMail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $subject, public array $lines, public ?string $url = null, public string $label = 'View details')
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject);
        foreach ($this->lines as $line) {
            $mail->line($line);
        }
        if ($this->url) {
            $mail->action($this->label, $this->url);
        }

        return $mail;
    }
}
