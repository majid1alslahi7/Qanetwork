<?php

namespace App\Observers;

use App\Enums\UserRole;
use App\Models\AccountRegistration;
use App\Models\Network;
use App\Models\ProviderSettlement;
use App\Models\Sale;
use App\Models\SellerDeposit;
use App\Services\Delivery\QueueOperationalMessageService;
use App\Services\Operations\RecordAccountNotificationService;
use Illuminate\Database\Eloquent\Model;

class OperationalNotificationObserver
{
    public function __construct(private readonly RecordAccountNotificationService $notifications, private readonly QueueOperationalMessageService $messages) {}

    public function saved(Model $record): void
    {
        $status = (string) $record->getAttribute('status');
        $event = $record::class.'|'.$record->getKey().'|'.$status;
        if ($record instanceof Sale && $record->wasChanged('status') && in_array($status, ['completed', 'failed', 'timeout', 'unknown_provider_state', 'reconciliation_required'], true)) {
            $title = $status === 'completed' ? 'اكتملت عملية البيع' : ($status === 'failed' ? 'لم تكتمل عملية البيع' : 'عملية بيع تحتاج متابعة');
            $message = $status === 'completed' ? 'الكرت متاح من تفاصيل العملية.' : 'راجع تفاصيل العملية وحالة الرصيد. لا تُعد الطلب قبل التأكد من نتيجته.';
            $this->notifications->record($record->seller()->first()?->user()->first(), UserRole::SELLER, $event, 'sale', $record->id, $status, $title, $message);
            if ($status !== 'completed') {
                $this->notifications->administrators($event, 'sale', $record->id, $status, $title, $message);
            }
        } elseif ($record instanceof SellerDeposit && ($record->wasRecentlyCreated || $record->wasChanged('status')) && in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $title = match ($status) {
                'approved' => 'تم اعتماد الإيداع', 'rejected' => 'تم رفض الإيداع', default => 'طلب إيداع جديد'
            };
            $message = 'راجع الإيداع وسجل المحفظة لمعرفة تفاصيل الطلب.';
            $this->notifications->record($record->seller()->first()?->user()->first(), UserRole::SELLER, $event, 'deposit', $record->id, $status, $title, $message);
            if ($status !== 'pending') {
                $body = $status === 'approved' ? 'تم اعتماد إيداعك وإضافة الرصيد إلى المحفظة. راجع التطبيق للتفاصيل.' : 'لم يُعتمد إيداعك. راجع سبب القرار داخل التطبيق أو تواصل مع الإدارة.';
                $this->messages->forUser($record->seller()->first()?->user()->first(), $event, 'deposit', $body);
            }
            if ($status === 'pending') {
                $this->notifications->administrators($event, 'deposit', $record->id, $status, $title, $message);
            }
        } elseif ($record instanceof ProviderSettlement && $record->wasRecentlyCreated) {
            $this->notifications->record($record->owner()->first()?->user()->first(), UserRole::NETWORK_OWNER, $event, 'settlement', $record->id, 'paid', 'تم تسجيل تسوية مستحقات', 'راجع التسوية وتوزيعها على مستحقات الشبكات.');
            $this->messages->forUser($record->owner()->first()?->user()->first(), $event, 'settlement', 'تم تسجيل تسوية مستحقاتك. راجع التطبيق للتفاصيل.');
        } elseif ($record instanceof AccountRegistration && $record->wasRecentlyCreated && $status === 'pending') {
            $this->notifications->administrators($event, 'registration', $record->id, $status, 'طلب تسجيل جديد', 'يوجد طلب حساب جديد بانتظار مراجعة الإدارة.');
            $this->messages->queue($record->phone, $event, 'registration_received', 'استلمنا طلب تسجيلك وهو بانتظار مراجعة الإدارة. سنخبرك بقرار التفعيل.');
        } elseif ($record instanceof AccountRegistration && $record->wasChanged('status') && $status === 'rejected') {
            $this->messages->queue($record->phone, $event, 'registration_rejected', 'لم يُعتمد طلب التسجيل. تواصل مع الإدارة للاستفسار.');
        } elseif ($record instanceof Network && $record->wasChanged('health_status') && in_array($record->health_status, ['healthy', 'unhealthy'], true)) {
            $event .= '|'.$record->health_status.'|'.$record->last_health_check_at?->toISOString();
            $title = $record->health_status === 'healthy' ? 'الاتصال الرئيسي يعمل' : 'تعذر الاتصال بالمصدر الرئيسي';
            $message = 'راجع صحة مصادر الشبكة؛ جاهزية كل باقة تعتمد على مصدرها المحدد.';
            $this->notifications->record($record->owner()->first()?->user()->first(), UserRole::NETWORK_OWNER, $event, 'network', $record->id, $record->health_status, $title, $message);
            $this->notifications->administrators($event, 'network', $record->id, $record->health_status, $title, $message);
        }
    }
}
