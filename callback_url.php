<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'mpesa_callback.log');

header("Content-Type: application/json");

function logMpesa($message, $type = 'INFO') {
    $logEntry = date('[Y-m-d H:i:s]') . " [$type] " . $message . "\n";
    error_log($logEntry, 3, 'mpesa_callback.log');
}

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'ecommerce';

try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }
    
    $rawInput = file_get_contents('php://input');
    logMpesa("Raw callback data: " . $rawInput);
    
    $callbackData = json_decode($rawInput);
    
    if (!$callbackData || !isset($callbackData->Body->stkCallback)) {
        throw new Exception("Invalid callback data structure");
    }
    
    $stkCallback = $callbackData->Body->stkCallback;
    $resultCode = $stkCallback->ResultCode;
    $resultDesc = $stkCallback->ResultDesc;
    $merchantRequestID = $stkCallback->MerchantRequestID;
    $checkoutRequestID = $stkCallback->CheckoutRequestID;
    
    logMpesa("Processing callback - CheckoutRequestID: $checkoutRequestID, ResultCode: $resultCode");
    
    $status = 'FAILED';
    $mpesaReceiptNumber = null;
    
    if ($resultCode == 0) {
        $status = 'COMPLETED';
        
        if (isset($stkCallback->CallbackMetadata->Item)) {
            foreach ($stkCallback->CallbackMetadata->Item as $item) {
                if ($item->Name == "MpesaReceiptNumber") {
                    $mpesaReceiptNumber = $item->Value;
                    break;
                }
            }
            logMpesa("Transaction successful - Receipt Number: $mpesaReceiptNumber");
        } else {
            logMpesa("Warning: CallbackMetadata->Item not found in successful transaction", "WARN");
        }
    } else {
        $errorMessages = [
            1001 => "Customer cancelled the transaction",
            1002 => "System timed out",
            1032 => "Transaction failed",
            2001 => "Wrong PIN entered"
        ];
        
        $errorMessage = $errorMessages[$resultCode] ?? "Unknown error occurred";
        logMpesa("Transaction failed - Code: $resultCode, Message: $errorMessage", "ERROR");
    }
    
  
    $query = "UPDATE mpesa_payments 
              SET status = ?,
                  mpesa_receipt = ?,
                  result_code = ?,
                  result_description = ?,
                  updated_at = CURRENT_TIMESTAMP
              WHERE checkout_request_id = ?";
              
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Failed to prepare update query: " . $conn->error);
    }
    
    $stmt->bind_param("sssss", 
        $status, 
        $mpesaReceiptNumber, 
        $resultCode, 
        $resultDesc, 
        $checkoutRequestID
    );
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to update payment record: " . $stmt->error);
    }
    
    $affectedRows = $stmt->affected_rows;
    logMpesa("Database update completed - Rows affected: $affectedRows");
    
    if ($affectedRows === 0) {
        logMpesa("Warning: No payment record found for CheckoutRequestID: $checkoutRequestID", "WARN");
    }
    
    $response = [
        "ResultCode" => 0,
        "ResultDesc" => "Success",
        "ThirdPartyTransID" => time()
    ];
    
    logMpesa("Sending success response: " . json_encode($response));
    echo json_encode($response);
    
} catch (Exception $e) {
    logMpesa("Critical error: " . $e->getMessage(), "ERROR");
    http_response_code(500);
    
    $errorResponse = [
        "ResultCode" => 1,
        "ResultDesc" => "Error processing callback",
        "ErrorMessage" => $e->getMessage()
    ];
    
    echo json_encode($errorResponse);
} finally {
    if (isset($stmt)) {
        $stmt->close();
    }
    if (isset($conn)) {
        $conn->close();
    }
}
?>
