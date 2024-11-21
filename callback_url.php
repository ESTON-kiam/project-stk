<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'mpesa_error.log');

header('Content-Type: application/json');

function logMessage($message, $type = 'INFO') {
    try {
        $logFile = 'mpesa_callback.log';
        $timestamp = date('[Y-m-d H:i:s]');
        $logEntry = "$timestamp [$type] $message\n";
        
        if (!file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX)) {
            error_log("Failed to write to log file: $logFile");
        }
    } catch (Exception $e) {
        error_log("Logging failed: " . $e->getMessage());
    }
}

class DatabaseHandler {
    private $conn;
    
    public function __construct($config) {
        $this->connect($config);
    }
    
    private function connect($config) {
        try {
            $this->conn = new mysqli(
                $config['host'],
                $config['user'],
                $config['pass'],
                $config['name']
            );
            
            if ($this->conn->connect_error) {
                throw new Exception("Database connection failed: " . $this->conn->connect_error);
            }
            
            $this->conn->set_charset('utf8mb4');
            logMessage("Database connection established successfully");
        } catch (Exception $e) {
            logMessage("Database connection error: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }
    
    public function updatePaymentStatus($callbackData) {
        try {
          
            $checkoutRequestId = $callbackData['CheckoutRequestID'] ?? null;
            $resultCode = $callbackData['ResultCode'] ?? null;
            $resultDesc = $callbackData['ResultDesc'] ?? 'No description';
            
           
            $status = match((string)$resultCode) {
                '0' => 'COMPLETED',
                '1' => 'FAILED',
                '1032' => 'CANCELLED',
                '1037' => 'TIMEOUT',
                default => 'UNKNOWN'
            };
            
           
            $mpesaReceiptNumber = null;
            $phoneNumber = null;
            $amount = null;
            $transactionDate = null;
            
            
            if ($status === 'COMPLETED' && isset($callbackData['CallbackMetadata']['Item'])) {
                foreach ($callbackData['CallbackMetadata']['Item'] as $item) {
                    switch ($item['Name']) {
                        case 'MpesaReceiptNumber':
                            $mpesaReceiptNumber = $item['Value'] ?? null;
                            break;
                        case 'PhoneNumber':
                            $phoneNumber = $item['Value'] ?? null;
                            break;
                        case 'Amount':
                            $amount = $item['Value'] ?? null;
                            break;
                        case 'TransactionDate':
                            $transactionDate = $item['Value'] ?? null;
                            break;
                    }
                }
            }
            
           
            logMessage("Payment Data - Status: $status, ReceiptNumber: $mpesaReceiptNumber, PhoneNumber: $phoneNumber, Amount: $amount", 'DEBUG');
            
           
            $query = "INSERT INTO mpesa_payments (
                        checkout_request_id, 
                        status, 
                        result_code, 
                        result_desc, 
                        mpesa_receipt, 
                        phone_number, 
                        amount, 
                        transaction_date,
                        created_at, 
                        updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE 
                        status = VALUES(status),
                        result_code = VALUES(result_code),
                        result_desc = VALUES(result_desc),
                        mpesa_receipt = VALUES(mpesa_receipt),
                        phone_number = VALUES(phone_number),
                        amount = VALUES(amount),
                        transaction_date = VALUES(transaction_date),
                        updated_at = NOW()";
            
            $stmt = $this->conn->prepare($query);
            if (!$stmt) {
                throw new Exception("Failed to prepare statement: " . $this->conn->error);
            }
            
            $stmt->bind_param(
                "ssssssss", 
                $checkoutRequestId, 
                $status, 
                $resultCode, 
                $resultDesc, 
                $mpesaReceiptNumber, 
                $phoneNumber, 
                $amount, 
                $transactionDate
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to insert/update payment status: " . $stmt->error);
            }
            
            
            $affectedRows = $stmt->affected_rows;
            logMessage("Database operation - Affected Rows: $affectedRows, CheckoutRequestID: $checkoutRequestId", 'INFO');
           
            if ($status === 'COMPLETED') {
                $this->updateOrderStatus($checkoutRequestId);
            }
            
            return true;
            
        } catch (Exception $e) {
            logMessage("Payment status update error: " . $e->getMessage(), 'ERROR');
            throw $e;
        } finally {
            if (isset($stmt)) {
                $stmt->close();
            }
        }
    }
    
    private function updateOrderStatus($checkoutRequestId) {
        try {
           
            $query = "SELECT order_id FROM mpesa_payments WHERE checkout_request_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("s", $checkoutRequestId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            if (!$row || !isset($row['order_id'])) {
                throw new Exception("No order found for checkout request: " . $checkoutRequestId);
            }
            
           
            $updateQuery = "UPDATE orders SET 
                            payment_status = 'PAID',
                            status = 'PROCESSING',
                            updated_at = NOW()
                            WHERE id = ?";
            
            $stmt = $this->conn->prepare($updateQuery);
            $stmt->bind_param("i", $row['order_id']);
            $stmt->execute();
            
            logMessage("Updated order status for order ID: " . $row['order_id']);
        } catch (Exception $e) {
            logMessage("Order status update error: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }
    
    public function __destruct() {
        if (isset($this->conn)) {
            $this->conn->close();
        }
    }
}

try {
   
    logMessage("M-Pesa Callback Request Received", 'INFO');
    
   
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) {
        throw new Exception('No input data received');
    }
    
   
    logMessage("Raw Callback Input: " . $rawInput, 'DEBUG');
    
   
    $callbackData = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON data: ' . json_last_error_msg());
    }
    
 
    if (!isset($callbackData['Body']['stkCallback'])) {
        throw new Exception('Invalid callback structure: stkCallback missing');
    }
    
    
    $stkCallback = $callbackData['Body']['stkCallback'];
    
   
    $requiredFields = ['MerchantRequestID', 'CheckoutRequestID', 'ResultCode', 'ResultDesc'];
    foreach ($requiredFields as $field) {
        if (!isset($stkCallback[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }
    
    
    logMessage("Callback Details - CheckoutRequestID: " . $stkCallback['CheckoutRequestID'] 
        . ", ResultCode: " . $stkCallback['ResultCode']
        . ", ResultDesc: " . $stkCallback['ResultDesc'], 
    'INFO');
    
    
    $db = new DatabaseHandler([
        'host' => 'localhost',
        'user' => 'root',
        'pass' => '',
        'name' => 'ecommerce'
    ]);
    
   
    $updated = $db->updatePaymentStatus($stkCallback);
    
    
    $responseData = [
        'success' => true,
        'message' => 'Callback processed successfully',
        'data' => [
            'checkoutRequestId' => $stkCallback['CheckoutRequestID'],
            'resultCode' => $stkCallback['ResultCode'],
            'resultDesc' => $stkCallback['ResultDesc'],
            'databaseUpdated' => $updated
        ]
    ];
    

    echo json_encode($responseData);
    
} catch (Exception $e) {
 
    logMessage("Callback Processing Error: " . $e->getMessage(), 'ERROR');
    
    $errorResponse = [
        'success' => false,
        'message' => 'Error processing M-Pesa callback',
        'error' => $e->getMessage()
    ];
    
    http_response_code(500);
    echo json_encode($errorResponse);
}
?>