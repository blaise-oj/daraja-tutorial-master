
<?php

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

// Read the incoming MegaPay webhook
$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true);

// Reject invalid requests
if (!is_array($data) || !isset($data['ResponseCode'])) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid webhook payload'
    ]);
    exit;
}

// Record the notification in Heroku application logs
error_log('MegaPay webhook received: ' . $rawData);

// Handle failed or cancelled payments
if ((string)$data['ResponseCode'] !== '0') {

    error_log(
        'MegaPay payment failed: ' .
        ($data['ResponseDescription'] ?? 'Unknown reason')
    );

    http_response_code(200);
    echo json_encode([
        'status' => 'received',
        'message' => 'Failed payment notification recorded'
    ]);
    exit;
}

// Successful callback must contain a transaction ID
if (empty($data['TransactionID'])) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing transaction ID'
    ]);
    exit;
}

// Verify the transaction with MegaPay
$verificationData = [
    'api_key' => MEGAPAY_API_KEY,
    'email' => MEGAPAY_EMAIL,
    'transaction_request_id' => $data['TransactionID']
];

$ch = curl_init(
    MEGAPAY_API_URL . '/transactionstatus'
);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($verificationData),
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

// If verification service cannot be reached, allow a retry
if ($response === false || $httpCode < 200 || $httpCode >= 300) {

    error_log('MegaPay verification unavailable: ' . $curlError);

    http_response_code(503);
    echo json_encode([
        'status' => 'pending',
        'message' => 'Payment verification temporarily unavailable'
    ]);
    exit;
}

$verified = json_decode($response, true);

// Confirm actual completed status
if (
    !is_array($verified) ||
    (string)($verified['ResultCode'] ?? '') !== '200' ||
    ($verified['TransactionStatus'] ?? '') !== 'Completed' ||
    (string)($verified['TransactionCode'] ?? '') !== '0' ||
    empty($verified['TransactionReceipt'])
) {
    error_log('MegaPay transaction not confirmed: ' . $response);

    http_response_code(200);
    echo json_encode([
        'status' => 'unconfirmed',
        'message' => 'Payment has not been confirmed'
    ]);
    exit;
}

// Compare callback details with verified transaction details
if (
    isset($data['TransactionAmount'], $verified['TransactionAmount']) &&
    (float)$data['TransactionAmount'] !==
    (float)$verified['TransactionAmount']
) {
    error_log('MegaPay amount mismatch');

    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Transaction amount mismatch'
    ]);
    exit;
}

// Payment has been verified
error_log(
    'VERIFIED MEGAPAY PAYMENT: Receipt=' .
    $verified['TransactionReceipt'] .
    ', Amount=' . $verified['TransactionAmount']
);

http_response_code(200);

echo json_encode([
    'status' => 'success',
    'message' => 'Payment verified',
    'receipt' => $verified['TransactionReceipt'],
    'amount' => $verified['TransactionAmount']
]);

?>
