<?php

namespace App\Observers;

use App\Models\AccountDevice;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Delivery\QueueOperationalMessageService;
use App\Services\Operations\RecordAccountNotificationService;

class OperationalMessageObserver
{
    public function __construct(private readonly QueueOperationalMessageService $messages, private readonly RecordAccountNotificationService $notifications) {}

    public function created(AuditEvent $auditEvent): void
    {
        $event = $auditEvent->event_type;
        $status = $auditEvent->after['status'] ?? null;
        if (in_array($event, ['account.created', 'account.status_changed'], true)) {
            $user = User::query()->find($auditEvent->subject_id);
            $kind = 'account';
            $title = $status === 'active' ? 'حسابك فعال' : 'تم إيقاف حسابك';
            $body = $status === 'active' ? 'حسابك فعال. يمكنك تسجيل الدخول؛ اعتماد الجهاز يتم وفق سياسة حسابك.' : 'تم إيقاف حسابك. تواصل مع الإدارة للاستفسار.';
        } elseif (in_array($event, ['device.registered', 'device.status_changed', 'device.imei_changed'], true)) {
            $device = AccountDevice::query()->find($auditEvent->subject_id);
            $user = $device === null ? null : User::query()->find($device->user_id);
            $kind = 'device';
            $status ??= 'pending';
            $title = match ($status) {
                'approved' => 'تم اعتماد جهازك', 'blocked' => 'تم إيقاف جهازك', default => 'جهاز جديد لحسابك'
            };
            $body = match ($status) {
                'approved' => 'تم اعتماد جهازك. الدخول يتطلب حسابًا فعالًا.',
                'blocked' => 'تم إيقاف جهازك وإلغاء جلساته. تواصل مع الإدارة للاستفسار.',
                default => $user?->require_device_approval ? 'جهازك ينتظر اعتماد الإدارة. تفعيل الحساب واعتماد الجهاز خطوتان مستقلتان.' : 'تم تسجيل جهاز جديد لحسابك. تواصل مع الإدارة إن لم تكن أنت.',
            };
        } else {
            return;
        }
        if ($user !== null) {
            $key = 'audit:'.$auditEvent->id;
            $this->notifications->record($user, $user->role, $key, $kind, $auditEvent->subject_id, $status ?? 'active', $title, $body);
            $this->messages->forUser($user, $key, $kind, $body);
        }
    }
}
