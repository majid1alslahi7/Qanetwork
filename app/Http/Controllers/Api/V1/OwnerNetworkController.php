<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreNetworkConnectionRequest;
use App\Http\Requests\Api\V1\StoreNetworkProductRequest;
use App\Http\Requests\Api\V1\StoreNetworkRequest;
use App\Http\Requests\Api\V1\UpdateNetworkConnectionStatusRequest;
use App\Http\Requests\Api\V1\UpdateNetworkProductStatusRequest;
use App\Http\Resources\Api\V1\NetworkConnectionResource;
use App\Http\Resources\Api\V1\NetworkResource;
use App\Http\Resources\Api\V1\OwnerNetworkProductResource;
use App\Models\Network;
use App\Models\NetworkConnection;
use App\Models\NetworkProduct;
use App\Services\Networks\ManageNetworkConnectionService;
use App\Services\Networks\ManageNetworkProductService;
use App\Services\Networks\ManageNetworkService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class OwnerNetworkController extends Controller
{
    public function store(StoreNetworkRequest $request, ManageNetworkService $service): NetworkResource
    {
        return new NetworkResource($service->create($request->user(), $request->validated()));
    }

    public function storeProduct(StoreNetworkProductRequest $request, Network $network, ManageNetworkProductService $service): OwnerNetworkProductResource
    {
        try {
            return new OwnerNetworkProductResource($service->create($request->user(), $network, $request->validated()));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['external_product_id' => 'This provider product already exists in the network.']);
        }
    }

    public function connections(Request $request, string $network): AnonymousResourceCollection
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return NetworkConnectionResource::collection($this->ownedNetwork($request, $network)->connections()->orderBy('id')->paginate(25));
    }

    public function storeConnection(StoreNetworkConnectionRequest $request, Network $network, ManageNetworkConnectionService $service): NetworkConnectionResource
    {
        return new NetworkConnectionResource($service->create($request->user(), $network, $request->validated()));
    }

    public function updateProductStatus(UpdateNetworkProductStatusRequest $request, Network $network, NetworkProduct $product, ManageNetworkProductService $service): OwnerNetworkProductResource
    {
        return new OwnerNetworkProductResource($service->updateStatus($request->user(), $network, $product, $request->validated('status')));
    }

    public function updateConnectionStatus(UpdateNetworkConnectionStatusRequest $request, Network $network, NetworkConnection $connection, ManageNetworkConnectionService $service): NetworkConnectionResource
    {
        return new NetworkConnectionResource($service->updateStatus($request->user(), $network, $connection, $request->boolean('is_enabled'), $request->boolean('is_primary')));
    }

    public function checkConnection(Request $request, Network $network, NetworkConnection $connection, AdminNetworkReadinessController $controller): JsonResponse
    {
        $this->ownedNetwork($request, $network->id);

        return $controller->check($request, $network, $connection);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'network_owner_id' => ['prohibited']]);

        return NetworkResource::collection($request->user()->networkOwner()->firstOrFail()->networks()
            ->orderBy('name')->orderBy('id')->paginate(25));
    }

    public function show(Request $request, string $network): NetworkResource
    {
        return new NetworkResource($this->ownedNetwork($request, $network));
    }

    public function products(Request $request, string $network): AnonymousResourceCollection
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'network_owner_id' => ['prohibited']]);

        return OwnerNetworkProductResource::collection($this->ownedNetwork($request, $network)->products()
            ->orderBy('face_value')->orderBy('id')->paginate(25));
    }

    private function ownedNetwork(Request $request, string $network): Network
    {
        return $request->user()->networkOwner()->firstOrFail()->networks()->findOrFail($network);
    }
}
