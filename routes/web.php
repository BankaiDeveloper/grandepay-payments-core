<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use Hypervel\Support\Facades\Route;

Route::get('/', [HealthController::class, 'index']);
Route::get('/up', [HealthController::class, 'up']);
