<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One person's choice for one kind of notification: bell and/or email.
 *
 * @property int $id
 * @property int $membership_id
 * @property string $event
 * @property bool $via_mail
 * @property bool $via_app
 */
#[Fillable(['membership_id', 'event', 'via_mail', 'via_app'])]
class NotificationPreference extends Model
{
    protected function casts(): array
    {
        return ['via_mail' => 'boolean', 'via_app' => 'boolean'];
    }
}
