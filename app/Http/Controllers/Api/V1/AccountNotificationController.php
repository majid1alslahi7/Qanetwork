<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AccountNotificationResource;
use App\Services\Operations\RecordAccountNotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccountNotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'unread' => ['sometimes', 'boolean']]);
        $base = $this->query($request);
        $unread = (clone $base)->whereNull('read_at')->count();
        $items = $base->when($data['unread'] ?? false, fn (Builder $query) => $query->whereNull('read_at'))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(25);

        return AccountNotificationResource::collection($items)->additional(['summary' => ['unread_count' => $unread]]);
    }

    public function update(Request $request, string $notification): AccountNotificationResource
    {
        $data = $request->validate(['read' => ['required', 'boolean']]);
        $item = DB::transaction(function () use ($request, $notification, $data): DatabaseNotification {
            $item = $this->query($request)->lockForUpdate()->findOrFail($notification);
            if ($data['read']) {
                $item->markAsRead();
            } else {
                $item->markAsUnread();
            }

            return $item->refresh();
        }, 3);

        return new AccountNotificationResource($item);
    }

    /** @return Builder<DatabaseNotification> */
    private function query(Request $request): Builder
    {
        abort_unless(Schema::hasTable('notifications'), 503);

        return $request->user()->notifications()->getQuery()->where('type', RecordAccountNotificationService::TYPE)
            ->where('data->audience_role', $request->user()->role->value);
    }
}
