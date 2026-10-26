<?php

use App\Http\Controllers\Api\AgentCommentController;
use App\Http\Controllers\Api\AzureAgentController;
use App\Http\Controllers\Api\AzureRepositoryController;
use App\Http\Controllers\Api\AzureWorkItemController;
use App\Http\Controllers\Api\ChecklistDefinitionController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WorkItemChecklistController;
use App\Http\Controllers\Api\WorkItemTypeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/me', [UserController::class, 'me']);
Route::get('/users', [UserController::class, 'index']);

// Work items
Route::get('/work-items', [AzureWorkItemController::class, 'index']);
Route::get('/work-items/checklist-summary', [WorkItemChecklistController::class, 'summaries']);
Route::get('/work-items/{id}', [AzureWorkItemController::class, 'show'])->whereNumber('id');
Route::post('/work-items/{id}/comments', [AzureWorkItemController::class, 'addComment'])->whereNumber('id');
Route::patch('/work-items/{id}/state', [AzureWorkItemController::class, 'updateState'])->whereNumber('id');
Route::post('/work-items/{id}/link-branch', [AzureWorkItemController::class, 'linkBranch']);
Route::post('/work-items/{id}/link-pr', [AzureWorkItemController::class, 'linkPullRequest']);

Route::get('/work-item-types', [WorkItemTypeController::class, 'index']);
Route::get('/work-item-types/{type}/states', [WorkItemTypeController::class, 'states']);

// Alistamiento
Route::get('/checklist-definitions/data-types', [ChecklistDefinitionController::class, 'dataTypes']);
Route::patch('/checklist-definitions/reorder', [ChecklistDefinitionController::class, 'reorder']);
Route::apiResource('checklist-definitions', ChecklistDefinitionController::class);

Route::get('/work-items/{id}/checklist', [WorkItemChecklistController::class, 'show'])->whereNumber('id');
Route::put('/work-items/{id}/checklist/{definition}', [WorkItemChecklistController::class, 'update'])->whereNumber('id');

// Agente de comentario diario
Route::post('/work-items/{id}/agent/daily-comment', [AgentCommentController::class, 'propose'])->whereNumber('id');
Route::post('/agent/daily-comment/batch', [AgentCommentController::class, 'batch']);
Route::get('/agent-runs', [AgentCommentController::class, 'runs']);
Route::get('/agent-approvals', [AgentCommentController::class, 'index']);
Route::get('/agent-approvals/pending-count', [AgentCommentController::class, 'pendingCount']);
Route::post('/agent-approvals/{approval}/approve', [AgentCommentController::class, 'approve']);
Route::post('/agent-approvals/{approval}/reject', [AgentCommentController::class, 'reject']);
Route::post('/agent-approvals/{approval}/regenerate', [AgentCommentController::class, 'regenerate']);

Route::get('/agents', [AzureAgentController::class, 'index']);

Route::get('/repositories', [AzureRepositoryController::class, 'index']);
Route::get('/repositories/{repositoryId}/branches', [AzureRepositoryController::class, 'branches']);
Route::get('/repositories/{repositoryId}/pull-requests', [AzureRepositoryController::class, 'pullRequests']);
