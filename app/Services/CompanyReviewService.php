<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use App\Notifications\CompanyApproved;
use App\Notifications\CompanyInfoRequested;
use App\Notifications\CompanyRejected;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** System Admin actions on a company: approve, reject, request info, suspend, reactivate. */
class CompanyReviewService
{
    public function __construct(private CompanyProvisioningService $provisioning) {}

    public function approve(Organization $organization, User $actor): void
    {
        $this->requireStatus($organization, [Organization::PENDING], 'Only pending applications can be approved.');

        if (! $organization->applicant->hasVerifiedEmail()) {
            throw ValidationException::withMessages(['status' => 'The contact person has not verified their email yet.']);
        }

        DB::transaction(function () use ($organization, $actor) {
            $organization->forceFill([
                'status' => Organization::ACTIVE,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'suspended_at' => null,
            ])->save();

            $this->provisioning->provision($organization);
            $this->record($organization, 'approved', $actor);
        });

        $organization->applicant->notify(new CompanyApproved($organization));
    }

    public function reject(Organization $organization, User $actor, string $reason): void
    {
        $this->requireStatus($organization, [Organization::PENDING], 'Only pending applications can be rejected.');

        $this->transition($organization, $actor, Organization::REJECTED, 'rejected', $reason);
        $organization->applicant->notify(new CompanyRejected($organization, $reason));
    }

    public function requestInfo(Organization $organization, User $actor, string $note): void
    {
        $this->requireStatus($organization, [Organization::PENDING], 'Only pending applications can be sent back for more information.');

        $this->transition($organization, $actor, Organization::INFO_REQUESTED, 'info_requested', $note);
        $organization->applicant->notify(new CompanyInfoRequested($organization, $note));
    }

    public function suspend(Organization $organization, User $actor, ?string $note = null): void
    {
        $this->requireStatus($organization, [Organization::ACTIVE], 'Only active companies can be suspended.');

        DB::transaction(function () use ($organization, $actor, $note) {
            $organization->forceFill(['status' => Organization::SUSPENDED, 'suspended_at' => now()])->save();
            $this->record($organization, 'suspended', $actor, $note);
        });
    }

    public function reactivate(Organization $organization, User $actor, ?string $note = null): void
    {
        $this->requireStatus($organization, [Organization::SUSPENDED], 'Only suspended companies can be reactivated.');

        DB::transaction(function () use ($organization, $actor, $note) {
            $organization->forceFill(['status' => Organization::ACTIVE, 'suspended_at' => null])->save();
            $this->record($organization, 'reactivated', $actor, $note);
        });
    }

    public function setUserLimit(Organization $organization, ?int $limit): void
    {
        $organization->forceFill(['user_limit' => $limit])->save();
    }

    private function transition(Organization $organization, User $actor, string $status, string $action, string $note): void
    {
        DB::transaction(function () use ($organization, $actor, $status, $action, $note) {
            $organization->forceFill(['status' => $status])->save();
            $this->record($organization, $action, $actor, $note);
        });
    }

    private function record(Organization $organization, string $action, User $actor, ?string $note = null): void
    {
        $organization->reviews()->create(['action' => $action, 'note' => $note, 'acted_by' => $actor->id]);
    }

    /** @param list<string> $allowed */
    private function requireStatus(Organization $organization, array $allowed, string $message): void
    {
        if (! in_array($organization->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }
}
