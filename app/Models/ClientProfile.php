<?php

namespace App\Models;

use App\Enums\ClientType;
use App\Models\Concerns\LogsActivity;
use App\Support\Jmbg;
use Database\Factories\ClientProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * JMBG is stored encrypted; `jmbg_hash` allows exact lookup. Change JMBG only through the
 * model (never the query builder), otherwise the hash gets out of sync.
 */
class ClientProfile extends Model
{
    /** @use HasFactory<ClientProfileFactory> */
    use HasFactory, LogsActivity;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'individual',
        'country' => 'RS',
    ];

    /**
     * `user_id` and `jmbg_hash` are deliberately not mass-assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'full_name',
        'jmbg',
        'pib',
        'address',
        'postal_code',
        'city',
        'country',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'jmbg',
        'jmbg_hash',
    ];

    protected function casts(): array
    {
        return [
            'type' => ClientType::class,
            'jmbg' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $profile) {
            if ($profile->isDirty('jmbg')) {
                $profile->jmbg_hash = Jmbg::hash($profile->jmbg);
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Display mask: first 4 and last 3 characters, e.g. 0101******008. Never use the mask
     * as an input value; the full JMBG is only ever entered, not echoed back.
     */
    public function maskedJmbg(): ?string
    {
        $jmbg = $this->jmbg;

        if ($jmbg === null || $jmbg === '') {
            return null;
        }

        $length = strlen($jmbg);

        return $length > 7
            ? substr($jmbg, 0, 4).str_repeat('*', $length - 7).substr($jmbg, -3)
            : str_repeat('*', $length);
    }

    /**
     * Display mask: first 2 and last 2 characters, e.g. 10*****01.
     */
    public function maskedPib(): ?string
    {
        $pib = $this->pib;

        if ($pib === null || $pib === '') {
            return null;
        }

        $length = strlen($pib);

        return $length > 4
            ? substr($pib, 0, 2).str_repeat('*', $length - 4).substr($pib, -2)
            : str_repeat('*', $length);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function activityAction(string $event, array $changes): string
    {
        if ($event === 'updated' && isset($changes['jmbg']) && $this->jmbg === null) {
            return 'client_profile.jmbg_deleted';
        }

        return 'client_profile.'.$event;
    }

    public function activityLabel(): string
    {
        return __('Client profile').' #'.$this->getKey();
    }
}
