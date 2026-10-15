<?php

namespace App\Services;

use App\Exceptions\FormerMemberException;
use App\Models\Department;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Notifications\UserInvited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class InvitationService
{
    public function __construct(
        private HierarchyService $hierarchy,
        private EmploymentService $employment,
        private SeatService $seats,
    ) {}

    /**
     * Invite someone by email. Throws ValidationException for rule violations and
     * FormerMemberException when the email belongs to a former employee (use the rehire flow instead).
     *
     * @param  array{email:string,name?:?string,role_id:int,department_id?:?int,reports_to_id?:?int,reports_to_email?:?string,designation?:?string,employee_code?:?string}  $data
     */
    public function invite(Organization $organization, ?Membership $by, array $data, bool $send = true): Invitation
    {
        $invitation = DB::transaction(fn () => $this->create($organization, $by, $data));
        app(AuditLogger::class)->record('invitation.sent', $invitation, null, ['email' => $invitation->email, 'role_id' => $invitation->role_id], $invitation->email);

        if ($send) {
            $this->send($invitation, $by);
        }

        return $invitation;
    }

    /** Build and store a valid invitation (and its token) without sending it. */
    private function create(Organization $organization, ?Membership $by, array $data): Invitation
    {
        $email = strtolower(trim($data['email']));
        $role = $this->findRole($organization, (int) $data['role_id'], $by);

        $this->assertEmailFree($organization, $email);

        $department = null;
        if (! empty($data['department_id'])) {
            $department = Department::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $organization->id)->find($data['department_id']);
            if (! $department) {
                throw ValidationException::withMessages(['department_id' => 'That department does not exist.']);
            }
        }

        $external = $role->is_external;
        $managerId = null;
        if (! empty($data['reports_to_id'])) {
            $manager = Membership::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $organization->id)->with('role')->find($data['reports_to_id']);
            if (! $manager) {
                throw ValidationException::withMessages(['reports_to_id' => 'That manager does not exist.']);
            }
            $this->hierarchy->assertManagerAllowed($manager, $role, $external, $organization->id);
            $managerId = $manager->id;
        }

        $code = $data['employee_code'] ?? null;
        $this->assertEmployeeCodeFree($organization, $code ?: null);

        $this->seats->assertAvailable($organization, 1, 'email');

        $token = Invitation::newToken();

        $invitation = new Invitation([
            'organization_id' => $organization->id,
            'email' => $email,
            'name' => $data['name'] ?? null,
            'role_id' => $role->id,
            'department_id' => $department?->id,
            'reports_to_id' => $managerId,
            'reports_to_email' => $external ? null : ($data['reports_to_email'] ?? null),
            'user_type' => $external ? 'external' : 'internal',
            'designation' => $data['designation'] ?? null,
            'employee_code' => $code ?: null,
            'token_hash' => Invitation::hashToken($token),
            'invited_by' => $by?->id,
            'expires_at' => now()->addDays(Invitation::VALID_DAYS),
        ]);
        $invitation->save();
        $invitation->plainToken = $token;

        return $invitation;
    }

    /** Email the invitation link. The invitation must carry its plain token (just created or renewed). */
    public function send(Invitation $invitation, ?Membership $by = null): void
    {
        $invitation->loadMissing('role');
        $organization = Organization::findOrFail($invitation->organization_id);
        $inviter = $by ?? $invitation->inviter;

        Notification::route('mail', $invitation->email)
            ->notify(new UserInvited($invitation, $organization, $inviter?->user?->name, $invitation->plainToken));
    }

    /** Renew the link (new token, 7 more days) and email it again. */
    public function resend(Invitation $invitation, ?Membership $by = null): Invitation
    {
        if ($invitation->accepted_at !== null || $invitation->revoked_at !== null) {
            throw ValidationException::withMessages(['invitation' => 'This invitation can no longer be sent.']);
        }

        $organization = Organization::findOrFail($invitation->organization_id);

        DB::transaction(function () use ($invitation, $organization) {
            // An expired invitation gave its seat back, so it needs one again.
            if ($invitation->isExpired()) {
                $this->seats->assertAvailable($organization, 1, 'invitation');
            }

            $token = Invitation::newToken();
            $invitation->forceFill([
                'token_hash' => Invitation::hashToken($token),
                'expires_at' => now()->addDays(Invitation::VALID_DAYS),
            ])->save();
            $invitation->plainToken = $token;
        });

        app(AuditLogger::class)->record('invitation.resent', $invitation, null, ['email' => $invitation->email], $invitation->email);

        $this->send($invitation, $by);

        return $invitation;
    }

    public function revoke(Invitation $invitation): void
    {
        if ($invitation->accepted_at !== null) {
            throw ValidationException::withMessages(['invitation' => 'This invitation was already accepted.']);
        }

        $invitation->forceFill(['revoked_at' => now()])->save();
        app(AuditLogger::class)->record('invitation.revoked', $invitation, null, null, $invitation->email);
    }

    public function findByToken(string $token): ?Invitation
    {
        return Invitation::withoutGlobalScope(OrganizationScope::class)
            ->with(['role'])
            ->where('token_hash', Invitation::hashToken($token))
            ->first();
    }

    /**
     * Accept an invitation. New people pass `name` and `password`; people who already have an account pass
     * that user (they must be signed in as the invited email).
     *
     * @param  array{name?:string,password?:string}  $newUser
     *
     * @throws ValidationException
     */
    public function accept(Invitation $invitation, ?User $existing, array $newUser = []): Membership
    {
        return DB::transaction(function () use ($invitation, $existing, $newUser) {
            $invitation = Invitation::withoutGlobalScope(OrganizationScope::class)->lockForUpdate()->findOrFail($invitation->id);

            if (! $invitation->isUsable()) {
                throw ValidationException::withMessages(['invitation' => 'This invitation is no longer valid.']);
            }

            $organization = Organization::findOrFail($invitation->organization_id);
            if (! $organization->isActive()) {
                throw ValidationException::withMessages(['invitation' => 'This company is not active.']);
            }

            $user = $existing ?? User::where('email', $invitation->email)->first();

            if ($user !== null) {
                if ($existing === null || strtolower($existing->email) !== $invitation->email) {
                    throw ValidationException::withMessages(['invitation' => 'Sign in with the invited email address to accept.']);
                }

                $this->assertNotMember($organization, $user);
            } else {
                $user = new User(['name' => $newUser['name'] ?? $invitation->name ?? $invitation->email, 'email' => $invitation->email, 'password' => $newUser['password']]);
                $user->forceFill(['email_verified_at' => now()])->save(); // reaching the link proves the mailbox
            }

            $membership = Membership::withoutGlobalScope(OrganizationScope::class)->create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role_id' => $invitation->role_id,
                'department_id' => $invitation->department_id,
                'reports_to_id' => $this->resolveManager($invitation, $organization),
                'user_type' => $invitation->user_type,
                'designation' => $invitation->designation,
                'employee_code' => $this->codeIfFree($organization, $invitation->employee_code),
                'status' => Membership::ACTIVE,
                'joined_at' => now()->toDateString(),
            ]);

            $this->employment->open($membership, $invitation->inviter);
            $this->hierarchy->rebuildSubtree($membership);

            $invitation->forceFill(['accepted_at' => now()])->save();
            $user->forceFill(['last_organization_id' => $organization->id])->saveQuietly();

            return $membership;
        });
    }

    // --- rules ----------------------------------------------------------------------------------

    private function findRole(Organization $organization, int $roleId, ?Membership $by): Role
    {
        $role = Role::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization->id)->find($roleId);

        if (! $role) {
            throw ValidationException::withMessages(['role_id' => 'Choose a role from this company.']);
        }

        if ($by !== null && $role->level < $by->role->level) {
            throw ValidationException::withMessages(['role_id' => 'You cannot invite someone into a role more senior than your own.']);
        }

        return $role;
    }

    private function assertEmailFree(Organization $organization, string $email): void
    {
        $user = User::where('email', $email)->first();

        if ($user) {
            $membership = Membership::withoutGlobalScope(OrganizationScope::class)->withTrashed()
                ->where('organization_id', $organization->id)->where('user_id', $user->id)->first();

            if ($membership?->deleted_at !== null) {
                throw new FormerMemberException($membership);
            }

            if ($membership !== null) {
                throw ValidationException::withMessages(['email' => 'This person is already a member of the company.']);
            }
        }

        $pending = Invitation::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization->id)->where('email', $email)->usable()->exists();

        if ($pending) {
            throw ValidationException::withMessages(['email' => 'This email already has a pending invitation. Resend it instead.']);
        }
    }

    private function assertNotMember(Organization $organization, User $user): void
    {
        $exists = Membership::withoutGlobalScope(OrganizationScope::class)->withTrashed()
            ->where('organization_id', $organization->id)->where('user_id', $user->id)->exists();

        if ($exists) {
            throw ValidationException::withMessages(['invitation' => 'You already have a membership in this company.']);
        }
    }

    private function assertEmployeeCodeFree(Organization $organization, ?string $code): void
    {
        if ($code === null) {
            return;
        }

        $taken = Membership::withoutGlobalScope(OrganizationScope::class)->withTrashed()
            ->where('organization_id', $organization->id)->where('employee_code', $code)->exists()
            || Invitation::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $organization->id)->where('employee_code', $code)->usable()->exists();

        if ($taken) {
            throw ValidationException::withMessages(['employee_code' => 'That employee code is already in use.']);
        }
    }

    private function codeIfFree(Organization $organization, ?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $taken = Membership::withoutGlobalScope(OrganizationScope::class)->withTrashed()
            ->where('organization_id', $organization->id)->where('employee_code', $code)->exists();

        return $taken ? null : $code;
    }

    /** The manager named on the invitation (by id, or by email for bulk imports), if still valid. */
    private function resolveManager(Invitation $invitation, Organization $organization): ?int
    {
        if ($invitation->user_type === 'external') {
            return null;
        }

        $query = Membership::withoutGlobalScope(OrganizationScope::class)->with('role')
            ->where('organization_id', $organization->id);

        if ($invitation->reports_to_id) {
            $manager = (clone $query)->find($invitation->reports_to_id);
        } elseif ($invitation->reports_to_email) {
            $manager = (clone $query)->whereHas('user', fn ($q) => $q->where('email', $invitation->reports_to_email))->first();
        } else {
            return null;
        }

        if ($manager === null) {
            return null;
        }

        try {
            $this->hierarchy->assertManagerAllowed($manager, $invitation->role, false, $organization->id);
        } catch (ValidationException) {
            return null; // the manager left or no longer outranks them; an admin can set it afterwards
        }

        return $manager->id;
    }
}
