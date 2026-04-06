<?php
header('Content-Type: text/plain');

$files = [
    'push_debug.log' => __DIR__ . '/push_debug.log',
    'bg_trace.log' => __DIR__ . '/../api/bg_trace.log',
    'register_push_trace.log' => __DIR__ . '/../api/visitor/register_push_trace.log'
];

foreach ($files as $name => $path) {
    echo "=== $name ===\r\n";
    if (file_exists($path)) {
        // Get last 50 lines
        $lines = explode("\n", file_get_contents($path));
        echo implode("\n", array_slice($lines, -50));
    } else {
        echo "[File not found]\r\n";
    }
    echo "\r\n\r\n";
}
?>
