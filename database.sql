CREATE TABLE `mpesa_payments` (
  `id` int(11) NOT NULL,
  `phone_number` varchar(15) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `checkout_request_id` varchar(50) NOT NULL,
  status ENUM('COMPLETED', 'FAILED', 'CANCELLED', 'TIMEOUT', 'UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `mpesa_receipt` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE mpesa_payments
ADD COLUMN result_code VARCHAR(10),
ADD COLUMN result_desc TEXT,
ADD COLUMN updated_at DATETIME;
ALTER TABLE `mpesa_payments`
  ADD PRIMARY KEY (`id`);



ALTER TABLE `mpesa_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
COMMIT;
