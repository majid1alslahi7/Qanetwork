<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreNetworkConnectionRequest;
use App\Http\Requests\Api\V1\UpdateNetworkConnectionStatusRequest;
use App\Http\Resources\Api\V1\NetworkConnectionResource;
use App\Models\Network;
use App\Models\NetworkConnection;
use App\Services\Networks\ManageNetworkConnectionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminNetworkConnectionController extends Controller
{
    public function index(Request $request, Network $network): AnonymousResourceCollection
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return NetworkConnectionResource::collection($network->connections()->orderBy('id')->paginate(25));
    }

    public function store(StoreNetworkConnectionRequest $request, Network $network, ManageNetworkConnectionService $service): NetworkConnectionResource
    {
        return new NetworkConnectionResource($service->create($request->user(), $network, $request->validated()));
    }

    public function updateStatus(UpdateNetworkConnectionStatusRequest $request, Network $network, NetworkConnection $connection, ManageNetworkConnectionService $service): NetworkConnectionResource
    {
        return new NetworkConnectionResource($service->updateStatus($request->user(), $network, $connection,
            $request->boolean('is_enabled'), $request->boolean('is_primary')));
    }
}
