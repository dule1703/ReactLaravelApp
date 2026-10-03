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

    public function activityLabel(): string
    {
        return __('Client profile').' #'.$this->getKey();
    }
}
