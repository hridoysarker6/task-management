<?php

namespace App\Listeners;

use App\Models\Organization;
use App\Models\User;
use App\Services\CompanyRegistrationService;
use Illuminate\Auth\Events\Verified;

/** A new application reaches the review queue once the applicant has verified their email. */
class NotifySystemAdminsOfCompany
{
    public function __construct(private CompanyRegistrationService $registration) {}

    public function handle(Verified $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $pending = Organization::where('applicant_user_id', $event->user->id)
            ->where('status', Organization::PENDING)
            ->get();

        foreach ($pending as $organization) {
            $this->registration->notifySystemAdmins($organization);
        }
    }
}
