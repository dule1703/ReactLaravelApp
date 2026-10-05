<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The last assigned offer sequence number of one year. Technical table: it is changed only by
 * App\Services\OfferNumber (with atomic SQL, never through the model) and deliberately without
 * LogsActivity: the log entry belongs to the offer that received the number.
 */
class OfferCounter extends Model
{
    protected $primaryKey = 'year';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
