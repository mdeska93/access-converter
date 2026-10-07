<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AccessController;

Route::get('/', [AccessController::class, 'index'])->name('home');
Route::get('/table/{table}', [AccessController::class, 'table'])->name('table');
Route::post('/export', [AccessController::class, 'export'])->name('export');
Route::get('/export-progress', [AccessController::class, 'exportProgress'])->name('export.progress');
Route::get('/download/{file}', [AccessController::class, 'download'])->where('file', '.*')->name('download');

// Database Management Routes
Route::post('/database/select', [AccessController::class, 'selectDatabase'])->name('database.select');
Route::post('/database/upload', [AccessController::class, 'uploadDatabase'])->name('database.upload');
Route::post('/database/scan', [AccessController::class, 'scanFolder'])->name('database.scan');
Route::post('/database/reset', [AccessController::class, 'resetDatabase'])->name('database.reset');
Route::post('/database/remove-recent', [AccessController::class, 'removeRecentDatabase'])->name('database.remove_recent');
