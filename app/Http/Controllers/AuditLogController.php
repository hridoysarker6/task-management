<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** The audit log: for a company (what happened in it) and for the System Admin (everything, platform actions included). */
class AuditLogController extends Controller
{
    /** The kinds of line, by the first word of the action. */
    public const GROUPS = ['role' => 'Roles', 'permission' => 'Permissions', 'member' => 'People', 'invitation' => 'Invitations', 'project' => 'Projects', 'company' => 'Company'];

    public function __construct(private CurrentOrganization $current) {}

    public function company(Request $request): Response
    {
        Gate::authorize('audit.view');

        $organizationId = $this->current->id();
        $filters = $this->filters($request);

        return Inertia::render('Audit/Index', $this->page($filters, AuditLog::where('organization_id', $organizationId), false) + [
            'scope' => 'company',
            'routeName' => 'audit.index',
        ]);
    }

    public function platform(Request $request): Response
    {
        $filters = $this->filters($request, true);

        $query = AuditLog::query()->when($filters['organization_id'] ?? null, fn ($q, $id) => $id === 'platform' ? $q->whereNull('organization_id') : $q->where('organization_id', $id));

        return Inertia::render('Audit/Index', $this->page($filters, $query, true) + [
            'scope' => 'platform',
            'routeName' => 'admin.audit.index',
            'companies' => Organization::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  Builder<AuditLog>  $base
     * @return array<string, mixed>
     */
    private function page(array $filters, Builder $base, bool $showCompany): array
    {
        $lines = $base->clone()
            ->when($filters['group'] ?? null, fn ($q, $g) => $q->where('action', 'like', $g.'.%'))
            ->when($filters['actor'] ?? null, fn ($q, $a) => $q->whereHas('actor', fn ($u) => $u->where('name', 'like', '%'.addcslashes($a, '%_\\').'%')))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->with(['actor:id,name', 'organization:id,name'])->latest('created_at')->latest('id')
            ->paginate(30)->withQueryString()
            ->through(fn (AuditLog $l) => [
                'id' => $l->id,
                'action' => $l->action,
                'label' => $this->label($l->action),
                'group' => explode('.', $l->action)[0],
                'summary' => $l->summary,
                'actor' => $l->actor_user_id ? $l->actor->name : 'The system',
                'company' => $showCompany ? ($l->organization_id ? $l->organization->name : 'Platform') : null,
                'old' => $l->old_values,
                'new' => $l->new_values,
                'ip' => $l->ip_address,
                'created_at' => $l->created_at->toIso8601String(),
            ]);

        return ['lines' => $lines, 'filters' => $filters, 'groups' => self::GROUPS];
    }

    /** @return array<string, mixed> */
    private function filters(Request $request, bool $platform = false): array
    {
        $date = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : null;
        $organization = $request->query('organization_id');

        return array_filter([
            'group' => array_key_exists((string) $request->query('group'), self::GROUPS) ? $request->query('group') : null,
            'actor' => ($a = trim((string) $request->query('actor'))) !== '' ? mb_substr($a, 0, 60) : null,
            'from' => $date($request->query('from')),
            'to' => $date($request->query('to')),
            'organization_id' => $platform ? ($organization === 'platform' ? 'platform' : ((int) $organization ?: null)) : null,
        ], fn ($v) => $v !== null);
    }

    /** "role.permissions_updated" -> "Role permissions updated". */
    private function label(string $action): string
    {
        return ucfirst(str_replace(['.', '_'], ' ', $action));
    }
}
