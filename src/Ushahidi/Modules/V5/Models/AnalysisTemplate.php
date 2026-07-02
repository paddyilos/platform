<?php

namespace Ushahidi\Modules\V5\Models;

class AnalysisTemplate extends BaseModel
{
    public $timestamps = false;
    protected $table = 'analysis_templates';
    protected $fillable = [
        'name', 'user_id', 'form_id', 'date_range_start', 'date_range_end',
        'group_by', 'group_by_attribute_key', 'chart_type',
        'report_config', 'status_filter', 'tags_filter',
        'created', 'updated',
    ];
    protected $casts = [
        'report_config' => 'array',
        'status_filter' => 'array',
        'tags_filter' => 'array',
    ];
}
