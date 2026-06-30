<?php

namespace Ushahidi\Modules\V5\Models;

class ContactUs extends BaseModel
{
    public $timestamps = false;
    protected $table = 'contact_us';
    protected $fillable = [
        'name', 'email', 'phone_number', 'subject', 'message', 'status', 'created', 'updated',
    ];
}
