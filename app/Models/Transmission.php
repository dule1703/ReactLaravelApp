<?php

namespace App\Models;

use App\Enums\DriveType;
use App\Enums\TransmissionType;
use App\Models\Concerns\LogsActivity;
use Database\Factories\TransmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Shared by all car models. `drive` (fwd/awd) lives here because 4x4 is chosen together with
 * the transmission.
 */
class Transmission extends Model
{
    /** @use HasFactory<TransmissionFactory> */
    use HasFactory, LogsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'type', 'drive', 'is_active'];

    protected function casts(): array
    {
        return [
            'type' => TransmissionType::class,
            'drive' => DriveType::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Version, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(Version::class);
    }
}
