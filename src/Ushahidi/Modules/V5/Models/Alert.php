<?php

namespace Ushahidi\Modules\V5\Models;

class Alert extends BaseModel
{
    public $timestamps = false;
    protected $table = 'alerts';
    protected $fillable = [
        'radius', 'latitude', 'longitude', 'location', 'email', 'categories',
        'status', 'hash', 'created', 'updated',
    ];
}
