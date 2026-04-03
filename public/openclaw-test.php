<?php
$token = getenv('OPENCLAW_API_TOKEN');
header('Content-Type: application/json');
echo json_encode([
    'token_exists' => !empty($token),
    'token_length' => strlen($token ?? ''),
    'token_preview' => substr($token ?? '', 0, 10) . '...' . substr($token ?? '', -10),
]);
