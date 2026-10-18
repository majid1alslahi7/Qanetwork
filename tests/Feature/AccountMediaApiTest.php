<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Network;
use App\Models\NetworkOwner;
use App\Models\Seller;
use App\Models\SellerDeposit;
use App\Models\SellerWallet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountMediaApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_is_private_scoped_idempotent_and_does_not_credit_wallet(): void
    {
        Storage::fake('local');
        [$user, $deposit] = $this->deposit();
        $url = '/api/v1/seller/deposits/'.$deposit->id.'/receipt';
        $this->getJson($url)->assertUnauthorized();
        Sanctum::actingAs($user, ['account', 'seller']);
        $uploaded = $this->post($url, ['file' => $this->image()], ['Accept' => 'application/json'])->assertOk()->json('data.receipt_version');
        $path = $deposit->fresh()->receipt_path;
        Storage::disk('local')->assertExists($path);
        $this->getJson('/api/v1/seller/deposits/'.$deposit->id)->assertOk()->assertJsonPath('data.receipt_version', $uploaded)->assertJsonMissingPath('data.receipt_path');
        $this->getJson($url)->assertOk()->assertJsonPath('data.content', base64_encode(Storage::disk('local')->get($path)));
        $this->post($url, ['file' => $this->image()], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.receipt_version', $uploaded);
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertSame('0.0000', $deposit->wallet->balance);
        $this->assertDatabaseCount('seller_ledger_entries', 0);
        [$other] = $this->deposit();
        Sanctum::actingAs($other, ['account', 'seller']);
        $this->getJson($url)->assertNotFound();
        $this->post($url, ['file' => $this->image()], ['Accept' => 'application/json'])->assertNotFound();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]), ['account', 'admin']);
        $this->getJson('/api/v1/admin/deposits/'.$deposit->id.'/receipt')->assertOk();
    }

    public function test_receipt_rejects_active_formats_oversize_and_changes_after_review(): void
    {
        Storage::fake('local');
        [$user, $deposit] = $this->deposit();
        Sanctum::actingAs($user, ['account', 'seller']);
        $url = '/api/v1/seller/deposits/'.$deposit->id.'/receipt';
        $this->post($url, ['file' => UploadedFile::fake()->createWithContent('image.jpg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post($url, ['file' => UploadedFile::fake()->create('image.jpg', 513, 'image/jpeg')], ['Accept' => 'application/json'])->assertUnprocessable();
        $deposit->status = 'approved';
        $deposit->save();
        $this->post($url, ['file' => $this->image()], ['Accept' => 'application/json'])->assertConflict();
        $this->assertNull($deposit->fresh()->receipt_path);
        Storage::disk('local')->assertDirectoryEmpty('deposit-receipts');
    }

    public function test_owner_cover_requires_own_network_landscape_and_is_readable_by_eligible_seller(): void
    {
        Storage::fake('local');
        $ownerUser = User::factory()->create(['role' => UserRole::NETWORK_OWNER]);
        $owner = NetworkOwner::query()->create(['user_id' => $ownerUser->id, 'code' => 'owner', 'name' => 'Owner']);
        $owner->user_id = $ownerUser->id;
        $owner->status = 'active';
        $owner->save();
        $network = Network::query()->create(['network_owner_id' => $owner->id, 'code' => 'network', 'name' => 'Network']);
        $network->status = 'active';
        $network->save();
        $product = $network->products()->create(['code' => 'product', 'name' => 'Package', 'external_product_id' => 'day', 'face_value' => '500']);
        $product->status = 'inactive';
        $product->save();
        $url = '/api/v1/owner/networks/'.$network->id.'/products/'.$product->id.'/cover';
        Sanctum::actingAs($ownerUser, ['account', 'network_owner']);
        $this->post($url, ['file' => $this->image(360, 640)], ['Accept' => 'application/json'])->assertUnprocessable();
        $version = $this->post($url, ['file' => $this->image(640, 360)], ['Accept' => 'application/json'])->assertOk()->json('data.cover_version');
        $this->getJson('/api/v1/owner/networks/'.$network->id.'/products')->assertOk()->assertJsonPath('data.0.cover_version', $version)->assertJsonMissingPath('data.0.cover_path');
        Storage::disk('local')->assertExists($product->fresh()->cover_path);
        [$seller] = $this->deposit();
        Sanctum::actingAs($seller, ['account', 'seller']);
        $sellerUrl = str_replace('/owner/', '/seller/', $url);
        $this->getJson($sellerUrl)->assertNotFound();
        $product->status = 'active';
        $product->save();
        $this->getJson($sellerUrl)->assertOk();
        $foreignOwner = User::factory()->create(['role' => UserRole::NETWORK_OWNER]);
        NetworkOwner::query()->create(['code' => 'other-owner', 'name' => 'Other'])->forceFill(['status' => 'active', 'user_id' => $foreignOwner->id])->save();
        Sanctum::actingAs($foreignOwner, ['account', 'network_owner']);
        $this->getJson($url)->assertNotFound();
        $this->post($url, ['file' => $this->image(640, 360)], ['Accept' => 'application/json'])->assertNotFound();
    }

    private function deposit(): array
    {
        $user = User::factory()->create(['role' => UserRole::SELLER]);
        $seller = Seller::query()->create(['user_id' => $user->id, 'code' => 'SEL-'.Str::ulid()]);
        $seller->status = 'active';
        $seller->save();
        $wallet = SellerWallet::query()->create(['seller_id' => $seller->id, 'currency_code' => 'YER']);
        $deposit = SellerDeposit::query()->create(['seller_id' => $seller->id, 'seller_wallet_id' => $wallet->id, 'reference_no' => 'DEP-'.Str::ulid(), 'amount' => '100', 'currency_code' => 'YER', 'payment_method' => 'cash', 'submitted_at' => now()]);

        return [$user, $deposit];
    }

    private function image(int $width = 640, int $height = 360): UploadedFile
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat("\0".str_repeat("\x44\x88\xaa", $width), $height))).$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent('image.png', $png);
    }
}
