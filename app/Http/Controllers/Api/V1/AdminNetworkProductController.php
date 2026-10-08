<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreNetworkProductRequest;
use App\Http\Requests\Api\V1\UpdateNetworkProductStatusRequest;
use App\Http\Resources\Api\V1\NetworkProductResource;
use App\Models\Network;
use App\Models\NetworkProduct;
use App\Services\Networks\ManageNetworkProductService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class AdminNetworkProductController extends Controller
{
    public function index(Request $request, Network $network): AnonymousResourceCollection
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return NetworkProductResource::collection($network->products()->orderBy('id')->paginate(25));
    }

    public function store(StoreNetworkProductRequest $request, Network $network, ManageNetworkProductService $service): NetworkProductResource
    {
        try {
            return new NetworkProductResource($service->create($request->user(), $network, $request->validated()));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['external_product_id' => 'This provider product already exists in the network.']);
        }
    }

    public function updateStatus(UpdateNetworkProductStatusRequest $request, Network $network, NetworkProduct $product, ManageNetworkProductService $service): NetworkProductResource
    {
        return new NetworkProductResource($service->updateStatus($request->user(), $network, $product, $request->validated('status')));
    }
}
