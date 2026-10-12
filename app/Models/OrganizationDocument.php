<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $type
 * @property string $path
 * @property string $original_name
 * @property int $size
 */
#[Fillable(['organization_id', 'type', 'path', 'original_name', 'mime', 'size', 'uploaded_by'])]
class OrganizationDocument extends Model
{
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
