<?php

header('Content-Type: application/json');
header('Cache-Control: no-store');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($path !== '/api/v1/webhooks/paypal/sandbox' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(404);
    echo '{"message":"Not found."}';
    exit;
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 262144) {
    http_response_code(413);
    echo '{"message":"Payload too large."}';
    exit;
}
$body = file_get_contents('php://input', false, null, 0, 262145);
if (strlen($body) > 262144) {
    http_response_code(413);
    echo '{"message":"Payload too large."}';
    exit;
}
$headers = ['Content-Type: application/json', 'Accept: application/json'];
foreach (['PAYPAL-TRANSMISSION-ID', 'PAYPAL-TRANSMISSION-TIME', 'PAYPAL-CERT-URL', 'PAYPAL-AUTH-ALGO', 'PAYPAL-TRANSMISSION-SIG'] as $name) {
    $value = $_SERVER['HTTP_'.str_replace('-', '_', $name)] ?? '';
    if ($value === '' || strlen($value) > 8192 || str_contains($value, "\r") || str_contains($value, "\n")) {
        http_response_code(400);
        echo '{"message":"Missing or invalid PayPal header."}';
        exit;
    }
    $headers[] = $name.': '.$value;
}
$curl = curl_init('http://127.0.0.1:8000/api/v1/webhooks/paypal/sandbox');
curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 40]);
$response = curl_exec($curl);
$status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
curl_close($curl);
if ($response !== false && $status >= 200 && $status < 300) {
    http_response_code(200);
    echo '{"received":true}';
    exit;
}
http_response_code($status >= 400 && $status < 500 ? $status : 502);
echo '{"message":"Webhook was not accepted."}';
