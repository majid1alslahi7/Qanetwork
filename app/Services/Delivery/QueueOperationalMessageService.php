<?php

namespace App\Services\Delivery;

use App\Enums\UserRole;
use App\Jobs\SendOperationalMessageJob;
use App\Models\OperationalMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class QueueOperationalMessageService
{
    public function forUser(?User $user, string $eventKey, string $kind, string $message, ?string $network = null): void
    {
        if ($user === null) {
            return;
        }
        $phone = $user->sms_phone ?? $user->seller()->value('phone') ?? $user->networkOwner()->value('phone');
        if ($network === null && $user->role === UserRole::NETWORK_OWNER) {
            $networks = $user->networkOwner()->first()?->networks()->limit(2)->pluck('name');
            if ($networks?->count() === 1) {
                $network = $networks->first();
            }
        }
        $this->queue($phone, $eventKey.'|user:'.$user->id, $kind, $message, $network);
    }

    public function queue(?string $phone, string $eventKey, string $kind, string $message, ?string $network = null): ?OperationalMessage
    {
        $recipient = YemenPhoneNumber::normalize($phone);
        if (! config('services.quick_connect.operational_enabled', false) || $recipient === null) {
            return null;
        }
        if ($kind === 'registration_received') {
            $eventKey = 'registration_received|'.$recipient.'|'.now()->toDateString();
        }
        $title = match ($kind) {
            'account' => 'حالة الحساب', 'device' => 'حالة الجهاز', 'deposit' => 'إيداع المحفظة',
            'settlement' => 'تسوية المستحقات', default => 'طلب التسجيل',
        };
        $record = OperationalMessage::query()->firstOrCreate(['event_key' => hash('sha256', $eventKey)], [
            'kind' => $kind, 'recipient' => $recipient, 'body' => MessageTemplate::format($title, [$message], $network), 'status' => 'queued',
        ]);
        if ($record->wasRecentlyCreated) {
            DB::afterCommit(fn () => SendOperationalMessageJob::dispatch($record->id));
        }

        return $record;
    }
}
