<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AccessController;
Route::get('/', [AccessController::class, 'index'])->name('home');
Route::get('/table/{table}', [AccessController::class, 'table'])->name('table');
Route::post('/export', [AccessController::class, 'export'])->name('export');
Route::get('/export-progress', [AccessController::class, 'exportProgress'])->name('export.progress');
Route::get('/download/{file}', [AccessController::class, 'download'])->where('file', '.*')->name('download');
