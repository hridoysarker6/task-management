<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $attachable_type
 * @property int $attachable_id
 * @property int $membership_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string|null $mime
 * @property int $size_bytes
 */
#[Fillable(['organization_id', 'attachable_type', 'attachable_id', 'membership_id', 'disk', 'path', 'original_name', 'mime', 'size_bytes'])]
class Attachment extends Model
{
    use BelongsToOrganization, SoftDeletes;

    /** @return MorphTo<Model, $this> */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Membership, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'membership_id')->withoutGlobalScopes()->withTrashed();
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/') && $this->mime !== 'image/svg+xml';
    }

    public function exists(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }
}
