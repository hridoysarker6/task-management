<?php

namespace App\Console\Commands;

use App\Models\Membership;
use App\Models\Scopes\OrganizationScope;
use App\Services\OffboardingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('members:process-exits')]
#[Description('Exit everyone whose scheduled last working day has arrived')]
class ProcessScheduledExits extends Command
{
    public function handle(OffboardingService $offboarding): int
    {
        $due = Membership::withoutGlobalScope(OrganizationScope::class)
            ->where('status', Membership::ACTIVE)
            ->whereNotNull('scheduled_exit_at')
            ->whereDate('scheduled_exit_at', '<=', today())
            ->with('role')
            ->get();

        foreach ($due as $member) {
            $offboarding->exit($member, $member->scheduled_exit_at);
            $this->line("Exited membership #{$member->id}");
        }

        $this->info("{$due->count()} exit(s) processed.");

        return self::SUCCESS;
    }
}
