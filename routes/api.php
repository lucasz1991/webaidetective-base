<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PagebuilderProjectController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Artisan;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/




Route::post('/pagebuilder/upload', [PagebuilderProjectController::class, 'uploadImage']);
Route::get('/pagebuilder/assets', [PagebuilderProjectController::class, 'getAssets']);

Route::post('/trigger-build', function () {
    Artisan::call('frontend:build');
    return response()->json([
        'message' => 'Frontend-Build wurde gestartet!',
        'output' => Artisan::output()
    ]);
})->middleware('auth:sanctum');
