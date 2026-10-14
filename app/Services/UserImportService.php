<?php

namespace App\Services;

use App\Exceptions\FormerMemberException;
use App\Models\Department;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Bulk invite from a CSV file: parse, validate every row (preview), then invite the valid ones.
 * Columns: email, role (required); name, department, reports_to_email, type (internal|external), designation, employee_code.
 */
class UserImportService
{
    public const MAX_ROWS = 500;

    public const COLUMNS = ['name', 'email', 'role', 'department', 'reports_to_email', 'type', 'designation', 'employee_code'];

    private const ALIASES = [
        'e-mail' => 'email', 'email address' => 'email', 'full name' => 'name',
        'reports to' => 'reports_to_email', 'reports_to' => 'reports_to_email', 'reports to email' => 'reports_to_email',
        'manager' => 'reports_to_email', 'manager email' => 'reports_to_email',
        'user_type' => 'type', 'user type' => 'type', 'employee code' => 'employee_code', 'employee id' => 'employee_code',
        'title' => 'designation', 'job title' => 'designation',
    ];

    public function __construct(
        private InvitationService $invitations,
        private HierarchyService $hierarchy,
        private SeatService $seats,
    ) {}

    /**
     * Read a CSV file into rows keyed by column name.
     *
     * @return list<array<string, string>>
     *
     * @throws ValidationException
     */
    public function parse(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be read.']);
        }

