<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AzureWorkItemController;
use App\Http\Controllers\Api\AzureAgentController;
use App\Http\Controllers\Api\AzureRepositoryController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/work-items', [AzureWorkItemController::class, 'index']);
Route::post('/work-items/{id}/link-branch', [AzureWorkItemController::class, 'linkBranch']);
Route::post('/work-items/{id}/link-pr', [AzureWorkItemController::class, 'linkPullRequest']);

Route::get('/agents', [AzureAgentController::class, 'index']);

Route::get('/repositories', [AzureRepositoryController::class, 'index']);
Route::get('/repositories/{repositoryId}/branches', [AzureRepositoryController::class, 'branches']);
Route::get('/repositories/{repositoryId}/pull-requests', [AzureRepositoryController::class, 'pullRequests']);