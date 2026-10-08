<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreNetworkRequest;
use App\Http\Requests\Api\V1\UpdateNetworkStatusRequest;
use App\Http\Resources\Api\V1\NetworkResource;
use App\Models\Network;
use App\Services\Networks\ManageNetworkService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminNetworkController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return NetworkResource::collection(Network::query()->orderBy('id')->paginate(25));
    }

    public function show(Network $network): NetworkResource
    {
        return new NetworkResource($network);
    }

    public function store(StoreNetworkRequest $request, ManageNetworkService $service): NetworkResource
    {
        return new NetworkResource($service->create($request->user(), $request->validated()));
    }

    public function updateStatus(UpdateNetworkStatusRequest $request, Network $network, ManageNetworkService $service): NetworkResource
    {
        return new NetworkResource($service->updateStatus($request->user(), $network, $request->validated('status')));
    }
}
