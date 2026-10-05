<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Support\Vat;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Admin-editable key/value settings. Changing the VAT rate never touches stored net prices;
 * an offer (phase 4) snapshots the rate when it is created.
 */
class Setting extends Model
{
    use LogsActivity;

    public const VAT_RATE_BP = 'vat_rate_bp';

    /** Used only if the row is missing (the migration creates it). */
    public const DEFAULT_VAT_RATE_BP = 2000;

    /**
     * @var list<string>
     */
    protected $fillable = ['key', 'value'];

    /**
     * Current VAT rate in basis points (2000 = 20%). Lenient on purpose (it only shows the value
     * on admin screens); an offer snapshots the rate with the strict App\Support\VatRate::current().
     */
    public static function vatRateBp(): int
    {
        $value = static::where('key', self::VAT_RATE_BP)->value('value');

        return $value === null ? self::DEFAULT_VAT_RATE_BP : (int) $value;
    }

    public static function setVatRateBp(int $rateBp): void
    {
        if ($rateBp < Vat::RATE_MIN_BP || $rateBp > Vat::RATE_MAX_BP) {
            throw new InvalidArgumentException('The VAT rate must be between 0 and 10000 basis points.');
        }

        // Through the model, so the change is logged with the old and new value.
        static::updateOrCreate(['key' => self::VAT_RATE_BP], ['value' => (string) $rateBp]);
    }

    public function activityLabel(): string
    {
        return 'Setting '.$this->key;
    }
}
