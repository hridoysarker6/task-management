<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/** The bell and the notifications page. Notifications are the current company's (or the user's own, for System Admins). */
class NotificationController extends Controller
{
    public function __construct(private CurrentOrganization $current) {}

    public function index(Request $request): Response
    {
        $unreadOnly = $request->boolean('unread');
        $query = $this->owner($request)->notifications()->latest()->latest('id');

        return Inertia::render('Notifications/Index', [
            'notifications' => $query->clone()->when($unreadOnly, fn ($q) => $q->whereNull('read_at'))->paginate(25)->withQueryString()
                ->through(fn (DatabaseNotification $n) => $this->present($n)),
            'unread' => $this->owner($request)->unreadNotifications()->count(),
            'filters' => ['unread' => $unreadOnly],
            'canSetPreferences' => $this->current->membership !== null,
        ]);
    }

    /** What the bell shows: the unread count and the latest few. */
    public function recent(Request $request): JsonResponse
    {
        $owner = $this->owner($request);

        return response()->json([
            'unread' => $owner->unreadNotifications()->count(),
            'items' => $owner->notifications()->latest()->latest('id')->limit(8)->get()->map(fn (DatabaseNotification $n) => $this->present($n))->values(),
        ]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $this->owner($request)->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return response()->json(['ok' => true]);
    }

    public function readAll(Request $request): RedirectResponse|JsonResponse
    {
        $this->owner($request)->unreadNotifications()->update(['read_at' => now()]);

        return $request->wantsJson() ? response()->json(['ok' => true]) : back();
    }

    /** The person whose notifications these are: their membership in this company, or the user for platform admins. */
    private function owner(Request $request): Membership|User
    {
        return $this->current->membership ?? $request->user();
    }

    /** @return array<string, mixed> */
    private function present(DatabaseNotification $n): array
    {
        return [
            'id' => $n->id,
            'event' => $n->data['event'] ?? null,
            'title' => $n->data['title'] ?? 'Notification',
            'body' => $n->data['body'] ?? null,
            'url' => $n->data['url'] ?? null,
            'read' => $n->read_at !== null,
            'created_at' => $n->created_at->toIso8601String(),
        ];
    }
}
