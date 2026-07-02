<?php

namespace Ushahidi\Modules\V5\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Ushahidi\Contracts\Permission;
use Ushahidi\Modules\V5\Models\AnalysisTemplate;

/**
 * Liberia PBO custom controller — CRUD for saved Analysis dashboard report
 * templates. Gated by Permission::ACCESS_ANALYSIS, checked directly against
 * the shared 'authorizer.post' binding (Acl::hasPermission() is generic and
 * doesn't care which authorizer key it's resolved from — see PostController
 * for the same pattern). See LIBERIA_CUSTOM.md.
 */
class AnalysisTemplateController extends V5Controller
{
    private function requireAccessAnalysis(): void
    {
        $authorizer = service('authorizer.post');
        $user = $authorizer->getUser();
        if (!$authorizer->acl->hasPermission($user, Permission::ACCESS_ANALYSIS)) {
            abort(403, trans('errors.generic403'));
        }
    }

    private function validationRules(bool $nameRequired): array
    {
        return [
            'name' => ($nameRequired ? 'required' : 'sometimes|required') . '|string|max:255',
            'form_id' => 'nullable|integer',
            'date_range_start' => 'nullable|integer',
            'date_range_end' => 'nullable|integer',
            'group_by' => 'nullable|string|max:50',
            'group_by_attribute_key' => 'nullable|string|max:255',
            'chart_type' => 'nullable|string|max:30',
            // One WebDataRocks report (`slice`/`options`/`conditions`/`formats`,
            // no `dataSource.data` — that's always re-fetched live on apply)
            // per pivot instance in a multi-pivot report (the "+ Add another
            // pivot" button). Shape is library-defined and opaque to the
            // backend, same as it was opaque under the old platform's
            // Flexmonster-JSON-in-the-config-table storage — no sub-key
            // validation beyond "it's an array".
            'report_config' => 'nullable|array',
            // Template-wide filters, shared by every pivot in report_config.
            'status_filter' => 'nullable|array',
            'status_filter.*' => 'string',
            'tags_filter' => 'nullable|array',
            'tags_filter.*' => 'integer',
        ];
    }

    // GET /api/v5/analysis-templates?form_id=
    public function index(Request $request): JsonResponse
    {
        $this->requireAccessAnalysis();
        $query = AnalysisTemplate::query();
        if ($request->filled('form_id')) {
            $query->where('form_id', $request->query('form_id'));
        }
        $templates = $query->orderByDesc('created')->get();
        return response()->json(['count' => $templates->count(), 'results' => $templates]);
    }

    // GET /api/v5/analysis-templates/{id}
    public function show(int $id): JsonResponse
    {
        $this->requireAccessAnalysis();
        return response()->json(['result' => AnalysisTemplate::findOrFail($id)]);
    }

    // POST /api/v5/analysis-templates
    public function store(Request $request): JsonResponse
    {
        $this->requireAccessAnalysis();
        $data = $request->validate($this->validationRules(true));
        $authorizer = service('authorizer.post');
        $user = $authorizer->getUser();
        $template = AnalysisTemplate::create($data + [
            'user_id' => $user ? $user->getId() : null,
            'chart_type' => $data['chart_type'] ?? 'bar',
            'created' => time(),
        ]);
        return response()->json(['result' => $template], 201);
    }

    // PUT /api/v5/analysis-templates/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $this->requireAccessAnalysis();
        $template = AnalysisTemplate::findOrFail($id);
        $data = $request->validate($this->validationRules(false));
        $template->update($data + ['updated' => time()]);
        return response()->json(['result' => $template]);
    }

    // DELETE /api/v5/analysis-templates/{id}
    public function destroy(int $id): JsonResponse
    {
        $this->requireAccessAnalysis();
        AnalysisTemplate::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }
}
