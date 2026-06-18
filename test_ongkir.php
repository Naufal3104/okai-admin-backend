<?php

use Illuminate\Http\Request;
use App\Http\Controllers\Api\OrderController;
use Illuminate\Support\Facades\Http;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$controller = app(OrderController::class);

$warehouse = \App\Models\Warehouses::where('name', 'like', '%Surabaya%')->first();
$user = \App\Models\User::where('name', 'like', '%Budi Santoso%')->first();

if (!$warehouse || !$user) {
    echo "Warehouse or user not found.\n";
    exit;
}

echo "Gudang: " . $warehouse->city . "\n";
$addressParts = array_map('trim', explode(',', $user->address));
$userCity = $addressParts[count($addressParts) - 3] ?? '';
$userPostalCode = $addressParts[count($addressParts) - 1] ?? '';
echo "User City: " . $userCity . "\n";

$req = Request::create('/api/shipping/rate', 'POST', [
    'city' => $userCity,
    'postal_code' => $userPostalCode,
    'province' => 'Jawa Timur',
    'items' => [
        ['product_id' => 1, 'qty' => 1]
    ]
]);

$response = $controller->getShippingRate($req);
echo "Response JSON:\n";
echo json_encode($response->getData(), JSON_PRETTY_PRINT) . "\n";
