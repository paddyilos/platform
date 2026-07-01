<?php

namespace Ushahidi\Modules\V5\Models;

class AnalysisTemplate extends BaseModel
{
    public $timestamps = false;
    protected $table = 'analysis_templates';
    protected $fillable = [
        'name', 'user_id', 'form_id', 'date_range_start', 'date_range_end',
        'group_by', 'group_by_attribute_key', 'chart_type', 'filters',
        'created', 'updated',
    ];
    protected $casts = [
        'filters' => 'array',
    ];
}
