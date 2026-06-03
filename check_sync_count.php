<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Apartment;

$open = Apartment::where('status', 'open')->where('verification_status', 'approved')->get();
$withSpace = 0;
foreach ($open as $apt) {
    $occ = (int) $apt->male_count + (int) $apt->female_count;
    if ($occ < (int) $apt->capacity || (int) $apt->capacity <= 0) {
        $withSpace++;
    }
}
echo "open+approved: {$open->count()}, with free spots: $withSpace\n";
