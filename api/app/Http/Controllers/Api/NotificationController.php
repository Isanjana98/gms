<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** SRS 21 - Notifications. */
class NotificationController extends Controller
{
    /** GET /api/notifications */
    public function index(Request $request): JsonResponse
    {
        $notifications = Notification::query()
            // Staff see what was addressed to them plus branch-wide notices;
            // messages queued for members are not staff inbox items.
            ->where(function ($q) use ($request) {
                $q->where('user_id', $request->user()->id)
                    ->orWhere(function ($w) use ($request) {
                        $w->whereNull('user_id')
                            ->whereNull('member_id')
                            ->where('branch_id', $request->user()->branch_id);
                    });
            })
            ->when($request->boolean('unread'), fn ($q) => $q->unread())
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request, 30));

        return response()->json($notifications->through(fn (Notification $n) => [
            'id' => $n->id,
            'type' => $n->type,
            'channel' => $n->channel,
            'subject' => $n->subject,
            'body' => $n->body,
            'data' => $n->data,
            'status' => $n->status,
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ]));
    }

    /** GET /api/notifications/unread-count */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = Notification::query()
            ->where('user_id', $request->user()->id)
            ->unread()
            ->count();

        return response()->json(['count' => $count]);
    }

    /** POST /api/notifications/{notification}/read */
    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        abort_if(
            $notification->user_id !== null && $notification->user_id !== $request->user()->id,
            403,
        );

        $notification->forceFill(['read_at' => now()])->save();

        return $this->ok('Marked as read.');
    }

    /** POST /api/notifications/read-all */
    public function markAllRead(Request $request): JsonResponse
    {
        $count = Notification::query()
            ->where('user_id', $request->user()->id)
            ->unread()
            ->update(['read_at' => now()]);

        return $this->ok("{$count} notification(s) marked as read.");
    }
}
