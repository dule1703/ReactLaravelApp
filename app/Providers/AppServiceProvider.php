<?php

namespace App\Providers;

use App\Listeners\ActivityLogSubscriber;
use App\Services\Backup\BackupStorage;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Named limiters (6.3): every name has its OWN counter. A numeric `throttle:N,1` has no name, so
     * all such routes of one user (or IP) share one counter: ten catalog reads would use up the
     * budget of saving an offer. Key: the user id when signed in, otherwise the IP.
     */
    public const LIMITERS = [
        // name => attempts per minute
        'login-ip' => 20,         // POST /login, per IP (LoginRequest limits email + IP, 5 attempts)
        'auth-guest' => 10,       // register, reset password
        'auth-forgot' => 5,       // forgot password (sends an email)
        'password-sensitive' => 10, // confirm / change password
        'verification' => 6,      // verification email and link
        'writes' => 30,           // state changes: profile, offer note, withdraw, revert, delete, restore
        'pdf' => 30,              // offer PDF
        'offers-store' => 10,     // saving an offer
        'catalog-read' => 60,     // configurator JSON
        'client-create' => 20,    // admin creates a client
        'client-reveal' => 20,    // admin reveals a JMBG / PIB
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The backup folder and the environment come from config/backup.php and APP_ENV (7.2).
        $this->app->bind(BackupStorage::class, fn () => BackupStorage::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Event::subscribe(ActivityLogSubscriber::class);

        foreach (self::LIMITERS as $name => $perMinute) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($perMinute)
                ->by($name === 'login-ip' || $request->user() === null ? $request->ip() : $request->user()->getAuthIdentifier()));
        }
    }
}
