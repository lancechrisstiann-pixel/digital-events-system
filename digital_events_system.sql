-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 08, 2026 at 02:27 AM
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
-- Database: `digital_events_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `attendees`
--

CREATE TABLE `attendees` (
  `id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `registered_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dim_location`
--

CREATE TABLE `dim_location` (
  `location_key` int(10) UNSIGNED NOT NULL,
  `location_name` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dim_status`
--

CREATE TABLE `dim_status` (
  `status_key` int(10) UNSIGNED NOT NULL,
  `status_name` varchar(50) NOT NULL,
  `status_category` varchar(50) NOT NULL COMMENT 'active | complete | inactive'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dim_time`
--

CREATE TABLE `dim_time` (
  `time_key` int(10) UNSIGNED NOT NULL,
  `full_date` date NOT NULL,
  `day_of_week` tinyint(4) NOT NULL COMMENT '1=Mon … 7=Sun',
  `day_name` varchar(10) NOT NULL,
  `day_of_month` tinyint(4) NOT NULL,
  `week_of_year` tinyint(4) NOT NULL,
  `month_num` tinyint(4) NOT NULL,
  `month_name` varchar(10) NOT NULL,
  `quarter` tinyint(4) NOT NULL COMMENT '1–4',
  `year_num` smallint(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dim_user`
--

CREATE TABLE `dim_user` (
  `user_key` int(10) UNSIGNED NOT NULL,
  `oltp_user_id` int(10) UNSIGNED NOT NULL,
  `username` varchar(100) NOT NULL,
  `registered_date` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `etl_log`
--

CREATE TABLE `etl_log` (
  `id` int(11) NOT NULL,
  `run_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `rows_inserted` int(11) NOT NULL DEFAULT 0,
  `rows_updated` int(11) NOT NULL DEFAULT 0,
  `status` varchar(50) DEFAULT NULL,
  `message` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `events`
--

CREATE TABLE `events` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL,
  `location` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `event_date` datetime NOT NULL,
  `max_attendees` int(10) UNSIGNED DEFAULT NULL,
  `status` varchar(20) DEFAULT 'unready',
  `event_type` enum('public','private') DEFAULT 'public',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fact_events`
--

CREATE TABLE `fact_events` (
  `fact_id` int(10) UNSIGNED NOT NULL,
  `oltp_event_id` int(10) UNSIGNED NOT NULL,
  `time_key` int(10) UNSIGNED NOT NULL,
  `location_key` int(10) UNSIGNED NOT NULL,
  `user_key` int(10) UNSIGNED NOT NULL,
  `status_key` int(10) UNSIGNED NOT NULL,
  `event_count` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `days_until_event` int(11) DEFAULT NULL COMMENT 'event_date minus created_at in days',
  `snapshot_date` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(20) DEFAULT 'user',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `attendees`
--
ALTER TABLE `attendees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_attendee` (`event_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `dim_location`
--
ALTER TABLE `dim_location`
  ADD PRIMARY KEY (`location_key`),
  ADD KEY `idx_location_name` (`location_name`(50));

--
-- Indexes for table `dim_status`
--
ALTER TABLE `dim_status`
  ADD PRIMARY KEY (`status_key`),
  ADD UNIQUE KEY `uq_status_name` (`status_name`);

--
-- Indexes for table `dim_time`
--
ALTER TABLE `dim_time`
  ADD PRIMARY KEY (`time_key`),
  ADD UNIQUE KEY `uq_full_date` (`full_date`),
  ADD KEY `idx_year_month` (`year_num`,`month_num`),
  ADD KEY `idx_quarter` (`year_num`,`quarter`);

--
-- Indexes for table `dim_user`
--
ALTER TABLE `dim_user`
  ADD PRIMARY KEY (`user_key`),
  ADD KEY `idx_oltp_user_id` (`oltp_user_id`);

--
-- Indexes for table `etl_log`
--
ALTER TABLE `etl_log`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_event_date` (`event_date`);

--
-- Indexes for table `fact_events`
--
ALTER TABLE `fact_events`
  ADD PRIMARY KEY (`fact_id`),
  ADD UNIQUE KEY `uq_oltp_event` (`oltp_event_id`),
  ADD KEY `idx_time_key` (`time_key`),
  ADD KEY `idx_user_key` (`user_key`),
  ADD KEY `idx_status_key` (`status_key`),
  ADD KEY `idx_location_key` (`location_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `attendees`
--
ALTER TABLE `attendees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dim_location`
--
ALTER TABLE `dim_location`
  MODIFY `location_key` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dim_status`
--
ALTER TABLE `dim_status`
  MODIFY `status_key` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dim_time`
--
ALTER TABLE `dim_time`
  MODIFY `time_key` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dim_user`
--
ALTER TABLE `dim_user`
  MODIFY `user_key` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `etl_log`
--
ALTER TABLE `etl_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `events`
--
ALTER TABLE `events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fact_events`
--
ALTER TABLE `fact_events`
  MODIFY `fact_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendees`
--
ALTER TABLE `attendees`
  ADD CONSTRAINT `attendees_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attendees_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `fact_events`
--
ALTER TABLE `fact_events`
  ADD CONSTRAINT `fk_fact_location` FOREIGN KEY (`location_key`) REFERENCES `dim_location` (`location_key`),
  ADD CONSTRAINT `fk_fact_status` FOREIGN KEY (`status_key`) REFERENCES `dim_status` (`status_key`),
  ADD CONSTRAINT `fk_fact_time` FOREIGN KEY (`time_key`) REFERENCES `dim_time` (`time_key`),
  ADD CONSTRAINT `fk_fact_user` FOREIGN KEY (`user_key`) REFERENCES `dim_user` (`user_key`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
