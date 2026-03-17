<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Scannr\ScannrServiceProvider;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        ScannrServiceProvider::class,
    ])
    ->withCommands([])
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
