<?php

namespace App\Http\Controllers\Api\V1\Reference;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reference\IndexCityRequest;
use App\Http\Resources\Api\V1\CityResource;
use App\Models\City;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class CityController extends Controller
{
    public function index(IndexCityRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $search = $validated['search'] ?? null;
        $activeOnly = $validated['active_only'] ?? true;

        $cities = City::query()
            ->where('country_code', 'SY')
            ->when($activeOnly, fn (Builder $query): Builder => $query->where('is_active', true))
            ->when(filled($search), function (Builder $query) use ($search): void {
                $query->where(function (Builder $builder) use ($search): void {
                    $builder->where('code', 'like', '%'.$search.'%')
                        ->orWhere('name_ar', 'like', '%'.$search.'%')
                        ->orWhere('name_en', 'like', '%'.$search.'%');
                });
            })
            ->orderBy(app()->getLocale() === 'ar' ? 'name_ar' : 'name_en')
            ->get();

        return ApiResponse::success(
            data: CityResource::collection($cities),
            message: __('reference.cities_retrieved'),
        );
    }
}
