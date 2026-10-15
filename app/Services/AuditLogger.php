<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the audit trail (WORKFLOW.md section 11): who did what, to what, when, from where, and the before and after.
 * Call it from the service that makes the change, so every route into that change is covered.
 */
class AuditLogger
{
    /** Fields whose values must never be stored. */
    private const SECRET = ['password', 'remember_token', 'token', 'plainToken'];

    public function __construct(private CurrentOrganization $current) {}

    /**
     * @param  string  $action  e.g. role.permissions_updated, member.offboarded, company.approved
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     * @param  bool  $platform  true for platform-level lines (no company), such as company approvals
     */
    public function record(string $action, ?Model $subject = null, ?array $old = null, ?array $new = null, ?string $summary = null, bool $platform = false, ?int $organizationId = null): AuditLog
    {
        $request = app()->runningInConsole() ? null : request();

        return AuditLog::create([
            'organization_id' => $platform ? null : ($organizationId ?? $subject?->getAttribute('organization_id') ?? $this->current->id()),
            'actor_user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => $subject ? $subject->getMorphClass() : null,
            'auditable_id' => $subject?->getKey(),
            'summary' => $summary !== null ? mb_substr($summary, 0, 255) : null,
            'old_values' => $old === null ? null : $this->clean($old),
            'new_values' => $new === null ? null : $this->clean($new),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'created_at' => now(),
        ]);
    }

    /**
     * Only what changed: the old and new values of the keys whose value is different.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    public static function diff(array $before, array $after): array
    {
        $old = [];
        $new = [];

        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) != $value) {
                $old[$key] = $before[$key] ?? null;
                $new[$key] = $value;
            }
        }

        return [$old, $new];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function clean(array $values): array
    {
        return collect($values)->map(fn ($v, $k) => in_array($k, self::SECRET, true) ? '[hidden]' : $v)->all();
    }
}
