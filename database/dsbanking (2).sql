-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 09, 2026 at 09:27 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `dsbanking`
--

-- --------------------------------------------------------

--
-- Table structure for table `accounts`
--

CREATE TABLE `accounts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `account_number` char(16) NOT NULL,
  `balance` decimal(12,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `accounts`
--

INSERT INTO `accounts` (`id`, `user_id`, `account_number`, `balance`, `created_at`) VALUES
(5, 7, '4241195564289163', 475.00, '2026-02-22 16:43:18'),
(6, 8, '8288650928176969', 36.00, '2026-02-22 17:10:17'),
(7, 9, '2204186221889972', 297.00, '2026-03-01 19:28:17'),
(8, 10, '3295986576092334', 202.00, '2026-03-01 21:40:55'),
(9, 11, '7280754796788788', 50.00, '2026-03-01 22:38:49'),
(10, 12, '1076817816007509', 41.00, '2026-03-05 10:31:44'),
(11, 13, '6942878650049394', 20.00, '2026-03-05 11:15:00');

-- --------------------------------------------------------

--
-- Table structure for table `apple_pay_devices`
--

CREATE TABLE `apple_pay_devices` (
  `id` int(11) NOT NULL,
  `card_id` int(11) NOT NULL,
  `device_name` varchar(100) DEFAULT NULL,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cards`
--

CREATE TABLE `cards` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `card_number` char(16) NOT NULL,
  `cvv` char(3) NOT NULL,
  `expiry_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cards`
--

INSERT INTO `cards` (`id`, `account_id`, `card_number`, `cvv`, `expiry_date`, `created_at`) VALUES
(5, 5, '8584364716882270', '683', '2029-02-22', '2026-02-22 16:43:18'),
(6, 6, '0501878840126046', '512', '2029-02-22', '2026-02-22 17:10:17'),
(7, 7, '1511028025535577', '726', '2029-03-01', '2026-03-01 19:28:17'),
(8, 8, '7034881370629734', '800', '2029-03-01', '2026-03-01 21:40:55'),
(9, 9, '5071195613440193', '600', '2029-03-01', '2026-03-01 22:38:49'),
(10, 10, '0475611835192889', '398', '2029-03-05', '2026-03-05 10:31:44'),
(11, 11, '3525874277198881', '309', '2029-03-05', '2026-03-05 11:15:00');

-- --------------------------------------------------------

--
-- Table structure for table `charges`
--

