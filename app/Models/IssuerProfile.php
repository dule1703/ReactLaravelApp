<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;

/**
 * The dealer that issues the offers: a single row edited by an admin (5.3). Each offer copies it when
 * it is made (IssuerSnapshot), so changing it later never changes an offer or its PDF. The PIB is a
 * sensitive field of the activity log (only its name is stored).
 */
class IssuerProfile extends Model
{
    use LogsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'address', 'postal_code', 'city', 'pib', 'phone', 'email'];

    /** The one row, or a new unsaved model when the admin has not entered the details yet. */
    public static function current(): self
    {
        return static::query()->orderBy('id')->first() ?? new static;
    }

    public function activityLabel(): string
    {
        return __('Offer issuer');
    }
}
