<?php
header('Content-Type: application/json');


$db_config = [
    'host' => 'localhost',
    'username' => 'root',
    'password' => '',
    'database' => 'ecommerce'
];


function createDbConnection($config) {
    try {
        $conn = new mysqli($config['host'], $config['username'], $config['password'], $config['database']);
        if ($conn->connect_error) {
            throw new Exception("Connection failed: " . $conn->connect_error);
        }
        return $conn;
    } catch (Exception $e) {
        return null;
    }
}


function insertInitialPayment($conn, $phone, $amount, $checkoutRequestId) {
    $stmt = $conn->prepare("INSERT INTO mpesa_payments (phone_number, amount, checkout_request_id, status) VALUES (?, ?, ?, 'UNKNOWN')");
    $stmt->bind_param("sds", $phone, $amount, $checkoutRequestId);
    
    if (!$stmt->execute()) {
        throw new Exception("Error inserting payment: " . $stmt->error);
    }
    
    $stmt->close();
    return $conn->insert_id;
}


$json_data = file_get_contents('php://input');
$data = json_decode($json_data);

if ($data) {
    date_default_timezone_set('Africa/Nairobi');

    $consumerKey = 'F1tuXfV73l8AUIXUVEdvQsRE7OJsRdg9kz22y67vCEG1TCul';
    $consumerSecret = 'agskGrWUs4A9NwazyA6bRhk9fCUm5wDmGfoPA9RQjA5biDaOJckGIAAIkJPFH0uU';
    $BusinessShortCode = '174379';
    $Passkey = 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919';

    $phone = $data->phone;
    $phone = preg_replace('/^0/', '254', $phone);
    $phone = preg_replace('/^\+254/', '254', $phone);

    $PartyA = $phone;
    $AccountReference = '2255';
    $TransactionDesc = 'Test Payment';
    $Amount = $data->amount;
    $Timestamp = date('YmdHis');

    $Password = base64_encode($BusinessShortCode . $Passkey . $Timestamp);

   
    $conn = createDbConnection($db_config);
    if (!$conn) {
        echo json_encode(['ResponseCode' => '1', 'ResponseDescription' => 'Database connection failed']);
        exit;
    }

    $headers = ['Content-Type:application/json; charset=utf8'];
    $access_token_url = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    $initiate_url = 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
    $CallBackURL = 'https://655a-196-250-209-180.ngrok-free.app/callback_url.php';

    $curl = curl_init($access_token_url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($curl, CURLOPT_HEADER, FALSE);
    curl_setopt($curl, CURLOPT_USERPWD, $consumerKey . ':' . $consumerSecret);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    $result = curl_exec($curl);

    if (curl_errno($curl)) {
        $conn->close();
        echo json_encode(['ResponseCode' => '1', 'ResponseDescription' => 'Curl error: ' . curl_error($curl)]);
        curl_close($curl);
        exit;
    }
    curl_close($curl);

    $result = json_decode($result);

    if (isset($result->access_token)) {
        $access_token = $result->access_token;

        $stkheader = ['Content-Type:application/json', 'Authorization:Bearer ' . $access_token];
        $curl_post_data = array(
            'BusinessShortCode' => $BusinessShortCode,
            'Password' => $Password,
            'Timestamp' => $Timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => $Amount,
            'PartyA' => $PartyA,
            'PartyB' => $BusinessShortCode,
            'PhoneNumber' => $PartyA,
            'CallBackURL' => $CallBackURL,
            'AccountReference' => $AccountReference,
            'TransactionDesc' => $TransactionDesc
        );

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $initiate_url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $stkheader);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($curl_post_data));
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

        $curl_response = curl_exec($curl);

        if (curl_errno($curl)) {
            $conn->close();
            echo json_encode(['ResponseCode' => '1', 'ResponseDescription' => 'Curl error: ' . curl_error($curl)]);
            curl_close($curl);
            exit;
        }
        curl_close($curl);

        if (empty($curl_response)) {
            $conn->close();
            echo json_encode(['ResponseCode' => '1', 'ResponseDescription' => 'Empty response from M-Pesa']);
            exit;
        }

        $response_data = json_decode($curl_response);

        if ($response_data === null) {
            $conn->close();
            echo json_encode(['ResponseCode' => '1', 'ResponseDescription' => 'Error decoding JSON response']);
            exit;
        }

        try {
           
            $paymentId = insertInitialPayment($conn, $PartyA, $Amount, $response_data->CheckoutRequestID);
            
            echo json_encode([
                'ResponseCode' => '0',
                'CheckoutRequestID' => $response_data->CheckoutRequestID,
                'CustomerMessage' => $response_data->CustomerMessage,
                'Status' => ($response_data->ResponseCode === '0') ? 'Successful' : 'Failed',
                'PaymentID' => $paymentId
            ]);
        } catch (Exception $e) {
            echo json_encode(['ResponseCode' => '1', 'ResponseDescription' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['ResponseCode' => '1', 'ResponseDescription' => 'Error fetching access token']);
    }
    
    $conn->close();
} else {
    echo json_encode(['ResponseCode' => '1', 'ResponseDescription' => 'Invalid request data']);
}