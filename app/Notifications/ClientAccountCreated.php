<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email a client gets when an admin made their account: a link to set the password (the
 * standard password broker token and the standard reset route) and nothing else. There is no
 * password in it, because nobody knows one.
 *
 * Deliberately NOT queued (no ShouldQueue): the queue cron (0.10) is not set up on the shared
 * hosting yet, so a queued mail would never leave. Sending is synchronous, and the caller handles
 * a failure.
 */
class ClientAccountCreated extends Notification
{
    public function __construct(public readonly string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject(__('Your Škoda configurator account'))
            ->greeting(__('Hello, :name!', ['name' => $notifiable->name]))
            ->line(__('An account has been created for you. Set your password with the button below to sign in and see your offers.'))
            ->action(__('Set password'), $this->url($notifiable))
            ->line(__('The link is valid for :minutes minutes. After that, use "Forgot your password?" on the sign-in page: :url', [
                'minutes' => $minutes,
                'url' => url(route('password.request', absolute: false)),
            ]))
            ->line(__('If you did not expect this email, you can ignore it.'));
    }

    private function url(object $notifiable): string
    {
        return url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));
    }
}
