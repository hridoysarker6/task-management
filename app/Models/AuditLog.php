<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One admin or security action. Append-only: lines are never edited. Not company-scoped by a global scope because
 * platform-level lines (company approvals) have no company; the pages filter by organization explicitly.
 *
 * @property int $id
 * @property int|null $organization_id
 * @property int|null $actor_user_id
 * @property string $action
 * @property string|null $auditable_type
 * @property int|null $auditable_id
 * @property string|null $summary
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
#[Fillable(['organization_id', 'actor_user_id', 'action', 'auditable_type', 'auditable_id', 'summary', 'old_values', 'new_values', 'ip_address', 'user_agent', 'created_at'])]
class AuditLog extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
