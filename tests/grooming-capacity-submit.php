<?php

use App\Http\Controllers\BookingController;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! str_starts_with(DB::connection()->getDatabaseName(), 'bethlehem_capacity_')) throw new RuntimeException('An isolated capacity database is required.');
Carbon::setTestNow('2026-10-16 13:00:00');
$user = User::findOrFail((int) $argv[1]);
$request = Request::create('/api/booking/store', 'POST', json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR));
$request->setUserResolver(fn () => $user);
// Keep the first submission inside its acquired shared lock while the other starts.
if (isset($argv[3])) {
    DB::listen(function ($query) use ($argv) {
        if (str_contains($query->sql, 'GET_LOCK')) {
            file_put_contents($argv[3], 'locked');
            usleep(1500000);
        }
    });
}
$started = microtime(true);
try {
    $response = $app->call([app(BookingController::class), 'store'], ['request' => $request]);
} catch (ValidationException $exception) {
    $response = $exception->response ?? response()->json(['errors' => $exception->errors()], 422);
}
echo json_encode(['status' => $response->getStatusCode(), 'body' => $response->getData(true),
    'started_at' => $started, 'finished_at' => microtime(true)], JSON_THROW_ON_ERROR);
