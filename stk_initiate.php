
<?php

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    exit;
}

// Read form information
$amount = $_POST['amount'] ?? '';
$phone = $_POST['phone'] ?? ($_POST['msisdn'] ?? '');

// Check required information
if (!is_numeric($amount) || (float)$amount <= 0 ||
    (float)$amount != (int)$amount) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Enter a valid whole-number amount.'
    ]);
    exit;
}

// Convert phone number to 254 format
$phone = preg_replace('/[\s\-+]/', '', trim($phone));

if (preg_match('/^(07|01)[0-9]{8}$/', $phone)) {
    $phone = '254' . substr($phone, 1);
} elseif (preg_match('/^254(7|1)[0-9]{8}$/', $phone)) {
    // Already in international format
} else {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Enter a valid Kenyan M-Pesa phone number.'
    ]);
    exit;
}

// Check API credentials
if (!MEGAPAY_API_KEY || !MEGAPAY_EMAIL) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Payment configuration is missing.'
    ]);
    exit;
}

// Generate a unique payment reference
$reference = 'ORDER_' . strtoupper(bin2hex(random_bytes(5)));

// Prepare MegaPay request
$requestData = [
    'api_key' => MEGAPAY_API_KEY,
    'email' => MEGAPAY_EMAIL,
    'amount' => (int)$amount,
    'msisdn' => $phone,
    'reference' => $reference
];

// Send request to MegaPay
$ch = curl_init(MEGAPAY_API_URL . '/initiatestk');

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($requestData),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json'
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

curl_close($ch);

// Handle connection errors
if ($response === false) {
    error_log('MegaPay connection error: ' . $curlError);

    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'Could not connect to the payment service.'
    ]);
    exit;
}

// Decode MegaPay response
$result = json_decode($response, true);

if (!is_array($result)) {
    error_log('Invalid MegaPay response: ' . $response);

    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid response from payment service.'
    ]);
    exit;
}

// Check whether MegaPay accepted the request
if ($httpCode >= 200 && $httpCode < 300 &&
    isset($result['success']) &&
    (string)$result['success'] === '200' &&
    !empty($result['transaction_request_id'])) {

    echo json_encode([
        'success' => true,
        'message' => 'STK Push request sent.',
        'reference' => $reference,
        'transaction_request_id' => $result['transaction_request_id']
    ]);
    exit;
}

// Payment request was not accepted
error_log('MegaPay initiation response: ' . $response);

http_response_code(400);
echo json_encode([
    'success' => false,
    'message' => $result['message'] ??
                 $result['massage'] ??
                 'MegaPay could not initiate the payment.'
]);

?>