        $header = fgetcsv($handle, 0, ',', '"', '');
        if (! $header) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'The file is empty.']);
        }

        $columns = array_map(function ($h) {
            $h = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)));

            return self::ALIASES[$h] ?? str_replace(' ', '_', $h);
        }, $header);

        if (! in_array('email', $columns, true) || ! in_array('role', $columns, true)) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'The file needs at least the columns "email" and "role".']);
        }

        $rows = [];
        while (($line = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($line === [null] || count(array_filter($line, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // blank line
            }

            $row = [];
            foreach ($columns as $i => $column) {
                if (in_array($column, self::COLUMNS, true)) {
                    $row[$column] = trim((string) ($line[$i] ?? ''));
                }
            }
            $rows[] = $row;

            if (count($rows) > self::MAX_ROWS) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => 'A file can have at most '.self::MAX_ROWS.' rows. Split it into smaller files.']);
            }
        }
        fclose($handle);

        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'The file has no rows below the header.']);
        }

        return $rows;
    }

    /**
     * Check every row without changing anything.
     *
     * @param  list<array<string, string>>  $rows
     * @return list<array{line:int,data:array<string,mixed>,errors:list<string>}>
     */
    public function validate(Organization $organization, Membership $by, array $rows): array
    {
        $roles = Role::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $organization->id)->get();
        $departments = Department::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $organization->id)->get();
        $existing = $this->existingPeople($organization);

        $remaining = $this->seats->remaining($organization);
        $seenEmails = [];
        $seenCodes = [];

        // Rows can name a manager who is also being invited in this file.
        $inFile = collect($rows)->mapWithKeys(fn ($r) => [strtolower($r['email'] ?? '') => $r]);

        $result = [];
        foreach ($rows as $i => $row) {
            $errors = [];
            $email = strtolower(trim($row['email'] ?? ''));

            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Email is missing or not valid.';
            } elseif (isset($seenEmails[$email])) {
                $errors[] = "Email repeated (first on line {$seenEmails[$email]}).";
            } else {
                $seenEmails[$email] = $i + 2;
                $errors = [...$errors, ...$this->emailProblems($existing, $email)];
            }

            $role = $this->match($roles, $row['role'] ?? '', ['slug', 'name']);
            if (! $role) {
                $errors[] = ($row['role'] ?? '') === '' ? 'Role is missing.' : "Unknown role \"{$row['role']}\".";
            } elseif ($role->level < $by->role->level) {
                $errors[] = "You cannot invite into the more senior role \"{$role->name}\".";
            }

            $department = null;
            if (($row['department'] ?? '') !== '') {
                $department = $this->match($departments, $row['department'], ['name', 'code']);
                if (! $department) {
                    $errors[] = "Unknown department \"{$row['department']}\".";
                }
            }

            $type = strtolower($row['type'] ?? '');
            if ($type !== '' && ! in_array($type, ['internal', 'external'], true)) {
                $errors[] = 'Type must be "internal" or "external".';
            } elseif ($role && $type !== '' && ($type === 'external') !== $role->is_external) {
                $errors[] = "Type \"{$type}\" does not match the role \"{$role->name}\".";
            }

            $code = ($row['employee_code'] ?? '') ?: null;
            if ($code !== null) {
                if (isset($seenCodes[$code])) {
                    $errors[] = "Employee code repeated (first on line {$seenCodes[$code]}).";
                } else {
                    $seenCodes[$code] = $i + 2;
                    if (in_array($code, $existing['codes'], true)) {
                        $errors[] = 'Employee code is already in use.';
                    }
                }
            }

            [$managerId, $managerEmail, $managerErrors] = $this->resolveManager($organization, $row, $role, $existing, $inFile, $roles);
            $errors = [...$errors, ...$managerErrors];

            if (! $errors && $remaining !== null) {
                if ($remaining < 1) {
                    $errors[] = "User limit ({$organization->user_limit}) reached.";
                } else {
                    $remaining--;
                }
            }

            $result[] = [
                'line' => $i + 2, // spreadsheet line: header is line 1
                'data' => [
                    'name' => ($row['name'] ?? '') ?: null,
                    'email' => $email,
                    'role' => $role->name ?? ($row['role'] ?? ''),
                    'role_id' => $role?->id,
                    'department' => $department->name ?? ($row['department'] ?? ''),
                    'department_id' => $department?->id,
                    'reports_to_email' => $managerEmail,
                    'reports_to_id' => $managerId,
                    'user_type' => $role?->is_external ? 'external' : 'internal',
                    'designation' => ($row['designation'] ?? '') ?: null,
                    'employee_code' => $code,
                ],
                'errors' => $errors,
            ];
        }

        return $result;
    }

    /**
     * Invite every valid row. Rows that fail on the way are reported back with their reason.
     *
     * @param  list<array<string, string>>  $rows
     * @return array{created:int,failed:list<array{line:int,data:array<string,mixed>,errors:list<string>}>}
     */
    public function import(Organization $organization, Membership $by, array $rows): array
    {
        $created = 0;
        $failed = [];

        foreach ($this->validate($organization, $by, $rows) as $row) {
            if ($row['errors']) {
                $failed[] = $row;

                continue;
            }

            try {
                $this->invitations->invite($organization, $by, $row['data']);
                $created++;
            } catch (ValidationException $e) {
                $row['errors'] = collect($e->errors())->flatten()->all();
                $failed[] = $row;
            } catch (FormerMemberException) {
                $row['errors'] = ['This email belongs to a former employee. Use Rehire instead.'];
                $failed[] = $row;
            }
        }

        return ['created' => $created, 'failed' => $failed];
    }

    /** CSV text for the "rows that could not be imported" report. */
    public function errorReport(array $failedRows): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['line', ...self::COLUMNS, 'errors'], ',', '"', '');

        foreach ($failedRows as $row) {
            $d = $row['data'];
            fputcsv($out, [
                $row['line'], $d['name'], $d['email'], $d['role'], $d['department'],
                $d['reports_to_email'], $d['user_type'], $d['designation'], $d['employee_code'],
                implode(' | ', $row['errors']),
            ], ',', '"', '');
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return (string) $csv;
    }

    public function template(): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, self::COLUMNS, ',', '"', '');
        fputcsv($out, ['Rahim Uddin', 'rahim@example.com', 'Developer', 'Software', 'karim@example.com', 'internal', 'Backend developer', 'E-1001'], ',', '"', '');
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return (string) $csv;
    }

    // --- helpers --------------------------------------------------------------------------------

    /** @return array{active:array<string,Membership>,former:list<string>,pending:list<string>,codes:list<string>} */
    private function existingPeople(Organization $organization): array
    {
        $members = Membership::withoutGlobalScope(OrganizationScope::class)->withTrashed()
            ->where('organization_id', $organization->id)->with(['user:id,email', 'role'])->get();

        return [
            'active' => $members->filter(fn ($m) => $m->deleted_at === null && $m->status === Membership::ACTIVE)
                ->mapWithKeys(fn ($m) => [strtolower($m->user->email) => $m])->all(),
            'former' => $members->filter(fn ($m) => $m->deleted_at !== null)->map(fn ($m) => strtolower($m->user->email))->values()->all(),
            'pending' => Invitation::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $organization->id)
                ->usable()->pluck('email')->map(fn ($e) => strtolower($e))->all(),
            'codes' => $members->pluck('employee_code')->filter()->values()->all(),
        ];
    }

    /** @return list<string> */
    private function emailProblems(array $existing, string $email): array
    {
        return match (true) {
            isset($existing['active'][$email]) => ['This person is already a member.'],
            in_array($email, $existing['former'], true) => ['This is a former employee. Use Rehire instead.'],
            in_array($email, $existing['pending'], true) => ['This email already has a pending invitation.'],
            default => [],
        };
    }

    /**
     * @template T of Role|Department
     *
     * @param  Collection<int, T>  $items
     * @param  list<string>  $fields
     * @return T|null
     */
    private function match(Collection $items, string $value, array $fields): Role|Department|null
    {
        $value = strtolower(trim($value));

        return $value === '' ? null : $items->first(function ($item) use ($fields, $value) {
            foreach ($fields as $field) {
                if ($item->{$field} !== null && strtolower((string) $item->{$field}) === $value) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * @param  Collection<int, Role>  $roles
     * @return array{0:?int,1:?string,2:list<string>} manager membership id, manager email (if in the file), errors
     */
    private function resolveManager(Organization $organization, array $row, ?Role $role, array $existing, Collection $inFile, Collection $roles): array
    {
        $email = strtolower(trim($row['reports_to_email'] ?? ''));

        if ($email === '' || $role === null) {
            return [null, null, []];
        }

        if ($role->is_external) {
            return [null, null, ['External people cannot report to anyone.']];
        }

        if (isset($existing['active'][$email])) {
            try {
                $this->hierarchy->assertManagerAllowed($existing['active'][$email], $role, false, $organization->id);
            } catch (ValidationException $e) {
                return [null, null, [collect($e->errors())->flatten()->first()]];
            }

            return [$existing['active'][$email]->id, null, []];
        }

        if ($inFile->has($email)) {
            $managerRole = $this->match($roles, $inFile[$email]['role'] ?? '', ['slug', 'name']);
            if ($managerRole && $managerRole->level >= $role->level) {
                return [null, null, ["The manager {$email} ({$managerRole->name}) would not outrank {$role->name}."]];
            }

            return [null, $email, []]; // linked when the manager accepts first
        }

        return [null, null, ["Manager {$email} is not a member and not in this file."]];
    }
}
