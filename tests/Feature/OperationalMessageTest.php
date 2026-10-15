<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\SendOperationalMessageJob;
use App\Models\AccountDevice;
use App\Models\AccountRegistration;
use App\Models\AuditEvent;
use App\Models\OperationalMessage;
use App\Models\Seller;
use App\Models\SellerDeposit;
use App\Models\SellerWallet;
use App\Models\User;
use App\Services\Accounts\ReviewAccountRegistrationService;
use App\Services\Delivery\QueueOperationalMessageService;
use App\Services\Delivery\SendOperationalMessageService;
use App\Services\Delivery\YemenPhoneNumber;
use App\Services\Finance\ApproveSellerDepositService;
use App\Services\Finance\RejectSellerDepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OperationalMessageTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://aalcgjgocszngpcqzjih.supabase.co/functions/v1/public-api';

    private const ID = '8f3c2a91-3456-4567-8123-123456789abc';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.quick_connect.enabled' => true, 'services.quick_connect.operational_enabled' => true, 'services.quick_connect.api_key' => 'qc_live_test']);
        Http::preventStrayRequests();
    }

    public function test_queues_one_encrypted_formal_message_per_event_and_registration_phone_per_day(): void
    {
        Queue::fake([SendOperationalMessageJob::class]);
        $service = app(QueueOperationalMessageService::class);

        $first = $service->queue('776450127', 'register:1', 'registration_received', 'طلبك قيد المراجعة', 'الملكي نت');
        $duplicate = $service->queue('+967776450127', 'register:2', 'registration_received', 'طلبك قيد المراجعة', 'الملكي نت');

        $this->assertSame($first->id, $duplicate->id);
        $this->assertSame('+967776450127', $first->recipient);
        $this->assertSame("شبكة الملكي نت\nطلب التسجيل\nطلبك قيد المراجعة\nخدمة QaNetwork", $first->body);
        $this->assertStringNotContainsString('776450127', DB::table('operational_messages')->value('recipient'));
        $this->assertStringNotContainsString('طلبك قيد المراجعة', DB::table('operational_messages')->value('body'));
        $this->assertArrayNotHasKey('recipient', $first->toArray());
        $this->assertArrayNotHasKey('body', $first->toArray());
        Queue::assertPushed(SendOperationalMessageJob::class, 1);
    }

    public function test_rollback_does_not_leave_message_or_dispatch_job(): void
    {
        Queue::fake([SendOperationalMessageJob::class]);
        DB::beginTransaction();
        app(QueueOperationalMessageService::class)->queue('776450127', 'account:1', 'account', 'تم التفعيل');

        DB::rollBack();

        $this->assertDatabaseCount('operational_messages', 0);
        Queue::assertNothingPushed();
    }

    public function test_accepted_message_is_polled_and_never_submitted_twice(): void
    {
        Queue::fake([SendOperationalMessageJob::class]);
        $message = app(QueueOperationalMessageService::class)->queue('776450127', 'event:1', 'device', 'تم اعتماد الجهاز');
        Http::fake([
            self::URL.'/messages' => Http::response(['ids' => [self::ID], 'channel_requested' => 'sms', 'channels_used' => ['sms'], 'to' => '+967776450127', 'status' => 'queued'], 201),
            self::URL.'/messages/'.self::ID => Http::response(['success' => true, 'message' => ['id' => self::ID, 'channel' => 'sms', 'status' => 'sent']]),
        ]);

        app(SendOperationalMessageService::class)->handle($message->id);
        $this->assertSame('submitted', $message->fresh()->status);
        app(SendOperationalMessageService::class)->handle($message->id);
        app(SendOperationalMessageService::class)->handle($message->id);

        $this->assertSame('sent', $message->fresh()->status);
        Http::assertSentCount(2);
        Queue::assertPushed(SendOperationalMessageJob::class, 1);
    }

    public function test_uncertain_transport_failure_is_not_retried(): void
    {
        Queue::fake([SendOperationalMessageJob::class]);
        $message = app(QueueOperationalMessageService::class)->queue('776450127', 'event:1', 'device', 'تم اعتماد الجهاز');
        Http::fake([self::URL.'/messages' => Http::failedConnection()]);

        app(SendOperationalMessageService::class)->handle($message->id);
        app(SendOperationalMessageService::class)->handle($message->id);

        $this->assertSame('unknown', $message->fresh()->status);
        Http::assertSentCount(1);
        Queue::assertPushed(SendOperationalMessageJob::class, 1);
    }

    public function test_device_approval_notice_does_not_activate_suspended_account(): void
    {
        Queue::fake([SendOperationalMessageJob::class]);
        $user = User::factory()->create(['role' => UserRole::ADMIN, 'status' => 'suspended']);
        $user->sms_phone = '+967776450127';
        $user->save();
        $device = AccountDevice::factory()->create(['user_id' => $user->id, 'status' => 'approved']);

        AuditEvent::query()->create(['actor_id' => $user->id, 'event_type' => 'device.status_changed', 'subject_type' => 'account_device', 'subject_id' => $device->id, 'after' => ['status' => 'approved']]);

        $this->assertSame('suspended', $user->fresh()->status);
        $this->assertStringContainsString('الدخول يتطلب حسابًا فعالًا', OperationalMessage::query()->firstOrFail()->body);
        $this->assertSame('device', $user->notifications()->firstOrFail()->data['kind']);
        Queue::assertPushed(SendOperationalMessageJob::class, 1);
    }

    public function test_disabled_operational_messages_and_invalid_numbers_do_not_dispatch(): void
    {
        Queue::fake([SendOperationalMessageJob::class]);
        config(['services.quick_connect.operational_enabled' => false]);

        $this->assertNull(app(QueueOperationalMessageService::class)->queue('776450127', 'event:1', 'device', 'تم التفعيل'));
        config(['services.quick_connect.operational_enabled' => true]);
        $this->assertNull(app(QueueOperationalMessageService::class)->queue('+15551234567', 'event:2', 'device', 'تم التفعيل'));

        $this->assertDatabaseCount('operational_messages', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_registration_stores_normalized_encrypted_phone_without_exposing_it(): void
    {
        Queue::fake([SendOperationalMessageJob::class]);

        $response = $this->postJson('/api/v1/auth/register', ['name' => 'New Seller', 'email' => 'new@example.com', 'phone' => '0776450127', 'role' => 'seller', 'currency_code' => 'YER', 'password' => 'Password123!Abc', 'password_confirmation' => 'Password123!Abc'])->assertCreated();

        $this->assertSame('+967776450127', AccountRegistration::query()->findOrFail($response->json('data.id'))->phone);
        $this->assertStringNotContainsString('776450127', DB::table('account_registrations')->value('phone'));
        $this->assertDatabaseCount('operational_messages', 1);
        Queue::assertPushed(SendOperationalMessageJob::class, 1);
    }

    #[DataProvider('depositDecisions')]
    public function test_deposit_decision_sends_one_notice_without_affecting_financial_idempotency(string $decision, string $balance): void
    {
        Queue::fake([SendOperationalMessageJob::class]);
        $user = User::factory()->create(['sms_phone' => '+967776450127']);
        $seller = Seller::query()->create(['user_id' => $user->id, 'code' => 'seller-test']);
        $wallet = SellerWallet::query()->create(['seller_id' => $seller->id, 'currency_code' => 'YER']);
        $deposit = SellerDeposit::query()->create(['seller_id' => $seller->id, 'seller_wallet_id' => $wallet->id, 'reference_no' => 'DEP-TEST', 'amount' => '500', 'currency_code' => 'YER', 'payment_method' => 'cash', 'submitted_at' => now()]);
        $service = $decision === 'approved' ? app(ApproveSellerDepositService::class) : app(RejectSellerDepositService::class);

        $service->handle($deposit, $user);
        $service->handle($deposit->fresh(), $user);

        $this->assertSame($balance, $wallet->fresh()->balance);
        $this->assertSame('deposit', OperationalMessage::query()->sole()->kind);
        $this->assertStringNotContainsString('كلمة المرور', OperationalMessage::query()->sole()->body);
        Queue::assertPushed(SendOperationalMessageJob::class, 1);
    }

    public static function depositDecisions(): array
    {
        return [['approved', '500.0000'], ['rejected', '0.0000']];
    }

    public function test_registration_approval_uses_registered_phone_and_only_one_activation_message(): void
    {
        Queue::fake([SendOperationalMessageJob::class]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $registration = AccountRegistration::factory()->create(['role' => 'seller', 'phone' => '+967776450127']);

        app(ReviewAccountRegistrationService::class)->handle($admin, $registration, 'approved', 'Approved identity');
        app(ReviewAccountRegistrationService::class)->handle($admin, $registration->fresh(), 'approved', 'Approved identity');

        $user = User::query()->findOrFail($registration->fresh()->user_id);
        $this->assertSame('+967776450127', $user->sms_phone);
        $this->assertSame(1, OperationalMessage::query()->where('kind', 'account')->count());
        $this->assertStringNotContainsString('776450127', $user->toJson());
        Queue::assertPushed(SendOperationalMessageJob::class, 2);
    }

    #[DataProvider('phoneFormats')]
    public function test_normalizes_supported_yemeni_phone_formats(string $input, ?string $expected): void
    {
        $this->assertSame($expected, YemenPhoneNumber::normalize($input));
    }

    public static function phoneFormats(): array
    {
        return [['776450127', '+967776450127'], ['0776450127', '+967776450127'], ['+967776450127', '+967776450127'], ['967776450127', '+967776450127'], ['00967776450127', '+967776450127'], ['٧٧٦٤٥٠١٢٧', '+967776450127'], ['+15551234567', null], ['721234567', null], ['', null]];
    }
}
