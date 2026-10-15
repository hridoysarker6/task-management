<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\NotificationPreference;
use App\Support\NotificationEvents;
use Illuminate\Support\Collection;

/** What each person wants to hear about, and how. Unset choices fall back to the defaults. */
class NotificationPreferenceService
{
    /** @var array<int, Collection<string, NotificationPreference>> */
    private array $loaded = [];

    /**
     * Channels to use for one event: any of 'database' (the bell) and 'mail'.
     *
     * @return list<string>
     */
    public function channels(Membership $member, string $event): array
    {
        $default = NotificationEvents::EVENTS[$event] ?? ['app' => true, 'mail' => false];
        $saved = $this->forMember($member)->get($event);

        $app = $saved ? $saved->via_app : $default['app'];
        $mail = $saved ? $saved->via_mail : $default['mail'];

        return array_values(array_filter([$app ? 'database' : null, $mail ? 'mail' : null]));
    }

    /** @return list<array{event:string,label:string,description:string,app:bool,mail:bool}> */
    public function all(Membership $member): array
    {
        $saved = $this->forMember($member);

        return collect(NotificationEvents::EVENTS)->map(fn ($e, $key) => [
            'event' => $key, 'label' => $e['label'], 'description' => $e['description'],
            'app' => $saved->has($key) ? $saved[$key]->via_app : $e['app'],
            'mail' => $saved->has($key) ? $saved[$key]->via_mail : $e['mail'],
        ])->values()->all();
    }

    /**
     * Save the choices sent (only known events are kept).
     *
     * @param  array<string, array{app?:mixed,mail?:mixed}>  $choices
     */
    public function save(Membership $member, array $choices): void
    {
        foreach (NotificationEvents::keys() as $event) {
            if (! isset($choices[$event])) {
                continue;
            }

            NotificationPreference::updateOrCreate(
                ['membership_id' => $member->id, 'event' => $event],
                ['via_app' => filter_var($choices[$event]['app'] ?? false, FILTER_VALIDATE_BOOLEAN), 'via_mail' => filter_var($choices[$event]['mail'] ?? false, FILTER_VALIDATE_BOOLEAN)],
            );
        }

        unset($this->loaded[$member->id]);
    }

    /** @return Collection<string, NotificationPreference> */
    private function forMember(Membership $member): Collection
    {
        return $this->loaded[$member->id] ??= NotificationPreference::where('membership_id', $member->id)->get()->keyBy('event');
    }
}
