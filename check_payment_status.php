<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'mpesa_error.log');

header('Content-Type: application/json');

function logMessage($message, $type = 'INFO') {
    try {
        $logFile = 'mpesa_requests.log';
        $timestamp = date('[Y-m-d H:i:s]');
        $logEntry = "$timestamp [$type] $message\n";
        
        if (!file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX)) {
            error_log("Failed to write to log file: $logFile");
        }
    } catch (Exception $e) {
        error_log("Logging failed: " . $e->getMessage());
    }
}

class MpesaExpressQuery {
    private $consumerKey;
    private $consumerSecret;
    private $environment;
    private $accessToken;
    private $businessShortCode = '174379';
    private $passkey = "bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919";
    
    public function __construct($consumerKey, $consumerSecret, $environment = 'sandbox') {
        try {
            $this->consumerKey = $consumerKey;
            $this->consumerSecret = $consumerSecret;
            $this->environment = $environment;
            date_default_timezone_set('Africa/Nairobi');
            $this->generateAccessToken();
        } catch (Exception $e) {
            logMessage("MpesaExpressQuery initialization failed: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }
    
    private function generateAccessToken() {
        try {
            $credentials = base64_encode($this->consumerKey . ':' . $this->consumerSecret);
            $url = $this->environment === 'sandbox' 
                ? 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials'
                : 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
                
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $credentials],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HEADER => false
            ]);
            
            $response = curl_exec($curl);
            
            if ($response === false) {
                throw new Exception('Failed to generate access token: ' . curl_error($curl));
            }
            
            $result = json_decode($response);
            if (!isset($result->access_token)) {
                throw new Exception('Invalid access token response');
            }
            
            $this->accessToken = $result->access_token;
            logMessage("Access token generated successfully");
            
        } catch (Exception $e) {
            logMessage("Access token generation failed: " . $e->getMessage(), 'ERROR');
            throw $e;
        } finally {
            if (isset($curl)) {
                curl_close($curl);
            }
        }
    }
    
    public function queryTransaction($checkoutRequestId) {
        try {
            logMessage("Starting transaction query for CheckoutRequestID: " . $checkoutRequestId);
            
            $query_url = $this->environment === 'sandbox'
                ? 'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query'
                : 'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query';
                
            $timestamp = date('YmdHis');
            $password = base64_encode($this->businessShortCode . $this->passkey . $timestamp);
            
            $curl_post_data = [
                'BusinessShortCode' => $this->businessShortCode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'CheckoutRequestID' => $checkoutRequestId
            ];
            
            $data_string = json_encode($curl_post_data);
            logMessage("Query payload: " . $data_string);
            
            $curl = curl_init($query_url);
            curl_setopt_array($curl, [
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->accessToken
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $data_string,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            
            $response = curl_exec($curl);
            
            if ($response === false) {
                throw new Exception('Failed to query transaction: ' . curl_error($curl));
            }
            
            logMessage("MPesa API Response: " . $response);
            $result = json_decode($response, true);
            
            if (!$result) {
                throw new Exception('Failed to decode MPesa response');
            }
            
            if (isset($result['ResultCode'])) {
                $result['ResultMessage'] = match((string)$result['ResultCode']) {
                    '0' => 'The transaction was completed successfully',
                    '1' => 'The balance is insufficient for the transaction',
                    '1032' => 'Transaction cancelled by user',
                    '1037' => 'Timeout in completing transaction',
                    default => 'Unknown result code: ' . $result['ResultCode']
                };
            }
            
            return $result;
            
        } catch (Exception $e) {
            logMessage("Transaction query failed: " . $e->getMessage(), 'ERROR');
            throw $e;
        } finally {
            if (isset($curl)) {
                curl_close($curl);
            }
        }
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
    
    public function updatePaymentStatus($checkoutRequestId, $status, $resultCode, $resultDesc, $mpesaReceiptNumber = null, $phoneNumber = null) {
        try {
            if ($status === 'COMPLETED' && $mpesaReceiptNumber) {
                $updateQuery = "UPDATE mpesa_payments SET 
                                status = ?,
                                result_code = ?,
                                result_desc = ?,
                                mpesa_receipt = ?,
                                phone_number = ?,
                                updated_at = NOW()
                               WHERE checkout_request_id = ?";
                               
                $stmt = $this->conn->prepare($updateQuery);
                if (!$stmt) {
                    throw new Exception("Failed to prepare statement: " . $this->conn->error);
                }
                
                $stmt->bind_param("ssssss", $status, $resultCode, $resultDesc, $mpesaReceiptNumber, $phoneNumber, $checkoutRequestId);
            } else {
                $updateQuery = "UPDATE mpesa_payments SET 
                                status = ?,
                                result_code = ?,
                                result_desc = ?,
                                updated_at = NOW()
                               WHERE checkout_request_id = ?";
                               
                $stmt = $this->conn->prepare($updateQuery);
                if (!$stmt) {
                    throw new Exception("Failed to prepare statement: " . $this->conn->error);
                }
                
                $stmt->bind_param("ssss", $status, $resultCode, $resultDesc, $checkoutRequestId);
            }
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update payment status: " . $stmt->error);
            }
            
            if ($stmt->affected_rows === 0) {
                logMessage("No rows updated for CheckoutRequestID: $checkoutRequestId", 'WARNING');
            } else {
                logMessage("Successfully updated payment status for CheckoutRequestID: $checkoutRequestId");
                if ($mpesaReceiptNumber) {
                    logMessage("Stored M-Pesa receipt number: $mpesaReceiptNumber");
                }
                if ($phoneNumber) {
                    logMessage("Stored phone number: $phoneNumber");
                }
            }
            
            if ($status === 'COMPLETED') {
                $this->updateOrderStatus($checkoutRequestId);
            }
            
            return $stmt->affected_rows > 0;
            
        } catch (Exception $e) {
            logMessage("Database update error: " . $e->getMessage(), 'ERROR');
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
            if (!$stmt) {
                throw new Exception("Failed to prepare statement: " . $this->conn->error);
            }
            
            $stmt->bind_param("s", $checkoutRequestId);
            if (!$stmt->execute()) {
                throw new Exception("Failed to fetch order_id: " . $stmt->error);
            }
            
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            if (!$row || !isset($row['order_id'])) {
                throw new Exception("No order_id found for checkout request: " . $checkoutRequestId);
            }
            
            $stmt->close();
            
            
            $updateQuery = "UPDATE orders SET 
                            payment_status = 'PAID',
                            status = 'PROCESSING',
                            updated_at = NOW()
                           WHERE id = ?";
                           
            $stmt = $this->conn->prepare($updateQuery);
            if (!$stmt) {
                throw new Exception("Failed to prepare order update statement: " . $this->conn->error);
            }
            
            $stmt->bind_param("i", $row['order_id']);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update order status: " . $stmt->error);
            }
            
            logMessage("Successfully updated order status for order ID: " . $row['order_id']);
            
        } catch (Exception $e) {
            logMessage("Order status update error: " . $e->getMessage(), 'ERROR');
            throw $e;
        } finally {
            if (isset($stmt)) {
                $stmt->close();
            }
        }
    }
    
    public function __destruct() {
        if (isset($this->conn)) {
            $this->conn->close();
        }
    }
}

try {
    logMessage("Starting payment status check request");
    
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) {
        throw new Exception('No input data received');
    }
    
