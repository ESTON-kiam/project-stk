<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Make Payment - Nitumie Bob</title>
    <link href="images/1200px-M-PESA_LOGO-01.svg.png" rel="icon">
    <link href="images/1200px-M-PESA_LOGO-01.svg.png" rel="apple-touch-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/index.css">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
               
                <div class="payment-status text-center bg-white payment-card" id="processing-status">
                    <div class="spinner"></div>
                    <h5 class="mt-3">Processing Payment</h5>
                    <p class="text-muted">Please wait while we initiate your payment...</p>
                </div>

                <div class="payment-status text-center bg-white payment-card" id="awaiting-status">
                    <i class="fas fa-mobile-alt status-icon text-primary"></i>
                    <h5>Check Your Phone</h5>
                    <p>Please enter your M-PESA PIN to complete the payment</p>
                    <div class="progress" style="height: 4px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" id="timeout-progress" style="width: 100%"></div>
                    </div>
                </div>

                <div class="payment-status text-center bg-white payment-card" id="success-status">
                    <i class="fas fa-check-circle status-icon text-success"></i>
                    <h5>Payment Successful!</h5>
                    <p class="text-success">Your transaction has been completed successfully</p>
                    <div class="transaction-details mt-3">
                        <p class="mb-2">Transaction ID: <span id="transaction-id"></span></p>
                        <p class="mb-2">Amount: <span id="confirmed-amount" class="transaction-amount"></span></p>
                        <button class="btn btn-outline-primary mt-3" onclick="window.location.reload()">Make Another Payment</button>
                    </div>
                </div>

                <div class="payment-status text-center bg-white payment-card" id="failed-status">
                    <i class="fas fa-times-circle status-icon text-danger"></i>
                    <h5>Payment Failed</h5>
                    <p class="text-danger" id="error-details">Sorry, your payment could not be processed</p>
                    <button class="btn btn-primary mt-3" onclick="window.location.reload()">Try Again</button>
                </div>

                
                <div class="card payment-card" id="payment-form-card">
                    <div class="card-body p-4">
                        <h4 class="text-center mb-4 fw-bold">Make a Payment</h4>
                        
                        <form id="payment-form">
                            <div class="mb-3">
                                <label for="amount" class="form-label">Amount (KES)</label>
                                <div class="input-group">
                                    <span class="input-group-text">KSh</span>
                                    <input type="number" class="form-control" name="amount" id="amount" 
                                           min="1" step="1" required placeholder="Enter amount">
                                </div>
                                <small class="text-muted">Minimum amount: KSh 1</small>
                            </div>
                            
                            <div class="mb-4">
                                <label for="phone" class="form-label">M-PESA Phone Number</label>
                                <div class="phone-input-group">
                                    <span class="phone-prefix">+254</span>
                                    <input type="tel" class="form-control phone-input" name="phone" id="phone" 
                                           pattern="^[0-9]{9}$" required placeholder="07XXXXXXXX">
                                </div>
                                <small class="text-muted">Enter Your Mpesa Number</small>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg" id="submit-btn">
                                    Pay Now
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="text-center mt-4">
            <a href="transactions.php" class="btn btn-outline-primary">
                <i class="fas fa-history"></i> View Transactions
            </a>
        </div>
    </div>

    <script>
        let checkStatusInterval;
        let merchantRequestId = '';
        let checkoutRequestId = '';
        const TIMEOUT_DURATION = 120000; 
        let startTime;

       
        document.getElementById('phone').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.startsWith('0')) {
                value = value.substring(1);
            }
            e.target.value = value.slice(0, 9);
        });

        document.getElementById('payment-form').addEventListener('submit', async function(event) {
            event.preventDefault();
            
            const phone = document.getElementById('phone').value;
            const amount = document.getElementById('amount').value;

            if (!validateForm(phone, amount)) {
                return;
            }

            startPaymentProcess();

            try {
                const response = await initiatePayment(phone, amount);
                handlePaymentInitiation(response);
            } catch (error) {
                handlePaymentError(error);
            }
        });

        function validateForm(phone, amount) {
            if (!phone || !amount) {
                showStatus('failed-status');
                document.getElementById('error-details').textContent = 'Please fill in all fields';
                return false;
            }

            if (phone.length !== 9) {
                showStatus('failed-status');
                document.getElementById('error-details').textContent = 'Please enter a valid phone number';
                return false;
            }

            if (amount < 1) {
                showStatus('failed-status');
                document.getElementById('error-details').textContent = 'Minimum amount is KSh 1';
                return false;
            }

            return true;
        }

        async function initiatePayment(phone, amount) {
            const formattedPhone = '254' + phone;
            const response = await fetch('stk_initiate.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    phone: formattedPhone,
                    amount: amount
                })
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            return response.json();
        }

        function startPaymentProcess() {
            hideAllStatus();
            showStatus('processing-status');
            document.getElementById('payment-form-card').style.display = 'none';
            startTime = Date.now();
        }

        function handlePaymentInitiation(data) {
            if (data.ResponseCode === '0') {
                merchantRequestId = data.MerchantRequestID;
                checkoutRequestId = data.CheckoutRequestID;
                showStatus('awaiting-status');
                startStatusChecking();
                startProgressBar();
            } else {
                showStatus('failed-status');
                document.getElementById('error-details').textContent = data.ResponseDescription || 'Payment initiation failed';
            }
        }

        function handlePaymentError(error) {
            showStatus('failed-status');
            document.getElementById('error-details').textContent = 'There was an error processing your payment';
            console.error('Payment Error:', error);
        }

        function startStatusChecking() {
            checkStatusInterval = setInterval(checkPaymentStatus, 5000);
            setTimeout(handleTimeout, TIMEOUT_DURATION);
        }

        function startProgressBar() {
            const progressBar = document.getElementById('timeout-progress');
            progressBar.style.transition = `width ${TIMEOUT_DURATION}ms linear`;
            setTimeout(() => progressBar.style.width = '0%', 50);
        }

        async function checkPaymentStatus() {
            try {
                const response = await fetch('check_payment_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        merchantRequestId: merchantRequestId,
                        checkoutRequestId: checkoutRequestId
                    })
                });

                const data = await response.json();
                handleStatusResponse(data);
            } catch (error) {
                console.error('Error checking payment status:', error);
            }
        }

        function handleStatusResponse(data) {
            if (data.status === 'successful') {
                clearInterval(checkStatusInterval);
                document.getElementById('transaction-id').textContent = data.transactionId || 'N/A';
                document.getElementById('confirmed-amount').textContent = 
                    'KSh ' + (data.amount || document.getElementById('amount').value);
                showStatus('success-status');
            } else if (data.status === 'failed') {
                clearInterval(checkStatusInterval);
                showStatus('failed-status');
                document.getElementById('error-details').textContent = 
                    data.message || 'Payment failed. Please try again.';
            }
        }

        function handleTimeout() {
            clearInterval(checkStatusInterval);
            if (document.getElementById('awaiting-status').style.display === 'block') {
                showStatus('failed-status');
                document.getElementById('error-details').textContent = 'Payment timeout. Please try again.';
            }
        }

        function hideAllStatus() {
            const statuses = ['processing-status', 'awaiting-status', 'success-status', 'failed-status'];
            statuses.forEach(status => {
                document.getElementById(status).style.display = 'none';
            });
        }

        function showStatus(statusId) {
            hideAllStatus();
            document.getElementById(statusId).style.display = 'block';
        }
    </script>
</body>
</html>