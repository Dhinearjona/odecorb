<?php

use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use App\Http\Controllers\RegisterController;


Route::get('/', function () {
    return view('welcome');
});

Route::get('/register', [RegisterController::class, 'registrationPage']);
Route::post('/register', [RegisterController::class, 'postRegister']);


//artisan helper - SECURITY: Only allow specific safe commands
Route::get('/artisan', function () {
    $param = request()->param;
    
    // List of allowed commands (whitelist approach for security)
    $allowedCommands = [
        'config:cache',
        'config:clear',
        'route:cache',
        'route:clear',
        'view:cache',
        'view:clear',
        'cache:clear',
        'optimize:clear',
    ];
    
    // Prevent dangerous commands
    $dangerousCommands = ['octane', 'migrate', 'db:', 'tinker', 'queue:', 'schedule:'];
    
    if (!$param) {
        return response()->json(['error' => 'No command specified'], 400);
    }
    
    // Check if command contains dangerous patterns
    foreach ($dangerousCommands as $dangerous) {
        if (strpos($param, $dangerous) !== false) {
            return response()->json(['error' => 'Command not allowed'], 403);
        }
    }
    
    // Only allow whitelisted commands
    if (!in_array($param, $allowedCommands)) {
        return response()->json(['error' => 'Command not in whitelist'], 403);
    }
    
    try {
        $result = Artisan::call($param);
        return response()->json(['success' => true, 'output' => Artisan::output()]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
});

Route::get('/create-transaction', [PaymentController::class, 'createTransaction'])->name('createTransaction');
Route::get('/process-transaction', [PaymentController::class, 'processTransaction'])->name('processTransaction');
Route::get('/success-transaction', [PaymentController::class, 'successTransaction'])->name('successTransaction');
Route::get('/cancel-transaction', [PaymentController::class, 'cancelTransaction'])->name('cancelTransaction');
