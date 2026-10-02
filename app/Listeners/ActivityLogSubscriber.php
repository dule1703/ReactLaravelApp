<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;

/**
 * Logs authentication events. Password changes and profile edits are logged by the
 * LogsActivity trait on User.
 */
class ActivityLogSubscriber
{
    public function __construct(private ActivityLogger $logger) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, [self::class, 'onLogin']);
        $events->listen(Logout::class, [self::class, 'onLogout']);
        $events->listen(Failed::class, [self::class, 'onFailed']);
        $events->listen(PasswordReset::class, [self::class, 'onPasswordReset']);
    }

    public function onLogin(Login $event): void
    {
        if ($event->user instanceof User) {
            $this->logger->log('auth.login', $event->user, actor: $event->user);
        }
    }

    public function onLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->logger->log('auth.logout', $event->user, actor: $event->user);
        }
    }

    /**
     * The password is never read. The typed email is stored only if it is a valid address.
     */
    public function onFailed(Failed $event): void
    {
        $email = $event->credentials['email'] ?? null;
        $valid = is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;

        $this->logger->log(
            'auth.login_failed',
            $event->user instanceof User ? $event->user : null,
            $valid
                ? __('Failed login attempt for :email', ['email' => $email])
                : __('Failed login attempt with an invalid input'),
            actorType: 'guest',
        );
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            $this->logger->log('auth.password_reset', $event->user, actor: $event->user);
        }
    }
}
