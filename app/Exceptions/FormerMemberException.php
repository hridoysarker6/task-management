<?php

namespace App\Exceptions;

use App\Models\Membership;
use RuntimeException;

/** Thrown when someone is invited whose email belongs to a former member: that is a rehire, not an invitation. */
class FormerMemberException extends RuntimeException
{
    public function __construct(public readonly Membership $membership)
    {
        parent::__construct('This email belongs to a former employee. Use Rehire instead.');
    }
}
