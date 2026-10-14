<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\Role;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The reporting tree: `memberships.reports_to_id` is the source of truth, and the
 * `membership_hierarchy` closure table (every ancestor/descendant pair) is kept in step with it.
 *
 * Rules: external people are never in the tree; a manager must be an active internal member of the same
 * company with a lower level number (higher rank) than the person; no loops.
 */
class HierarchyService
{
    private const MAX_DEPTH = 100;

    /** Set (or clear, with null) a person's manager and refresh the closure table for their subtree. */
    public function setManager(Membership $member, ?Membership $manager): void
    {
        $this->validateManager($member, $manager);

        DB::transaction(function () use ($member, $manager) {
            $member->forceFill(['reports_to_id' => $manager?->id])->save();
            $this->rebuildSubtree($member);
        });
    }

    /**
     * @param  Role|null  $asRole  validate as if the person held this role (used when changing roles)
     *
     * @throws ValidationException
     */
    public function validateManager(Membership $member, ?Membership $manager, ?Role $asRole = null, string $field = 'reports_to_id'): void
    {
        if ($manager === null) {
            return;
        }

        $role = $asRole ?? $member->role;

        $this->assertManagerAllowed($manager, $role, $member->isExternal(), $member->organization_id, $field);

        if ($manager->id === $member->id) {
            throw ValidationException::withMessages([$field => 'Someone cannot report to themselves.']);
        }

        if ($this->wouldCreateCycle($member, $manager)) {
            throw ValidationException::withMessages([$field => 'That would create a loop in the reporting line.']);
        }
    }

    /**
     * Checks that do not need an existing person (so they also work for invitations).
     *
     * @throws ValidationException
     */
    public function assertManagerAllowed(Membership $manager, Role $role, bool $external, int $organizationId, string $field = 'reports_to_id'): void
    {
        if ($external) {
            throw ValidationException::withMessages([$field => 'External users are not part of the reporting hierarchy.']);
        }

        if ($manager->organization_id !== $organizationId || $manager->status !== Membership::ACTIVE || $manager->deleted_at !== null || $manager->isExternal()) {
            throw ValidationException::withMessages([$field => 'The manager must be an active internal member of this company.']);
        }

        if ($manager->role->level >= $role->level) {
            throw ValidationException::withMessages([
                $field => "A manager must outrank the person they manage ({$manager->role->name} is level {$manager->role->level}, {$role->name} is level {$role->level}).",
            ]);
        }
    }

    /** True when making $manager the boss of $member would put $member above themselves. */
    public function wouldCreateCycle(Membership $member, Membership $manager): bool
    {
        $cursor = $manager;

        for ($i = 0; $cursor !== null && $i < self::MAX_DEPTH; $i++) {
            if ($cursor->id === $member->id) {
                return true;
            }

            $cursor = $cursor->reports_to_id
                ? Membership::withoutGlobalScope(OrganizationScope::class)->find($cursor->reports_to_id)
                : null;
        }

        return false;
    }

    /** Rebuild the closure rows for a person and everyone below them (after a manager or status change). */
    public function rebuildSubtree(Membership $root): void
    {
        $this->rebuild($root->organization_id, $root->id);
    }

    /** Rebuild the whole company's closure table. */
    public function rebuildOrganization(int $organizationId): void
    {
        $this->rebuild($organizationId, null);
    }

    /** Remove a person from the tree entirely (when they exit). */
    public function purge(Membership $member): void
    {
        DB::table('membership_hierarchy')
            ->where('ancestor_id', $member->id)
            ->orWhere('descendant_id', $member->id)
            ->delete();
    }

    /** @return list<int> ids of everyone below the person, any depth */
    public function descendantIds(Membership $member): array
    {
        return DB::table('membership_hierarchy')
            ->where('ancestor_id', $member->id)
            ->where('depth', '>', 0)
            ->pluck('descendant_id')
            ->all();
    }

    private function rebuild(int $organizationId, ?int $rootId): void
    {
        // Active, internal people only; soft-deleted (exited) rows are excluded by the default scope.
        /** @var array<int, int|null> $parentOf */
        $parentOf = Membership::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId)
            ->where('status', Membership::ACTIVE)
            ->where('user_type', 'internal')
            ->pluck('reports_to_id', 'id')
            ->all();

        $children = [];
        foreach ($parentOf as $id => $parent) {
            if ($parent !== null && array_key_exists($parent, $parentOf)) {
                $children[$parent][] = $id;
            }
        }

        // Which people to refresh: the whole company, or the subtree under $rootId.
        if ($rootId === null) {
            $nodes = array_keys($parentOf);
        } else {
            $nodes = [];
            $queue = array_key_exists($rootId, $parentOf) ? [$rootId] : [];
            while ($queue) {
                $id = array_shift($queue);
                if (isset($nodes[$id])) {
                    continue;
                }
                $nodes[$id] = $id;
                array_push($queue, ...($children[$id] ?? []));
            }
            $nodes = array_values($nodes);

            // The root is not an active internal person (exited, external): they have no place in the tree.
            if (! array_key_exists($rootId, $parentOf)) {
                DB::table('membership_hierarchy')->where('ancestor_id', $rootId)->orWhere('descendant_id', $rootId)->delete();
            }
        }

        DB::transaction(function () use ($nodes, $parentOf) {
            foreach (array_chunk($nodes, 500) as $chunk) {
                DB::table('membership_hierarchy')->whereIn('descendant_id', $chunk)->delete();
            }

            $rows = [];
            foreach ($nodes as $node) {
                $cursor = $node;
                $seen = [];
                for ($depth = 0; $cursor !== null && ! isset($seen[$cursor]) && $depth < self::MAX_DEPTH; $depth++) {
                    $seen[$cursor] = true;
                    $rows[] = ['ancestor_id' => $cursor, 'descendant_id' => $node, 'depth' => $depth];
                    $cursor = $parentOf[$cursor] ?? null;
                    if ($cursor !== null && ! array_key_exists($cursor, $parentOf)) {
                        $cursor = null;
                    }
                }
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('membership_hierarchy')->insert($chunk);
            }
        });
    }
}