CREATE TABLE `charges` (
  `id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `phone_number` varchar(20) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `receiver_name` varchar(50) NOT NULL,
  `receiver_surname` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `charges`
--

INSERT INTO `charges` (`id`, `sender_id`, `company_id`, `phone_number`, `amount`, `receiver_name`, `receiver_surname`, `created_at`) VALUES
(1, 7, 2, '+383 +38344578377', 10.00, 'Bejtush', 'Rrahimi', '2026-03-01 18:43:18'),
(2, 8, 1, '+383 84528204858', 1.00, 'Shkodran', 'Qorrolli', '2026-03-01 18:44:35'),
(3, 7, 1, '+383 8481548784', 1.00, 'Mejtush', 'Bisha', '2026-03-01 19:08:16'),
(4, 7, 2, '+383 +38344568898', 10.00, 'Olti', 'Avdullahu', '2026-03-01 19:18:53'),
(5, 8, 1, '+383 6485858585', 3.00, 'Edi', 'Haziri', '2026-03-01 19:21:43'),
(6, 9, 1, '+383 258856365', 10.00, 'Dion', 'Sherifi', '2026-03-01 19:31:38'),
(7, 9, 1, '+355 2536248487', 10.00, 'Dion', 'Sherifi', '2026-03-01 19:40:53'),
(8, 9, 2, '+355 57548484848', 3.00, 'Nis', 'Elezi', '2026-03-01 19:44:55'),
(9, 7, 2, '+383 555555536', 10.00, 'Elina', 'Mustafa', '2026-03-01 19:46:44'),
(10, 7, 1, '+383 44123456', 10.00, 'Erbar', 'Tahiri', '2026-03-01 21:44:36'),
(11, 7, 2, '+383 45896523', 3.00, 'Eridon', 'Berbatovci', '2026-03-01 22:17:30'),
(12, 7, 1, '+383 45896054', 10.00, 'Uccuci', 'Tdcjv', '2026-03-01 22:18:04'),
(13, 7, 1, '+383 44569325', 10.00, 'Dion', 'Krilla', '2026-03-01 22:30:32'),
(14, 9, 1, '+383 44563888', 10.00, 'Naim', 'Frasheri', '2026-03-01 22:35:39'),
(15, 7, 1, '+32 4528268464', 10.00, 'Jxdjdnkt', 'Jrjrrk', '2026-03-01 22:45:39'),
(16, 7, 2, '+994 45282869', 3.00, 'Jdhdhdh', 'Hdhdhdhd', '2026-03-01 22:46:25'),
(17, 7, 1, '+374 65655555', 10.00, 'Ismet', 'Peja', '2026-03-01 22:49:22'),
(18, 7, 1, '+383 44573695', 5.00, 'Shkodran', 'Qorrolli', '2026-03-05 10:39:06'),
(19, 7, 2, '+383 43507962', 100.00, 'Esma', 'Maloku', '2026-03-05 11:18:24');

-- --------------------------------------------------------

--
-- Table structure for table `topup_companies`
--

CREATE TABLE `topup_companies` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `image_url` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `topup_companies`
--

INSERT INTO `topup_companies` (`id`, `name`, `image_url`) VALUES
(1, 'Vala', 'assets/images/vala.png'),
(2, 'IPKO', 'assets/images/ipko.png');

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `sender_account` int(11) DEFAULT NULL,
  `receiver_account` int(11) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`id`, `sender_account`, `receiver_account`, `amount`, `description`, `created_at`) VALUES
(1, 5, 6, 20.00, 'Paret per qerapa', '2026-03-01 18:41:32'),
(7, 7, 5, 200.00, 'Here is your money because you are a G', '2026-03-01 19:30:57'),
(12, 5, 8, 2.00, '', '2026-03-01 21:42:50'),
(14, 5, 8, 200.00, '', '2026-03-01 21:46:01'),
(15, 5, 6, 20.00, 'Qe paret per armotizera', '2026-03-01 22:18:37'),
(16, 7, 5, 100.00, 'Here comes the money', '2026-03-01 22:36:19'),
(17, 7, 5, 50.00, '', '2026-03-01 22:36:30'),
(18, 7, 5, 200.00, '', '2026-03-01 22:36:45'),
(19, 7, 5, 150.00, '', '2026-03-01 22:36:52'),
(20, 5, 9, 50.00, 'Dhurate per diplomim', '2026-03-01 22:43:50'),
(21, 5, 7, 30.00, '', '2026-03-05 10:26:02'),
(22, 5, 10, 41.00, 'Paret per kurs Muaji Mars', '2026-03-05 10:37:33'),
(23, 5, 11, 20.00, 'Paret per kafe', '2026-03-05 11:19:03');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `surname` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `pin` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `surname`, `email`, `password`, `pin`, `created_at`) VALUES
(7, 'Dion', 'Sherifi', 'dionsherifi7@gmail.com', '$2y$10$boAKX8khQaXXEB6xeseQjONR1CU8MmDupLABz5YNYpuF3ERbxLt1W', '$2y$10$guiqPwcz/dvo7842xAHtPukoKEz0SOF8P7l0Gs49HH1yX5Ej/HqwC', '2026-02-22 16:43:18'),
(8, 'Elona ', 'Mustafa', 'elonas@gmail.com', '$2y$10$TwGkz.tsZeDHVeGNnYvQze3qFS0s4Mb6T/UTuCNcsdi8BQNjYAo82', '$2y$10$5Yi9Qn.jA6e3rwe0KFQzieVbNTcn2.kAOhzSSfoTkGO0iUiy22JQC', '2026-02-22 17:10:17'),
(9, 'Sami', 'Frasheri', 'samif@gmail.com', '$2y$10$GyxFdnJgW4AKWx/xokiZWOQpcMx0PfXkKRrRECSRbGM7JoWhrbT46', '$2y$10$i.8hjYPADGhGzPwrygMEP.9UUz6LjXjna7LQdm590iEtdBjzC.Kvq', '2026-03-01 19:28:17'),
(10, 'Erblin', 'Tahiri', 'blinith@gmail.com', '$2y$10$RDCZhfFcLBnT37R9hSuXW.q.sxidzjKc6Uj0beq121sc4vitiih5i', '$2y$10$hROsgfrJmRRrLaQeqXLcQOzvUInqPz0Vem/h0e16MIFVniIgZWFiu', '2026-03-01 21:40:55'),
(11, 'Ari', 'Sherifi', 'arisherifi@gmail.com', '$2y$10$SH/v8WiItwH36K3nm.64ru6j0a/eV0mCHjdcOaKaIfYo7eWid64ce', '$2y$10$Qgv0UMp1hOsUnoI8tEoO6u8cW/Sdbl9f6bqLgcHYJYW2iynSv8gam', '2026-03-01 22:38:49'),
(12, 'Shkodran', 'Qorrolli', 'shkodran@gmail.com', '$2y$10$4qMz6XpcNtFA/lWYUw2UBOSUCs1o67qp39ESdNjhdxxuEGZxqJ/Ui', '$2y$10$Up3X3le/6zlGsSNBbfQK9.vmTPCKLJxDtj9kQYT/heHlB51x2H81y', '2026-03-05 10:31:44'),
(13, 'Esma', 'Maloku', 'esma@gmail.com', '$2y$10$qy7Wa.feXyxAVY2N5mS23eiFroYp8zHcSaV1IixlFmjKjfIiuH38e', '$2y$10$MGI6J1pwTKHgG6jk8q285efUKRbD00PiRH4okl5BmjkWFrx9KgrMy', '2026-03-05 11:15:00');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `accounts`
--
ALTER TABLE `accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `account_number` (`account_number`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `apple_pay_devices`
--
ALTER TABLE `apple_pay_devices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `card_id` (`card_id`);

--
-- Indexes for table `cards`
--
ALTER TABLE `cards`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `card_number` (`card_number`),
  ADD KEY `account_id` (`account_id`);

--
-- Indexes for table `charges`
--
ALTER TABLE `charges`
  ADD PRIMARY KEY (`id`),
  ADD KEY `company_id` (`company_id`);

--
-- Indexes for table `topup_companies`
--
ALTER TABLE `topup_companies`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sender_account` (`sender_account`),
  ADD KEY `receiver_account` (`receiver_account`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `accounts`
--
ALTER TABLE `accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `apple_pay_devices`
--
ALTER TABLE `apple_pay_devices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cards`
--
ALTER TABLE `cards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `charges`
--
ALTER TABLE `charges`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `topup_companies`
--
ALTER TABLE `topup_companies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `accounts`
--
ALTER TABLE `accounts`
  ADD CONSTRAINT `accounts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `apple_pay_devices`
--
ALTER TABLE `apple_pay_devices`
  ADD CONSTRAINT `apple_pay_devices_ibfk_1` FOREIGN KEY (`card_id`) REFERENCES `cards` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `cards`
--
ALTER TABLE `cards`
  ADD CONSTRAINT `cards_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `charges`
--
ALTER TABLE `charges`
  ADD CONSTRAINT `charges_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `topup_companies` (`id`);

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`sender_account`) REFERENCES `accounts` (`id`),
  ADD CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`receiver_account`) REFERENCES `accounts` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
