<?php

namespace App\Http\Controllers\Api\V1\Application;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Application\InternalNote\DeleteApplicationInternalNoteRequest;
use App\Http\Requests\Api\V1\Application\InternalNote\IndexApplicationInternalNoteRequest;
use App\Http\Requests\Api\V1\Application\InternalNote\IndexApplicationInternalNoteRevisionRequest;
use App\Http\Requests\Api\V1\Application\InternalNote\ShowApplicationInternalNoteRequest;
use App\Http\Requests\Api\V1\Application\InternalNote\StoreApplicationInternalNoteRequest;
use App\Http\Requests\Api\V1\Application\InternalNote\UpdateApplicationInternalNoteRequest;
use App\Http\Resources\Api\V1\ApplicationInternalNoteResource;
use App\Http\Resources\Api\V1\ApplicationInternalNoteRevisionResource;
use App\Models\ApplicationInternalNote;
use App\Models\JobApplication;
use App\Services\ApplicationInternalNoteService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ApplicationInternalNoteController extends Controller
{
    public function __construct(private readonly ApplicationInternalNoteService $service) {}

    public function index(IndexApplicationInternalNoteRequest $request, JobApplication $jobApplication): JsonResponse
    {
        return ApiResponse::success(ApplicationInternalNoteResource::collection(
            $this->service->listForApplication($request->user(), $jobApplication, $request->validated())
        ), 'Internal notes retrieved successfully.');
    }

    public function store(StoreApplicationInternalNoteRequest $request, JobApplication $jobApplication): JsonResponse
    {
        return ApiResponse::success(new ApplicationInternalNoteResource(
            $this->service->create($request->user(), $jobApplication, $request->validated('body'))
        ), 'Internal note created successfully.', 201);
    }

    public function show(ShowApplicationInternalNoteRequest $request, ApplicationInternalNote $note): JsonResponse
    {
        return ApiResponse::success(new ApplicationInternalNoteResource($this->service->show($request->user(), $note)), 'Internal note retrieved successfully.');
    }

    public function update(UpdateApplicationInternalNoteRequest $request, ApplicationInternalNote $note): JsonResponse
    {
        return ApiResponse::success(new ApplicationInternalNoteResource(
            $this->service->update($request->user(), $note, $request->validated('body'), $request->integer('version'))
        ), 'Internal note updated successfully.');
    }

    public function destroy(DeleteApplicationInternalNoteRequest $request, ApplicationInternalNote $note): JsonResponse
    {
        return ApiResponse::success(new ApplicationInternalNoteResource(
            $this->service->softDelete($request->user(), $note, $request->integer('version'))
        ), 'Internal note deleted successfully.');
    }

    public function revisions(IndexApplicationInternalNoteRevisionRequest $request, ApplicationInternalNote $note): JsonResponse
    {
        return ApiResponse::success(ApplicationInternalNoteRevisionResource::collection(
            $this->service->listRevisions($request->user(), $note, $request->integer('per_page', 15))
        ), 'Internal note revisions retrieved successfully.');
    }
}
