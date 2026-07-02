<?php

namespace Ushahidi\Modules\V5\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Ushahidi\Contracts\Permission;

/**
 * Liberia PBO custom controller — lean, flat per-post rows for feeding the
 * Analysis Report Builder's WebDataRocks pivot table (client-side crosstab
 * needs raw per-post attribute values, not the server-side aggregate
 * counts `posts/stats` returns).
 *
 * Deliberately NOT the stock `GET /posts?only=...,post_content` mechanism:
 * that returns full field *metadata* (label/input/type/options/config/
 * translations) repeated on every single post — ~5.8KB/post measured
 * against this deployment's real data, which blows past WebDataRocks'
 * free-tier 1MB payload cap at only ~170 posts. This endpoint returns one
 * flat `{attribute_label: value}` row per post instead (no metadata
 * repetition), roughly 10-20x smaller, batching one query per EAV value
 * table (no N+1) rather than Eloquent's per-post relationship loading.
 * See LIBERIA_CUSTOM.md.
 */
class PivotDataController extends V5Controller
{
    private const MAX_LIMIT = 3000;

    // Same exclusion rule as the frontend's isPivotableField() (see
    // analysis-report-builder-tab.component.ts) and Phase 0's
    // EloquentPostRepository::applyGroupByAttribute() — only attribute
    // types with a single groupable scalar value are exposed.
    private const VALUE_TABLES = [
        'varchar' => 'post_varchar',
        'text' => 'post_text',
        'datetime' => 'post_datetime',
        'decimal' => 'post_decimal',
        'int' => 'post_int',
    ];

    private function requireAccessAnalysis(): void
    {
        $authorizer = service('authorizer.post');
        $user = $authorizer->getUser();
        if (!$authorizer->acl->hasPermission($user, Permission::ACCESS_ANALYSIS)) {
            abort(403, trans('errors.generic403'));
        }
    }

    // GET /api/v5/analysis-pivot-data?form_id=&created_after=&created_before=&status[]=&tags[]=&limit=
    public function index(Request $request): JsonResponse
    {
        $this->requireAccessAnalysis();

        $formId = $request->query('form_id');
        if (!$formId) {
            abort(422, 'form_id is required');
        }

        $limit = min((int) ($request->query('limit') ?: self::MAX_LIMIT), self::MAX_LIMIT);

        $query = DB::table('posts')->where('form_id', $formId);

        $statuses = (array) $request->query('status', []);
        $query->whereIn('status', $statuses ?: ['published']);

        if ($request->filled('created_after')) {
            $query->where('created', '>=', strtotime($request->query('created_after')));
        }
        if ($request->filled('created_before')) {
            $query->where('created', '<=', strtotime($request->query('created_before')));
        }

        $tags = (array) $request->query('tags', []);
        if ($tags) {
            $query->whereIn('id', function ($sub) use ($tags) {
                $sub->select('post_id')->from('posts_tags')->whereIn('tag_id', $tags);
            });
        }

        $total = (clone $query)->count();
        $posts = $query->orderByDesc('created')
            ->limit($limit)
            ->get(['id', 'status', 'created', 'title', 'mgmt_lev_1', 'mgmt_lev_2']);

        $postIds = $posts->pluck('id')->all();

        $attributes = DB::table('form_attributes')
            ->join('form_stages', 'form_attributes.form_stage_id', '=', 'form_stages.id')
            ->where('form_stages.form_id', $formId)
            ->whereIn('form_attributes.type', array_keys(self::VALUE_TABLES))
            // Exclude plain free-text fields (high-cardinality, not a useful
            // pivot dimension) — same rule the frontend applies.
            ->where(function ($q) {
                $q->where('form_attributes.input', '!=', 'text')
                    ->orWhere('form_attributes.type', '!=', 'varchar');
            })
            ->select('form_attributes.id', 'form_attributes.label', 'form_attributes.type')
            ->get();

        // post_id => [label => value], batched one query per value table (no N+1).
        $valuesByPost = [];
        if ($postIds && $attributes->isNotEmpty()) {
            foreach (self::VALUE_TABLES as $type => $table) {
                $attrsOfType = $attributes->where('type', $type);
                if ($attrsOfType->isEmpty()) {
                    continue;
                }
                $labelByAttributeId = $attrsOfType->pluck('label', 'id');
                $rows = DB::table($table)
                    ->whereIn('post_id', $postIds)
                    ->whereIn('form_attribute_id', $attrsOfType->pluck('id'))
                    ->select('post_id', 'form_attribute_id', 'value')
                    ->get();
                foreach ($rows as $row) {
                    $label = $labelByAttributeId[$row->form_attribute_id] ?? null;
                    if ($label) {
                        $valuesByPost[$row->post_id][$label] = $row->value;
                    }
                }
            }
        }

        $results = $posts->map(function ($post) use ($valuesByPost) {
            return array_merge([
                'Post ID' => $post->id,
                'Status' => $post->status,
                'Date' => date('Y-m-d', $post->created),
                'Title' => $post->title,
                'County' => $post->mgmt_lev_1,
                'District' => $post->mgmt_lev_2,
            ], $valuesByPost[$post->id] ?? []);
        })->values();

        return response()->json([
            'count' => $results->count(),
            'total' => $total,
            'truncated' => $total > $limit,
            'results' => $results,
        ]);
    }
}
