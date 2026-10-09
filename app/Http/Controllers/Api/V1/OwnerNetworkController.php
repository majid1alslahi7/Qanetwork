<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\NetworkResource;
use App\Http\Resources\Api\V1\OwnerNetworkProductResource;
use App\Models\Network;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OwnerNetworkController extends Controller
{
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
