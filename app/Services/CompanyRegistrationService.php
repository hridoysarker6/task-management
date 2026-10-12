<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use App\Notifications\CompanyRegistered;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class CompanyRegistrationService
{
    /**
     * Create the applicant's login and a pending company. The verification email goes out through the
     * Registered event; System Admins are told once the email is verified (NotifySystemAdminsOfCompany).
     *
     * @param  array<string, mixed>  $company  organization fields
     * @param  array{name:string,email:string,phone?:?string,password:string,designation?:?string}  $contact
     * @param  list<UploadedFile>  $otherDocuments
     */
    public function register(array $company, array $contact, ?UploadedFile $tradeLicense = null, array $otherDocuments = []): Organization
    {
        $organization = DB::transaction(function () use ($company, $contact, $tradeLicense, $otherDocuments) {
            $user = User::create([
                'name' => $contact['name'],
                'email' => $contact['email'],
                'phone' => $contact['phone'] ?? null,
                'password' => $contact['password'],
            ]);

            $organization = new Organization($company);
            $organization->status = Organization::PENDING;
            $organization->applicant_user_id = $user->id;
            $organization->settings = ['contact_designation' => $contact['designation'] ?? null];
            $organization->save();

            $this->storeDocuments($organization, $user, $tradeLicense, $otherDocuments);
            $organization->reviews()->create(['action' => 'submitted', 'acted_by' => $user->id]);

            return $organization;
        });

        event(new Registered($organization->applicant));

        return $organization;
    }

    /**
     * The applicant corrects a rejected / info-requested application and sends it back for review.
     *
     * @param  array<string, mixed>  $company
     * @param  list<UploadedFile>  $otherDocuments
     */
    public function resubmit(Organization $organization, array $company, ?string $note = null, ?UploadedFile $tradeLicense = null, array $otherDocuments = []): void
    {
        DB::transaction(function () use ($organization, $company, $note, $tradeLicense, $otherDocuments) {
            $organization->fill($company);
            $organization->status = Organization::PENDING;
            $organization->save();

            $this->storeDocuments($organization, $organization->applicant, $tradeLicense, $otherDocuments);
            $organization->reviews()->create(['action' => 'resubmitted', 'note' => $note, 'acted_by' => $organization->applicant_user_id]);
        });

        $this->notifySystemAdmins($organization, resubmitted: true);
    }

    public function notifySystemAdmins(Organization $organization, bool $resubmitted = false): void
    {
        $admins = User::where('is_system_admin', true)->where('status', 'active')->get();

        Notification::send($admins, new CompanyRegistered($organization, $resubmitted));
    }

    /** @param list<UploadedFile> $others */
    private function storeDocuments(Organization $organization, User $uploader, ?UploadedFile $tradeLicense, array $others): void
    {
        $files = collect($others)->map(fn ($f) => ['other', $f]);

        if ($tradeLicense) {
            $files->prepend(['trade_license', $tradeLicense]);
        }

        foreach ($files as [$type, $file]) {
            $organization->documents()->create([
                'type' => $type,
                'path' => $file->store("organization-documents/{$organization->id}"),
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => $uploader->id,
            ]);
        }
    }
}
