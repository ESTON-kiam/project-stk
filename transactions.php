<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MPesa Payments</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f4f9;
        }
        .container {
            margin: 20px auto;
            width: 90%;
            max-width: 1200px;
            background: #fff;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            overflow: hidden;
            padding: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            margin-bottom: 20px;
        }
        thead {
            background-color: #007BFF;
            color: #fff;
        }
        th, td {
            padding: 10px 15px;
            border: 1px solid #ddd;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        tr:hover {
            background-color: #f1f1f1;
        }
        .status-completed {
            color: green;
            font-weight: bold;
        }
        .status-failed {
            color: red;
            font-weight: bold;
        }
        .status-cancelled {
            color: orange;
            font-weight: bold;
        }
        .status-timeout {
            color: gray;
            font-weight: bold;
        }
        .status-unknown {
            color: blue;
            font-weight: bold;
        }
        h1 {
            text-align: center;
            color: #333;
        }
        .btn-container {
            text-align: center;
            margin-top: 10px;
        }
        .btn-payment {
            display: inline-block;
            padding: 10px 20px;
            font-size: 16px;
            color: #fff;
            background-color: #28a745;
            text-decoration: none;
            border-radius: 5px;
            transition: background-color 0.3s;
        }
        .btn-payment:hover {
            background-color: #218838;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>MPesa Payments</h1>
        <table>
            <thead>
                <tr>
                    <th>Phone Number</th>
                    <th>Amount</th>
                    <th>Date</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody id="mpesaTableBody">
           <?php include ('get_transactions.php')?>
            </tbody>
        </table>
        <div class="btn-container">
            <button class="btn-payment" id="makePayment">Make a Payment</button>
        </div>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const tableBody = document.getElementById("mpesaTableBody");

            async function fetchPayments() {
                try {
                    const response = await fetch('get_transactions.php');
                    const data = await response.json();

                    tableBody.innerHTML = '';

                    data.forEach(payment => {
                        const row = document.createElement('tr');

                        row.innerHTML = `
                            <td>${payment.phone_number}</td>
                            <td>${payment.amount}</td>
                            <td>${new Date(payment.created_at).toLocaleDateString()}</td>
                            <td class="status-${payment.status.toLowerCase()}">${payment.status}</td>
                        `;

                        tableBody.appendChild(row);
                    });
                } catch (error) {
                    console.error('Error fetching payments:', error);
                }
            }

            fetchPayments();

            // Refresh data every minute
            setInterval(fetchPayments, 60000);
        });

        document.getElementById("makePayment").addEventListener("click", function () {
            window.location.href = "index.php"; // Redirect to the payment page
        });
    </script>
</body>
</html>