    logMessage("Raw input: " . $rawInput);
    
    $data = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON data: ' . json_last_error_msg());
    }
    
    if (!isset($data['checkoutRequestId'])) {
        throw new Exception('Invalid request data: checkoutRequestId missing');
    }
    
    $mpesa = new MpesaExpressQuery(
        'F1tuXfV73l8AUIXUVEdvQsRE7OJsRdg9kz22y67vCEG1TCul',
        'agskGrWUs4A9NwazyA6bRhk9fCUm5wDmGfoPA9RQjA5biDaOJckGIAAIkJPFH0uU'
    );
    
    $response = $mpesa->queryTransaction($data['checkoutRequestId']);
    
    if (!isset($response['ResultCode'])) {
        throw new Exception('Invalid MPesa response: ResultCode missing');
    }
    
    $status = match((string)$response['ResultCode']) {
        '0' => 'COMPLETED',
        '1' => 'FAILED',
        '1032' => 'CANCELLED',
        '1037' => 'TIMEOUT',
        default => 'UNKNOWN'
    };
    
    $db = new DatabaseHandler([
        'host' => 'localhost',
        'user' => 'root',
        'pass' => '',
        'name' => 'ecommerce'
    ]);
    
    $mpesaReceiptNumber = null;
    $phoneNumber = null;
    if ($status === 'COMPLETED') {
        $mpesaReceiptNumber = $response['MpesaReceiptNumber'] ?? null;
        $phoneNumber = $response['PhoneNumber'] ?? null;
    }
    
    $updated = $db->updatePaymentStatus(
        $data['checkoutRequestId'],
        $status,
        (string)$response['ResultCode'],
        $response['ResultDesc'] ?? 'No description provided',
        $mpesaReceiptNumber,
        $phoneNumber
    );
    
    $responseData = [
        'success' => true,
        'message' => $response['ResultMessage'] ?? $response['ResultDesc'] ?? 'Status check completed',
        'data' => [
            'status' => $status,
            'resultCode' => $response['ResultCode'],
            'resultDesc' => $response['ResultDesc'] ?? '',
            'checkoutRequestId' => $data['checkoutRequestId'],
            'mpesaReceiptNumber' => $mpesaReceiptNumber,
            'phoneNumber' => $phoneNumber,
            'databaseUpdated' => $updated
        ]
    ];
    
    echo json_encode($responseData, JSON_THROW_ON_ERROR);
    
} catch (Exception $e) {
    logMessage("Error in main execution: " . $e->getMessage(), 'ERROR');
    
    $errorResponse = [
        'success' => false,
        'message' => 'An error occurred while processing the request',
        'error' => $e->getMessage()
    ];
    
    http_response_code(500);
    echo json_encode($errorResponse, JSON_THROW_ON_ERROR);
}
