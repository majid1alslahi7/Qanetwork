<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Auth\ManageNotificationDeviceTokenService;
use App\Services\Operations\RecordAccountNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class NotificationFeedController extends Controller
{
    public function __invoke(Request $request, ManageNotificationDeviceTokenService $devices): JsonResponse
    {
        $devices->assertParentActive($request->user());
        abort_unless(Schema::hasTable('notifications'), 503);
        $query = $request->user()->notifications()->where('type', RecordAccountNotificationService::TYPE)->where('data->audience_role', $request->user()->role->value);
        $unread = (clone $query)->whereNull('read_at')->count();
        $latest = $query->orderByDesc('created_at')->orderByDesc('id')->first(['id']);

        return response()->json(['data' => ['unread_count' => $unread, 'revision' => $latest?->id]])->header('Cache-Control', 'no-store');
    }
}
