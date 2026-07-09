<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->file(base_path('index.html'));
});

Route::get('/index.html', function () {
    return response()->file(base_path('index.html'));
});

Route::get('/{directory}/{path}', function (string $directory, string $path) {
    $root = realpath(base_path($directory));
    $file = realpath(base_path($directory . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path)));

    if (!$root || !$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !File::isFile($file)) {
        abort(404);
    }

    $contentTypes = [
        'css' => 'text/css; charset=UTF-8',
        'html' => 'text/html; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
    ];
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    return response()->file($file, [
        'Cache-Control' => $directory === 'pages' ? 'no-cache' : 'public, max-age=3600',
        'Content-Type' => $contentTypes[$extension] ?? File::mimeType($file),
    ]);
})->where('directory', 'assets|css|pages|scripts')->where('path', '.*');
