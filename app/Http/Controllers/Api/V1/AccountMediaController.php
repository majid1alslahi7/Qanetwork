<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreImageRequest;
use App\Models\AuditEvent;
use App\Models\Network;
use App\Models\NetworkProduct;
use App\Models\SellerDeposit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AccountMediaController extends Controller
{
    public function receipt(Request $request, string $deposit): JsonResponse
    {
        return $this->image($this->deposit($request, $deposit)->receipt_path);
    }

    public function storeReceipt(StoreImageRequest $request, string $deposit): JsonResponse
    {
        $record = $this->deposit($request, $deposit);
        abort_unless($request->user()->role === UserRole::SELLER, 403);
        $file = $request->file('file');
        $path = 'deposit-receipts/'.$record->id.'/'.hash_file('sha256', $file->getRealPath()).'.'.$file->extension();
        DB::transaction(function () use ($request, $record, $file, $path): void {
            $current = SellerDeposit::query()->lockForUpdate()->findOrFail($record->id);
            if ($current->receipt_path === $path && Storage::disk('local')->exists($path)) {
                return;
            }
            abort_unless($current->status === 'pending', 409, 'Receipts cannot be changed after review.');
            if (Storage::disk('local')->putFileAs(dirname($path), $file, basename($path)) === false) {
                abort(503, 'Unable to save receipt.');
            }
            $current->receipt_path = $path;
            $current->save();
            AuditEvent::query()->create(['actor_id' => $request->user()->id, 'event_type' => 'deposit.receipt_uploaded', 'subject_type' => 'seller_deposit', 'subject_id' => $current->id]);
        }, 3);

        return response()->json(['data' => ['receipt_version' => substr(hash('sha256', $path), 0, 20)]]);
    }

    public function cover(Request $request, string $network, string $product): JsonResponse
    {
        return $this->image($this->product($request, $network, $product)->cover_path);
    }

    public function storeCover(StoreImageRequest $request, string $network, string $product): JsonResponse
    {
        abort_unless(in_array($request->user()->role, [UserRole::ADMIN, UserRole::NETWORK_OWNER], true), 403);
        $record = $this->product($request, $network, $product);
        $file = $request->file('file');
        [$width, $height] = getimagesize($file->getRealPath());
        if ($width < 640 || $width / $height < 1.5 || $width / $height > 2.5) {
            throw ValidationException::withMessages(['file' => 'Choose a landscape cover at least 640px wide with a ratio between 1.5 and 2.5.']);
        }
        $path = 'product-covers/'.$record->id.'/'.hash_file('sha256', $file->getRealPath()).'.'.$file->extension();
        DB::transaction(function () use ($request, $record, $file, $path): void {
            $current = NetworkProduct::query()->lockForUpdate()->findOrFail($record->id);
            if ($current->cover_path === $path && Storage::disk('local')->exists($path)) {
                return;
            }
            if (Storage::disk('local')->putFileAs(dirname($path), $file, basename($path)) === false) {
                abort(503, 'Unable to save cover.');
            }
            $current->cover_path = $path;
            $current->save();
            AuditEvent::query()->create(['actor_id' => $request->user()->id, 'event_type' => 'product.cover_uploaded', 'subject_type' => 'network_product', 'subject_id' => $current->id]);
        }, 3);

        return response()->json(['data' => ['cover_version' => substr(hash('sha256', $path), 0, 20)]]);
    }

    private function deposit(Request $request, string $deposit): SellerDeposit
    {
        return $request->user()->role === UserRole::ADMIN ? SellerDeposit::query()->findOrFail($deposit)
            : $request->user()->seller()->firstOrFail()->deposits()->findOrFail($deposit);
    }

    private function product(Request $request, string $network, string $product): NetworkProduct
    {
        $query = Network::query();
        if ($request->user()->role === UserRole::NETWORK_OWNER) {
            $query->where('network_owner_id', $request->user()->networkOwner()->firstOrFail()->id);
        } elseif ($request->user()->role === UserRole::SELLER) {
            $query->where('status', 'active');
        }

        return $query->findOrFail($network)->products()
            ->when($request->user()->role === UserRole::SELLER, fn ($query) => $query->where('status', 'active'))->findOrFail($product);
    }

    private function image(?string $path): JsonResponse
    {
        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);
        $bytes = Storage::disk('local')->get($path);
        abort_if($bytes === null || strlen($bytes) > 512 * 1024, 404);

        return response()->json(['data' => ['content' => base64_encode($bytes)]])->header('Cache-Control', 'private, no-store');
    }
}
