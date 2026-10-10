<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSellerContactRequest;
use App\Http\Resources\Api\V1\SellerContactResource;
use App\Services\Accounts\ManageSellerContactService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class SellerContactController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'search' => ['sometimes', 'string', 'max:150'], 'favorite' => ['sometimes', 'boolean']]);
        $query = $request->user()->seller()->where('status', 'active')->firstOrFail()->contacts();

        return SellerContactResource::collection($query
            ->when(isset($data['search']), fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$data['search'].'%')->orWhere('phone', 'like', '%'.$data['search'].'%')))
            ->when(isset($data['favorite']), fn ($query) => $query->where('is_favorite', $data['favorite']))
            ->orderByDesc('is_favorite')->orderBy('name')->orderBy('id')->paginate(25));
    }

    public function show(Request $request, string $contact): SellerContactResource
    {
        return new SellerContactResource($request->user()->seller()->firstOrFail()->contacts()->findOrFail($contact));
    }

    public function store(StoreSellerContactRequest $request, ManageSellerContactService $service): SellerContactResource
    {
        return $this->save($request, $service, null);
    }

    public function update(StoreSellerContactRequest $request, string $contact, ManageSellerContactService $service): SellerContactResource
    {
        return $this->save($request, $service, $contact);
    }

    public function destroy(Request $request, string $contact, ManageSellerContactService $service): Response
    {
        $service->delete($request->user(), $contact);

        return response()->noContent();
    }

    private function save(StoreSellerContactRequest $request, ManageSellerContactService $service, ?string $contact): SellerContactResource
    {
        try {
            return new SellerContactResource($service->save($request->user(), $contact, $request->validated()));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['phone' => 'This phone already belongs to one of your contacts.']);
        }
    }
}
