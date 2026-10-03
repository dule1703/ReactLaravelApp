<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use App\Models\Concerns\LogsActivity;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, LogsActivity, Notifiable;

    /**
     * Default attribute values. `role` is deliberately not in $fillable.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'client',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    /**
     * @return HasOne<ClientProfile, $this>
     */
    public function clientProfile(): HasOne
    {
        return $this->hasOne(ClientProfile::class);
    }

    /**
     * The client's profile, created on demand: a client registered before profiles existed
     * (e.g. on a rolled-back release) has none. Admins have no profile.
     */
    public function profile(): ?ClientProfile
    {
        if (! $this->isClient()) {
            return null;
        }

        try {
            return $this->clientProfile()->firstOrCreate([], ['full_name' => $this->name]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created it first.
            return $this->clientProfile()->firstOrFail();
        }
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isClient(): bool
    {
        return $this->role === UserRole::Client;
    }

    public function activityLabel(): string
    {
        return __($this->isAdmin() ? 'Administrator' : 'Client').' #'.$this->getKey().' '.$this->name;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function activityAction(string $event, array $changes): string
    {
        if ($event === 'updated' && array_keys($changes) === ['password']) {
            return 'auth.password_changed';
        }

        return 'user.'.$event;
    }
}
