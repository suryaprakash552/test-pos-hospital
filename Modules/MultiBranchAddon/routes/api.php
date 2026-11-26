<?php

use Illuminate\Support\Facades\Route;
use Modules\MultiBranchAddon\App\Http\Controllers\Api as Multibranch;

/*
    |--------------------------------------------------------------------------
    | API Routes
    |--------------------------------------------------------------------------
    |
    | Here is where you can register API routes for your application. These
    | routes are loaded by the RouteServiceProvider within a group which
    | is assigned the "api" middleware group. Enjoy building your API!
    |
*/

Route::middleware(['auth:sanctum'])->prefix('v1')->name('api.')->group(function () {
    Route::apiResource('branches', Multibranch\AcnooBranchController::class)->except('show');
    // Named explicitly: unnamed, they each inherited the group's `api.`
    // prefix and nothing else, so both were named `api.` and collided.
    Route::get('/switch-branch/{id}', [Multibranch\AcnooBranchController::class, 'switchBranch'])->name('switch-branch');
    Route::get('/exit-branch/{id}', [Multibranch\AcnooBranchController::class, 'exitBranch'])->name('exit-branch');
});
