<?php

use App\Http\Controllers\Panel\DecisionController;
use App\Http\Controllers\Panel\LoginController;
use App\Http\Controllers\Panel\StatsController;
use App\Http\Controllers\Panel\TicketController;
use Illuminate\Support\Facades\Route;

// Панель операторов: docs/design.md §5.
Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'login'])->name('login.attempt');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', fn () => redirect()->route('tickets.index'));

    Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::post('/tickets/{ticket}/reply', [TicketController::class, 'reply'])->name('tickets.reply');
    Route::post('/tickets/{ticket}/close', [TicketController::class, 'close'])->name('tickets.close');

    Route::get('/decisions', [DecisionController::class, 'index'])->name('decisions.index');
    Route::get('/stats', StatsController::class)->name('stats');
});
