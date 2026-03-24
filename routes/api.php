<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\SystemController;
use App\PaymentsCore\Http\Controllers\WebhookController;
use App\PaymentsCore\Http\Controllers\WithdrawalController;
use Hypervel\Support\Facades\Route;

Route::get('/'.env('PAYMENTS_API_VERSION', 'v1').'/system/info', [SystemController::class, 'show']);

// Webhook routes (public - no auth)
Route::post('/webhooks/{provider}', [WebhookController::class, 'handle']);

// Withdrawal routes
Route::post('/withdrawals', [WithdrawalController::class, 'store']);
Route::get('/withdrawals/{uuid}', [WithdrawalController::class, 'show']);
Route::post('/withdrawals/{uuid}/approve', [WithdrawalController::class, 'approve']);
Route::post('/withdrawals/{uuid}/reject', [WithdrawalController::class, 'reject']);

use App\PaymentsCore\Http\Controllers\ChargebackController;

// Chargeback routes
Route::post('/chargebacks/transaction/{transactionUuid}', [ChargebackController::class, 'store']);
Route::get('/chargebacks/{id}', [ChargebackController::class, 'show']);
Route::post('/chargebacks/{id}/approve', [ChargebackController::class, 'approve']);
Route::post('/chargebacks/{id}/reject', [ChargebackController::class, 'reject']);
Route::post('/chargebacks/{id}/replay', [ChargebackController::class, 'replay']);
