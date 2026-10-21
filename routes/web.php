<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

require __DIR__.'/hrms.php';
require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
