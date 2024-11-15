<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "ecommerce";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$query = "SELECT phone_number, amount, created_at, status FROM mpesa_payments ORDER BY created_at DESC";
$result = $conn->query($query);

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $statusClass = match ($row['status']) {
            'COMPLETED' => 'status-completed',
            'FAILED' => 'status-failed',
            'CANCELLED' => 'status-cancelled',
            'TIMEOUT' => 'status-timeout',
            default => 'status-unknown',
        };

        // Updated table row without the mpesa_receipt column
        echo "<tr>
            <td>{$row['phone_number']}</td>
            <td>KES {$row['amount']}</td>
            <td>{$row['created_at']}</td>
            <td class='{$statusClass}'>{$row['status']}</td>
        </tr>";
    }
} else {
    echo "<tr><td colspan='4'>No records found</td></tr>"; // Adjusted colspan to 4 since we have 4 columns now
}

$conn->close();
?>
