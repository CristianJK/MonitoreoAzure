<?php

use App\Http\Controllers\Api\AgentCommentController;
use App\Http\Controllers\Api\AzureAgentController;
use App\Http\Controllers\Api\AzureRepositoryController;
use App\Http\Controllers\Api\AzureWorkItemController;
use App\Http\Controllers\Api\ChecklistDefinitionController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WorkItemChecklistController;
use App\Http\Controllers\Api\WorkItemTypeController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// Única ruta pública: iniciar sesión con el PAT personal
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Toda la API requiere sesión. Lectura: cualquier work item. Escritura: solo
// los asignados al usuario (work-item.owner) o, en el catálogo, administradores.
Route::middleware(['auth:sanctum', 'azure.pat'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::patch('/me', [AuthController::class, 'updateMe']);
    Route::get('/users', [UserController::class, 'index']);

    // Work items
    Route::get('/work-items', [AzureWorkItemController::class, 'index']);
    Route::get('/work-items/checklist-summary', [WorkItemChecklistController::class, 'summaries']);
    Route::get('/work-items/{id}', [AzureWorkItemController::class, 'show'])->whereNumber('id');
    Route::get('/work-items/{id}/checklist', [WorkItemChecklistController::class, 'show'])->whereNumber('id');

    Route::middleware(['throttle:writes', 'audit', 'work-item.owner'])->group(function () {
        Route::post('/work-items/{id}/comments', [AzureWorkItemController::class, 'addComment'])->whereNumber('id');
        Route::patch('/work-items/{id}/state', [AzureWorkItemController::class, 'updateState'])->whereNumber('id');
        Route::post('/work-items/{id}/link-branch', [AzureWorkItemController::class, 'linkBranch'])->whereNumber('id');
        Route::post('/work-items/{id}/link-pr', [AzureWorkItemController::class, 'linkPullRequest'])->whereNumber('id');
        Route::put('/work-items/{id}/checklist/{definition}', [WorkItemChecklistController::class, 'update'])->whereNumber('id');
        Route::post('/work-items/{id}/agent/daily-comment', [AgentCommentController::class, 'propose'])->whereNumber('id');
    });

    Route::get('/work-item-types', [WorkItemTypeController::class, 'index']);
    Route::get('/work-item-types/{type}/states', [WorkItemTypeController::class, 'states']);

    // Alistamiento: catálogo (lectura para todos, escritura para administradores)
    Route::get('/checklist-definitions/data-types', [ChecklistDefinitionController::class, 'dataTypes']);
    Route::apiResource('checklist-definitions', ChecklistDefinitionController::class)->only(['index', 'show']);

    Route::middleware(['can:manage-checklist', 'throttle:writes', 'audit'])->group(function () {
        Route::patch('/checklist-definitions/reorder', [ChecklistDefinitionController::class, 'reorder']);
        Route::apiResource('checklist-definitions', ChecklistDefinitionController::class)->only(['store', 'update', 'destroy']);
    });

    // Agente de comentario diario (cada usuario ve y resuelve solo lo suyo)
    Route::post('/agent/daily-comment/batch', [AgentCommentController::class, 'batch'])->middleware(['throttle:agent-batch', 'audit']);
    Route::get('/agent-runs', [AgentCommentController::class, 'runs']);
    Route::get('/agent-approvals', [AgentCommentController::class, 'index']);
    Route::get('/agent-approvals/pending-count', [AgentCommentController::class, 'pendingCount']);

    Route::middleware(['throttle:writes', 'audit'])->group(function () {
        Route::post('/agent-approvals/{approval}/approve', [AgentCommentController::class, 'approve']);
        Route::post('/agent-approvals/{approval}/reject', [AgentCommentController::class, 'reject']);
        Route::post('/agent-approvals/{approval}/regenerate', [AgentCommentController::class, 'regenerate']);
    });

    Route::get('/agents', [AzureAgentController::class, 'index']);

    Route::get('/repositories', [AzureRepositoryController::class, 'index']);
    Route::get('/repositories/{repositoryId}/branches', [AzureRepositoryController::class, 'branches']);
    Route::get('/repositories/{repositoryId}/pull-requests', [AzureRepositoryController::class, 'pullRequests']);
});
