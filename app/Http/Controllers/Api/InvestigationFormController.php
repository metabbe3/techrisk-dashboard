<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvestigationFormApiResource;
use App\Models\InvestigationFormRequest;
use App\Traits\ApiResponser;
use Illuminate\Http\Request;

/**
 * @group Investigation Forms
 *
 * Read-only intake status for the DingTalk agent: answers "which incidents
 * still have pending/expired investigation forms?" without exposing link tokens
 * or raw form bodies.
 */
class InvestigationFormController extends Controller
{
    use ApiResponser;

    public const STATUS_FILTERS = ['open', 'pending', 'expired', 'submitted', 'revoked', 'all'];

    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', self::STATUS_FILTERS)],
            'incident_no' => ['nullable', 'string', 'max:40'],
            'team' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $status = $data['status'] ?? 'open';

        $query = InvestigationFormRequest::query()
            ->with(['incident:id,no,title,severity'])
            ->withCount([
                'recipients',
                'recipients as opened_recipients_count' => fn ($q) => $q->whereNotNull('last_opened_at'),
            ])
            ->withSum('recipients', 'reminder_count')
            ->whereHas('incident', fn ($q) => $q->applyUserYearAccess($request->user()));

        match ($status) {
            'open', 'pending' => $query->open(),
            'expired' => $query->expired(),
            'submitted' => $query->where('status', InvestigationFormRequest::STATUS_SUBMITTED),
            'revoked' => $query->where('status', InvestigationFormRequest::STATUS_REVOKED),
            'all' => null,
        };

        if (isset($data['incident_no'])) {
            $query->whereHas('incident', fn ($q) => $q->where('no', $data['incident_no']));
        }

        if (isset($data['team'])) {
            $query->where('team', 'like', '%'.$data['team'].'%');
        }

        $limit = (int) ($data['limit'] ?? 20);
        $offset = (int) ($data['offset'] ?? 0);

        $total = (clone $query)->count();
        $rows = $query->orderBy('expires_at')->orderBy('id')
            ->skip($offset)->take($limit)->get();

        return $this->successResponse(
            InvestigationFormApiResource::collection($rows),
            'Investigation forms retrieved successfully.',
            meta: [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'returned' => $rows->count(),
                'has_more' => ($offset + $rows->count()) < $total,
            ],
        );
    }
}
