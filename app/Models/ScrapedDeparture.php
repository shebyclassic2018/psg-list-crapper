<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScrapedDeparture extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_slug',
        'oacl_bus_id',
        'bus_name',
        'origin',
        'destination',
        'travel_date',
        'departure_time',
        'departure_at',
        'upload_before_status',
        'upload_before_attempted_at',
        'upload_after_status',
        'upload_after_attempted_at',
    ];

    protected $casts = [
        'departure_at' => 'datetime',
        'upload_before_attempted_at' => 'datetime',
        'upload_after_attempted_at' => 'datetime',
    ];
}
