-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 24, 2026 at 07:31 AM
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
-- Database: `cars_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `tbl_allocated_passengers`
--

CREATE TABLE `tbl_allocated_passengers` (
  `id` int(11) NOT NULL,
  `allocation_id` int(11) NOT NULL,
  `passenger_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_allocations`
--

CREATE TABLE `tbl_allocations` (
  `allocation_id` int(11) NOT NULL,
  `car_id` int(11) DEFAULT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `requestor_id` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `date` date NOT NULL,
  `pickup_time` time NOT NULL,
  `dropoff_time` time DEFAULT NULL,
  `actual_pickup_time` time DEFAULT NULL,
  `actual_dropoff_time` time DEFAULT NULL,
  `pickup_location` varchar(255) NOT NULL,
  `dropoff_location` varchar(255) DEFAULT NULL,
  `local_number` varchar(50) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` enum('pending','approved','declined','in_progress','completed','cancelled') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `request_number` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_audit_logs`
--

CREATE TABLE `tbl_audit_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `allocation_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_audit_logs`
--

INSERT INTO `tbl_audit_logs` (`log_id`, `user_id`, `action`, `allocation_id`, `details`, `timestamp`) VALUES
(1, 1, 'login', NULL, 'Logged in', '2026-07-24 04:15:09');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_cars`
--

CREATE TABLE `tbl_cars` (
  `car_id` int(11) NOT NULL,
  `brand` varchar(100) NOT NULL,
  `plate_number` varchar(20) NOT NULL,
  `parking` varchar(50) DEFAULT NULL,
  `coding_day` varchar(20) DEFAULT NULL,
  `capacity` int(11) NOT NULL DEFAULT 4,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('available','reserved','in_use','returned','under_maintenance','coding_restricted') DEFAULT 'available',
  `status_updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_drivers`
--

CREATE TABLE `tbl_drivers` (
  `driver_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `mobile` varchar(20) NOT NULL,
  `car_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_noreply`
--

CREATE TABLE `tbl_noreply` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `host` varchar(255) NOT NULL,
  `port` int(11) NOT NULL DEFAULT 587,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_noreply`
--

INSERT INTO `tbl_noreply` (`id`, `email`, `password`, `host`, `port`, `created_at`, `updated_at`) VALUES
(1, 'system.notification@glory.com.ph', 'T%161390629034at', 'smtp.office365.com', 587, '2026-05-08 04:42:26', '2026-05-08 04:42:26');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_passengers`
--

CREATE TABLE `tbl_passengers` (
  `passenger_id` int(11) NOT NULL,
  `passenger_name` varchar(100) NOT NULL,
  `contact` varchar(50) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_users`
--

CREATE TABLE `tbl_users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `full_name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','driver','general') NOT NULL DEFAULT 'general',
  `driver_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_users`
--

INSERT INTO `tbl_users` (`user_id`, `username`, `full_name`, `email`, `password`, `role`, `driver_id`, `created_at`) VALUES
(1, 'admin', 'Calvin Berlandino', 'system.gh@glory.com.ph', '$2y$12$uawUQQp5zNupXECjAAqw6uBdlIWnlgM3BPc..ZwofaRa75s/X78ga', 'admin', NULL, '2026-07-14 04:44:27');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `tbl_allocated_passengers`
--
ALTER TABLE `tbl_allocated_passengers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_allocation_passenger` (`allocation_id`,`passenger_id`),
  ADD KEY `passenger_id` (`passenger_id`);

--
-- Indexes for table `tbl_allocations`
--
ALTER TABLE `tbl_allocations`
  ADD PRIMARY KEY (`allocation_id`),
  ADD UNIQUE KEY `request_number` (`request_number`),
  ADD KEY `requestor_id` (`requestor_id`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `idx_date` (`date`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_driver_date` (`driver_id`,`date`),
  ADD KEY `idx_car_date` (`car_id`,`date`);

--
-- Indexes for table `tbl_audit_logs`
--
ALTER TABLE `tbl_audit_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `tbl_cars`
--
ALTER TABLE `tbl_cars`
  ADD PRIMARY KEY (`car_id`),
  ADD UNIQUE KEY `plate_number` (`plate_number`);

--
-- Indexes for table `tbl_drivers`
--
ALTER TABLE `tbl_drivers`
  ADD PRIMARY KEY (`driver_id`),
  ADD KEY `car_id` (`car_id`);

--
-- Indexes for table `tbl_noreply`
--
ALTER TABLE `tbl_noreply`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_email` (`email`);

--
-- Indexes for table `tbl_passengers`
--
ALTER TABLE `tbl_passengers`
  ADD PRIMARY KEY (`passenger_id`);

--
-- Indexes for table `tbl_users`
--
ALTER TABLE `tbl_users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `tbl_allocated_passengers`
--
ALTER TABLE `tbl_allocated_passengers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_allocations`
--
ALTER TABLE `tbl_allocations`
  MODIFY `allocation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_audit_logs`
--
ALTER TABLE `tbl_audit_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tbl_cars`
--
ALTER TABLE `tbl_cars`
  MODIFY `car_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_drivers`
--
ALTER TABLE `tbl_drivers`
  MODIFY `driver_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_noreply`
--
ALTER TABLE `tbl_noreply`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tbl_passengers`
--
ALTER TABLE `tbl_passengers`
  MODIFY `passenger_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_users`
--
ALTER TABLE `tbl_users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `tbl_allocated_passengers`
--
ALTER TABLE `tbl_allocated_passengers`
  ADD CONSTRAINT `tbl_allocated_passengers_ibfk_1` FOREIGN KEY (`allocation_id`) REFERENCES `tbl_allocations` (`allocation_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_allocated_passengers_ibfk_2` FOREIGN KEY (`passenger_id`) REFERENCES `tbl_passengers` (`passenger_id`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_allocations`
--
ALTER TABLE `tbl_allocations`
  ADD CONSTRAINT `tbl_allocations_ibfk_1` FOREIGN KEY (`car_id`) REFERENCES `tbl_cars` (`car_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_allocations_ibfk_2` FOREIGN KEY (`driver_id`) REFERENCES `tbl_drivers` (`driver_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_allocations_ibfk_3` FOREIGN KEY (`requestor_id`) REFERENCES `tbl_users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_allocations_ibfk_4` FOREIGN KEY (`approved_by`) REFERENCES `tbl_users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `tbl_audit_logs`
--
ALTER TABLE `tbl_audit_logs`
  ADD CONSTRAINT `tbl_audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `tbl_users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_drivers`
--
ALTER TABLE `tbl_drivers`
  ADD CONSTRAINT `tbl_drivers_ibfk_1` FOREIGN KEY (`car_id`) REFERENCES `tbl_cars` (`car_id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
