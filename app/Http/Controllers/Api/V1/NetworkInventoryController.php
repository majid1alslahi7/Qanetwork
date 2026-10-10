<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\InventoryCard;
use App\Models\Network;
use App\Models\NetworkProduct;
use App\Services\Networks\ImportInventoryCardsService;
use App\Services\Networks\ReadInventorySpreadsheetService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NetworkInventoryController extends Controller
{
    public function index(Request $request, string $network, string $product): JsonResponse
    {
        $target = $this->product($request, $network, $product);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $stock = InventoryCard::query()->where('network_id', $target->network_id)->where('network_product_id', $target->id);
        $counts = (clone $stock)->selectRaw('status, COUNT(*) as quantity')->groupBy('status')->pluck('quantity', 'status');
        $counts['expired'] = (clone $stock)->where('status', 'available')->whereNotNull('expires_at')->where('expires_at', '<=', now())->count();
        $counts['available'] = (clone $stock)->where('status', 'available')->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count();
        $cards = $stock->select(['id', 'status', 'created_at', 'allocated_at', 'expires_at'])->orderByDesc('id')->paginate(25);

        return response()->json(['data' => $cards->items(), 'summary' => $counts, 'meta' => ['current_page' => $cards->currentPage(), 'last_page' => $cards->lastPage(), 'total' => $cards->total()]]);
    }

    public function store(Request $request, string $network, string $product, ReadInventorySpreadsheetService $reader, ImportInventoryCardsService $importer): JsonResponse
    {
        $target = $this->product($request, $network, $product);
        $validated = $request->validate([
            'file' => ['required_without:cards', Rule::prohibitedIf($request->has('cards')), 'file', 'max:5120', 'extensions:xlsx,csv'],
            'cards' => ['required_without:file', Rule::prohibitedIf($request->hasFile('file')), 'array', 'min:1', 'max:5000'],
            'cards.*' => ['array:username,password'],
            'cards.*.username' => ['required', 'string', 'max:255'],
            'cards.*.password' => ['nullable', 'string', 'max:1024'],
        ]);
        $rows = $request->hasFile('file') ? $reader->read($request->file('file')) : $validated['cards'];
        $count = $importer->handle($request->user(), $target->network, $target, $rows);

        return response()->json(['data' => ['imported_count' => $count, 'product_id' => $target->id]], 201);
    }

    private function product(Request $request, string $network, string $product): NetworkProduct
    {
        $ownerId = $request->user()->role === UserRole::NETWORK_OWNER ? $request->user()->networkOwner()->firstOrFail()->id : null;
        $owned = Network::query()->when($ownerId !== null, fn (Builder $query) => $query->where('network_owner_id', $ownerId))->findOrFail($network);

        return $owned->products()->findOrFail($product);
    }
}
