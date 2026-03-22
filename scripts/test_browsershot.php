<?php

require __DIR__.'/vendor/autoload.php';

use Spatie\Browsershot\Browsershot;

echo "Testing Browsershot with Node binary: " . env('NODE_BINARY_PATH', '/usr/bin/node') . "\n";

try {
    $html = Browsershot::url('https://google.com')
        ->setNodeBinary('/usr/bin/node') // Point to your node binary
        ->noSandbox()
        ->disableSetuidSandbox()
        ->disableDevShmUsage()
        ->bodyHtml();
    
    echo "SUCCESS: Browsershot fetched the content! (" . strlen($html) . " bytes)\n";
} catch (\Exception $e) {
    echo "FAILED: Browsershot error:\n";
    echo $e->getMessage() . "\n";
}
