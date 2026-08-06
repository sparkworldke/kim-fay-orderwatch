-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Aug 03, 2026 at 11:34 PM
-- Server version: 11.4.12-MariaDB-ubu2404
-- PHP Version: 8.3.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `kimfay_sight_orderwatch`
--

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `role` varchar(255) NOT NULL DEFAULT 'Administrator',
  `department_id` bigint(20) UNSIGNED DEFAULT NULL,
  `department_role` varchar(20) NOT NULL DEFAULT 'member',
  `org_level` varchar(20) NOT NULL DEFAULT 'sales',
  `reports_to_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `product_type_scope` varchar(20) NOT NULL DEFAULT 'both',
  `data_scope_mode` varchar(20) NOT NULL DEFAULT 'scoped',
  `is_shared_mailbox` tinyint(1) NOT NULL DEFAULT 0,
  `trained_at` timestamp NULL DEFAULT NULL,
  `is_consultant` tinyint(1) NOT NULL DEFAULT 0,
  `phone_number` varchar(20) DEFAULT NULL,
  `whatsapp_number` varchar(20) DEFAULT NULL,
  `rep_code` varchar(50) DEFAULT NULL,
  `employee_number` varchar(50) DEFAULT NULL,
  `designation` varchar(255) DEFAULT NULL,
  `division` varchar(255) DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `password` varchar(255) NOT NULL,
  `password_changed_at` timestamp NULL DEFAULT NULL,
  `inactivity_digest_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `last_inactivity_digest_sent_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `is_super_admin` tinyint(1) NOT NULL DEFAULT 0,
  `is_account_manager` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `trained_by` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `role`, `department_id`, `department_role`, `org_level`, `reports_to_user_id`, `product_type_scope`, `data_scope_mode`, `is_shared_mailbox`, `trained_at`, `is_consultant`, `phone_number`, `whatsapp_number`, `rep_code`, `employee_number`, `designation`, `division`, `email_verified_at`, `is_active`, `password`, `password_changed_at`, `inactivity_digest_enabled`, `last_inactivity_digest_sent_at`, `remember_token`, `is_super_admin`, `is_account_manager`, `created_at`, `updated_at`, `trained_by`) VALUES
(1, 'Titus Kaleli Mutiso', 'commercialtechlead@kimfay.com', 'Administrator', NULL, 'hod', 'hod', 5, 'both', 'deny_all', 0, NULL, 1, '+254718020630', '+254718020630', 'P415', 'P415', 'Commercial Tech Lead', 'Marketing', '2026-07-14 01:54:52', 1, '$2y$12$2DFjM9jB/I3sfqyvDxqZ9eRaMiC21COKwCtZ.ilCWgKyS3EiHi23i', '2026-07-10 15:51:23', 0, NULL, NULL, 1, 0, '2026-06-23 12:18:22', '2026-07-30 15:04:12', NULL),
(5, 'Vignesh Ramachandran', 'cco@kimfay.com', 'Administrator', 7, 'executive', 'c_suite', NULL, 'both', 'org_wide', 0, NULL, 1, NULL, NULL, 'P320', 'P320', 'Chief Commercial Officer', 'C-Suite', '2026-07-14 01:54:52', 1, '$2y$12$6VgHArv6HpGOxT/r9KoCi.B1XzNZj9WdexZ4WC9wlLvAgLXxhgpWm', '2026-07-14 01:52:17', 0, NULL, NULL, 0, 0, '2026-06-25 11:07:31', '2026-07-14 01:52:17', NULL),
(6, 'Divya Jumani', 'djumani@kimfay.com', 'Administrator', NULL, 'executive', 'executive', NULL, 'both', 'org_wide', 0, NULL, 1, NULL, NULL, 'C1144', 'C1144', NULL, NULL, '2026-07-14 01:54:52', 1, '$2y$12$mfGCGo6nvdv42sLxChMMvOPfASp/CDQiQQ5BDDoJ3mwqOngQ6KCCq', '2026-07-14 01:52:17', 0, NULL, NULL, 0, 0, '2026-06-25 11:09:08', '2026-07-14 01:52:17', NULL),
(7, 'Shawn Kevin Mugendi', 'gtintern@kimfay.com', 'Customer Service Agent', NULL, 'member', 'gap', 71, 'both', 'deny_all', 1, NULL, 1, '+254745977012', NULL, 'C1170', 'C1170', 'Marketing', 'Marketing', '2026-07-14 01:54:52', 1, '$2y$12$6SMd/kBVJGI/jFMi4IxpLOlX0k/Z3EHt3xrxcVDSHNuMNcUJb0EKm', '2026-07-14 01:52:17', 0, NULL, NULL, 0, 0, '2026-06-29 01:28:01', '2026-07-14 01:52:17', NULL),
(8, 'Purity Nduku Kioko', 'moderntrade@kimfay.com', 'Customer Service Agent', 1, 'hod', 'hod', 5, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P496', 'P496', 'Modern Trade Manager', 'Modern Trade', '2026-07-14 01:54:52', 1, '$2y$12$Kqolr/Ivy0NOgAYSm5P6ouq2v9uq/guo8V.OvzUgN7XQfpHA0tYMm', '2026-07-14 01:52:17', 0, NULL, NULL, 0, 0, '2026-06-29 01:29:27', '2026-07-14 01:52:17', NULL),
(9, 'Mayur Hitesh Rabadia', 'warehouse.manager@kimfay.com', 'Customer Service Agent', 13, 'hod', 'hod', 91, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P478', 'P478', 'Warehouse & Dispatch Manager', 'FGS', '2026-07-14 01:54:52', 1, '$2y$12$IMR.LpKNg3LLNIhnwWlIhu/ZCaIDcUS2zYnuLCfCIZPCMf5xumgQW', '2026-07-14 01:52:17', 0, NULL, NULL, 0, 0, '2026-06-29 01:30:14', '2026-07-14 01:52:17', NULL),
(11, 'Production', 'productionmanager@kimfay.com', 'Customer Service Manager', NULL, 'member', 'sales', NULL, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P391', 'P391', NULL, NULL, '2026-07-14 01:54:52', 1, '$2y$12$lIR7rAkpy6xDoKj.XUz86OLF5ViDKZuYFnmhMAumZcsM410dp5pdu', '2026-07-14 01:52:17', 0, NULL, NULL, 0, 0, '2026-06-29 01:32:26', '2026-07-14 01:52:17', NULL),
(12, 'Steve', 'salesstrategy@kimfay.com', 'Customer Service Manager', NULL, 'member', 'sales', 5, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P013', 'P013', NULL, NULL, '2026-07-14 01:54:52', 1, '$2y$12$SxInlC8phmtmxIcM9zb5aePvpBOfunoY8j6bKr0jbqkOqPQ5uoct6', '2026-07-14 01:52:17', 0, NULL, NULL, 0, 0, '2026-06-29 01:33:06', '2026-07-14 01:52:17', NULL),
(16, 'Mercyline Kemunto Moranga', 'kpsales.hygiene@kimfay.com', 'Sales Consultant', NULL, 'member', 'sales', 24, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P483', 'P483', 'Profesional Sales Consultant - Hygiene Innovations', 'Professional Sales', '2026-07-14 01:54:52', 1, '$2y$12$1jjVz4PV5DkUve6V7nOiJOObS5XLEoEciJzm6UkPuLMb4.r9Bc.EK', '2026-07-14 02:12:24', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:34', '2026-07-14 02:12:24', NULL),
(17, 'Victoria Munanie Kyalo Ndetei', 'adsupervisor@kimfay.com', 'Sales Consultant', 12, 'member', 'brandsops', 68, 'trading', 'scoped', 0, NULL, 1, NULL, NULL, 'P370', 'P370', 'Account Developer', 'Partner Brands', '2026-07-14 01:54:52', 1, '$2y$12$xEEP5yR8ietjfLuM3lXpbeAXaLY049ofkVA.HJxhlcKGgNHzsw1ma', '2026-07-14 02:12:24', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:35', '2026-07-14 02:12:24', NULL),
(19, 'Berna Piwang Abondo', 'berna.piwang@kimfay.com', 'Sales Consultant', NULL, 'member', 'sales', 24, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P460', 'P460', 'Professional Sales Consultant', 'Professional Sales', '2026-07-14 01:54:52', 1, '$2y$12$1x2TEzVaEH58GiVd0FavA.X35lmuFldX5dxLiz.ca1D/SjVtIbOjC', '2026-07-14 02:12:24', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:35', '2026-07-14 02:12:24', NULL),
(20, 'Consultant JUM', 'npd@kimfay.com', 'Sales Consultant', NULL, 'member', 'sales', NULL, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P104', 'P104', NULL, NULL, '2026-07-14 01:54:52', 1, '$2y$12$tDneBV6zzbK2gt5WCYABfue7j1lj1yIOlkcakr6uHLeGqOjZAqAly', '2026-07-14 02:12:24', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:35', '2026-07-14 02:12:24', NULL),
(21, 'Georgina Muthini Kiilu', 'georgina.kac@kimfay.com', 'Sales Consultant', 1, 'member', 'sales', 8, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P013', 'P013', 'Modern Trade Representative', 'Modern Trade', '2026-07-14 01:54:52', 1, '$2y$12$kczcB8qbpPvnjFwTw1nK9.6W01K3OvrRoqc7SwBPKW5cBiWruWnvu', '2026-07-14 02:12:24', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:36', '2026-07-14 02:12:24', NULL),
(22, 'Beatrice Luteshi', 'customercare@kimfay.com', 'Sales Consultant', NULL, 'hod', 'hod', 5, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P014', 'P014', 'Customer Care Manager', 'Customer Care', '2026-07-14 01:54:52', 1, '$2y$12$OMhNDsDDShZii/6u.l.ZzehxQcoJH5rPOVJAlsrFDdeiICxtsw9nS', '2026-07-14 02:12:24', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:36', '2026-07-14 02:12:24', NULL),
(23, 'Lucy Gakii Rimberia', 'lucygakii@kimfay.com', 'Sales Consultant', NULL, 'member', 'sales', 24, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P022', 'P022', 'Professional Sales Consultant', 'Professional Sales', '2026-07-14 01:54:52', 1, '$2y$12$9axnt1mUEfd13AVLAWpCxeSq1HeNJ17Jath.P58hUGUPv4Zq6trW6', '2026-07-14 02:12:24', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:36', '2026-07-14 02:12:24', NULL),
(24, 'Susan Ngina Mwathi', 'susan@kimfay.com', 'Sales Consultant', NULL, 'hod', 'hod', 5, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P025', 'P025', 'Business Development Manager', 'Professional Sales', '2026-07-14 01:54:52', 1, '$2y$12$DjG4mK53E15ea7wktAc/QeZbBwcohQW5Krm/p68REJl54YIPMmyD6', '2026-07-14 02:12:24', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:37', '2026-07-14 02:12:24', NULL),
(25, 'Johnsam Kioko Musyoki', 'jkioko@kimfay.com', 'Sales Consultant', 1, 'member', 'sales', 8, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P033', 'P033', 'Area Sales Manager', 'General Trade', '2026-07-14 01:54:52', 1, '$2y$12$0ZvHcy36NbvWQl4jESEvYu4pcBCIoxwDPHc5cqUvHzYTY2.fo4rk6', '2026-07-17 12:27:00', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:37', '2026-07-17 12:27:00', NULL),
(26, 'Jane Kirigo Kuria', 'jane.kac@kimfay.com', 'Sales Consultant', 1, 'member', 'sales', 8, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P076', 'P076', 'Modern Trade Representative', 'Modern Trade', '2026-07-14 01:54:52', 1, '$2y$12$RHa2C6Hwr/vBBXCVoAJfMOUkTJvpQqfVmRbVbIT99iq9l.Z4B8LqK', '2026-07-14 02:12:24', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:37', '2026-07-14 02:12:24', NULL),
(28, 'Doreen Mukami Mugwika', 'dmugwika@kimfay.com', 'Sales Consultant', NULL, 'member', 'sales', 24, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P096', 'P096', 'Professional Sales Consultant', 'Professional Sales', '2026-07-14 01:54:52', 1, '$2y$12$IS3uR7FnF.wVGwmspVR.5Oz7rF3PmIwSCXMifr4T8Mm.zXqMW5Yiq', '2026-07-14 02:12:24', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:38', '2026-07-14 02:12:24', NULL),
(29, 'Irene Naliaka Luketelo', 'iluketelo@kimfay.com', 'Sales Consultant', 3, 'member', 'sales', 24, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P104', 'P104', 'Professional Sales Consultant', 'Professional Sales', '2026-07-14 01:54:52', 1, '$2y$12$9MmfooHyFcWFMxnsqDPmcu3V0qBBuqoyIKa03xgrsdT//puo.qVHG', '2026-07-14 02:12:24', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:38', '2026-07-14 02:12:24', NULL),
(31, 'George Amenya Morang\'a', 'gamenya@kimfay.com', 'Sales Consultant', 1, 'member', 'sales', 8, 'both', 'scoped', 0, NULL, 1, '+254726031669', NULL, 'P120', 'P120', 'Senior Sales Representative', 'Modern Trade', '2026-07-14 01:54:52', 1, '$2y$12$vLBlZVbewhlR.kRfFBFjiuPodjScyGjU4UZgS7WEjMWtzMjejxKb6', '2026-07-15 08:16:21', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:39', '2026-07-15 08:16:21', NULL),
(32, 'Lilian Kalondu Kimeu', 'lilian.kimeu@kimfay.com', 'Sales Consultant', 1, 'member', 'sales', 8, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P149', 'P149', 'Senior Sales Representative', 'Modern Trade', '2026-07-14 01:54:52', 1, '$2y$12$XRsdpRtcszJ4ev1yFjbuR.jhPD.xvMqBNC3jr4bE0Hqa8tRynxAy2', '2026-07-20 18:31:32', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:39', '2026-07-20 18:31:32', NULL),
(33, 'June Jemutai Chesire', 'june.kp@kimfay.com', 'Sales Consultant', NULL, 'member', 'sales', 24, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P193', 'P193', 'Professional Sales Consultant', 'Professional Sales', '2026-07-14 01:54:52', 1, '$2y$12$E7rq3e8ThzNd/J4ELisx2.vRcYBhnPfONi1BAE5BhNYMCM37ro8g2', '2026-07-14 02:12:24', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:39', '2026-07-14 02:12:24', NULL),
(34, 'Kevin Werunga Barasa', 'kelvin.werunga@kimfay.com', 'Sales Consultant', 1, 'member', 'sales', 8, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P230', 'P230', 'Consumer Sales Representative', 'Modern Trade', '2026-07-14 01:54:52', 1, '$2y$12$/WxMwRRfDGnM0fwiXHNuJ.tok1tXnSnQxe/Q39HwPc9DJoZt3LjtW', '2026-07-17 06:29:24', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:39', '2026-07-17 06:29:24', NULL),
(35, 'Beryl Akinyi Muga', 'muga.kac@kimfay.com', 'Sales Consultant', 1, 'member', 'sales', 8, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P245', 'P245', 'Modern Trade Representative', 'Modern Trade', '2026-07-14 01:54:52', 1, '$2y$12$Mem13w5oaVSDwX.LKalDeukYUgmR42y.N.thtcjPM9HA8t5I1b/b6', '2026-07-14 02:12:24', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:40', '2026-07-14 02:12:24', NULL),
(38, 'Dennis Mutwiri Kimathi', 'dennis.kac@kimfay.com', 'Sales Consultant', 1, 'member', 'sales', 8, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P293', 'P293', 'Modern Trade Representative', 'Modern Trade', '2026-07-14 01:54:52', 1, '$2y$12$Suroj0stPCTGpjShtaysYelmmiCH6yWgb5qIKftKp2jEvvTRQjNSy', '2026-07-14 02:12:24', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:40', '2026-07-14 02:12:24', NULL),
(39, 'Lucy Wanjiru Munene', 'lucy.kac@kimfay.com', 'Sales Consultant', 1, 'member', 'sales', 8, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P321', 'P321', 'Modern Trade Representative', 'Modern Trade', '2026-07-14 01:54:52', 1, '$2y$12$GvNdsDAnTk549YJAqpiclelcQY6T0G3n.B6Xoj1WA8a/M.F1Iy94i', '2026-07-14 02:12:24', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:41', '2026-07-14 02:12:24', NULL),
(40, 'Dan Kaberia Mutiga', 'consumersales.msa@kimfay.com', 'Sales Consultant', 1, 'member', 'sales', 8, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P345', 'P345', 'Consumer Sales Representative', 'General Trade', '2026-07-14 01:54:52', 1, '$2y$12$Un0zIoPhX//16HfcPjEVmOuo.Ko.G9jOjrc.QpzhIhQfdR3m2qZwW', '2026-07-14 02:12:24', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:41', '2026-07-14 02:12:24', NULL),
(41, 'Nicholas Muriuki', 'nicholas.muriuki@kimfay.com', 'Sales Consultant', 12, 'member', 'brandsops', 68, 'trading', 'scoped', 0, NULL, 1, NULL, NULL, 'P380', 'P380', 'Medical Representative', 'Partner Brands', '2026-07-14 01:54:52', 1, '$2y$12$3ATUIUdzRwzexsXUCE9dIOpNtjGnDrRQE/T4aSAwjUWPq5K58T6HK', '2026-07-14 02:12:24', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:41', '2026-07-14 02:12:24', NULL),
(42, 'Johnson Muthiga Mbatia', 'asm.lake@kimfay.com', 'Sales Consultant', 1, 'member', 'sales', 8, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P395', 'P395', 'Area Sales Manager', 'General Trade', '2026-07-14 01:54:52', 1, '$2y$12$j3C8LeE/43DAqJ813msnwOmQ76oSPw3yUaLS/GpPeI0rXULNtQxWS', '2026-07-14 02:12:24', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:41', '2026-07-14 02:12:24', NULL),
(43, 'Peris Wanjiku Kariuki', 'pharmacychannel@kimfay.com', 'Sales Consultant', 12, 'member', 'brandsops', 68, 'trading', 'scoped', 0, NULL, 1, NULL, NULL, 'P400', 'P400', 'Pharmarcy Channel Manager', 'Partner Brands', '2026-07-14 01:54:52', 1, '$2y$12$6r.6U9.awCpx7XjpAGXGweRFvatc1QdMORkkSPA8BfDY7QWwLNQAq', '2026-07-14 02:12:24', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:42', '2026-07-14 02:12:24', NULL),
(47, 'Brian Basweti ChirChir', 'brian.basweti@kimfay.com', 'Sales Consultant', 12, 'member', 'brandsops', 68, 'trading', 'scoped', 0, NULL, 1, NULL, NULL, 'P438', 'P438', 'Trade Marketing Lead - Dabur', 'Partner Brands', '2026-07-14 01:54:52', 1, '$2y$12$bI.V6qWcSJHl0xxnkqApXONkI/zB.J6WDa0/xahCVduo.qH59eUoG', '2026-07-14 02:12:24', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:43', '2026-07-14 02:12:24', NULL),
(48, 'Zipporah Wangeci Muiruri', 'moderntrade.nrb@kimfay.com', 'Sales Consultant', 1, 'member', 'sales', 8, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P443', 'P443', 'Modern Trade Representative', 'Modern Trade', '2026-07-14 01:54:52', 1, '$2y$12$j2VrVkncgVD234cHhO9UcesApI/g6ZM03LNaRC1Yq46MvptHg4mNS', '2026-07-10 06:11:13', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:43', '2026-07-10 06:11:13', NULL),
(50, 'Clement Omondi Otieno', 'clement.otieno@kimfay.com', 'Sales Consultant', 1, 'member', 'sales', 8, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P487', 'P487', 'Consumer Sales Representative', 'General Trade', '2026-07-14 01:54:52', 1, '$2y$12$E9kXX6xNtJEANXlWtunlTO5j7vHIbVZen4DqElnc6cvDYNKlCntM6', '2026-07-10 06:11:14', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:44', '2026-07-10 06:11:14', NULL),
(51, 'John Kaguai Njoroge', 'kpconsultant@kimfay.com', 'Sales Consultant', NULL, 'member', 'sales', 24, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P489', 'P489', 'Profesional Sales Consultant - Upcountry', 'Professional Sales', '2026-07-14 01:54:52', 1, '$2y$12$5z30Kgkk/SmOpjxBLXq58.kyzyxbqvp6PfUf5ktdGKrFaPAt36Sxm', '2026-07-10 06:11:14', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:44', '2026-07-10 06:11:14', NULL),
(53, 'Shirleen Chebet Kimutai', 'salesconsultant2@kimfay.com', 'Sales Consultant', NULL, 'member', 'sales', 24, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P505', 'P505', 'Professional Sales Consultant', 'Professional Sales', '2026-07-14 01:54:52', 1, '$2y$12$1jjVz4PV5DkUve6V7nOiJOObS5XLEoEciJzm6UkPuLMb4.r9Bc.EK', '2026-07-14 11:51:37', 0, NULL, NULL, 0, 0, '2026-07-06 01:23:44', '2026-07-14 11:51:37', NULL),
(55, 'Hartaj Singh Bains', 'hbains@kimfay.com', 'Executive', NULL, 'executive', 'executive', NULL, 'both', 'org_wide', 0, NULL, 1, NULL, NULL, 'P302', 'P302', 'Sales and Marketing Director', 'Executive', '2026-07-14 01:54:52', 1, '$2y$12$aO416V/bRCz7aB3QcJHrP.2VwartBpFBUM9o714Rx0SYswcvaw6YC', '2026-07-10 03:34:10', 0, NULL, NULL, 0, 0, '2026-07-06 22:03:58', '2026-07-10 03:34:10', NULL),
(56, 'Rajdeep Singh Bains', 'rbains@kimfay.com', 'Executive', NULL, 'executive', 'c_suite', NULL, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P301', 'P301', 'Chief Executive Officer', 'Executive', '2026-07-14 01:54:52', 1, '$2y$12$Y4EYgH/bMPuktfbDqUedCu1ZLIM.3OCjYivSjcHJ5nPyAD41oO9Ua', '2026-07-06 22:03:59', 0, NULL, NULL, 0, 0, '2026-07-06 22:03:59', '2026-07-06 22:03:59', NULL),
(57, 'Miraj Shantilal Jhankhariya', 'coo@kimfay.com', 'Executive', NULL, 'executive', 'c_suite', NULL, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P231', 'P231', 'Chief Operations Officer', 'C-Suite', '2026-07-14 01:54:52', 1, '$2y$12$ud279UGPm9POUNaU3MZr4eJI8gWX/ITuQ1hMZWwbulhgnytJdU10m', '2026-07-06 22:03:59', 0, NULL, NULL, 0, 0, '2026-07-06 22:03:59', '2026-07-06 22:03:59', NULL),
(58, 'Yvonne Achieng Otieno', 'salesrep.kp@kimfay.com', 'Sales Consultant', NULL, 'member', 'sales', 24, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P317', 'P317', 'Professional Sales Consultant', 'Professional Sales', '2026-07-14 01:54:52', 1, '$2y$12$oOdVrrYH6fjL4TF8UK8M6uar/w/rJcjy7ArjrfhGWti4wiU75QqWO', '2026-07-06 22:18:06', 0, NULL, NULL, 0, 0, '2026-07-06 22:18:06', '2026-07-06 22:18:06', NULL),
(59, 'Siham Ahmed Mohamed', 'siham.marketing@kimfay.com', 'Executive', 5, 'hod', 'hod', 5, 'both', 'org_wide', 0, NULL, 1, NULL, NULL, 'P429', 'P429', 'Marketing Coordinator - Modern Trade', 'Marketing', '2026-07-14 01:54:52', 1, '$2y$12$IQudet04k9aCDSoFRwhEPulCgcT.a5CiakYKhq2n7GGnghkHn3iaK', '2026-07-10 03:34:17', 0, NULL, NULL, 0, 0, '2026-07-09 13:14:16', '2026-07-10 03:34:17', NULL),
(60, 'Adan Gulleid Ibrahim', 'brandoperations.unilever@kimfay.com', 'Sales Operations', 12, 'member', 'brandsops', 68, 'trading', 'scoped', 0, NULL, 1, NULL, NULL, 'P456', 'P456', 'Brands Operations Manager', 'Partner Brands', '2026-07-14 01:54:52', 1, '$2y$12$7T49r3d9PreDvzjyAzItIeObsatwhRqR4ZKHg8/lnsjWFZqK1Q99u', '2026-07-10 05:11:42', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:02', '2026-07-10 05:11:42', NULL),
(61, 'Akbar Majid Alamkhan', 'akbar.majid@kimfay.com', 'Sales Operations', 13, 'hod', 'hod', 91, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P215', 'P215', 'Store Manager', 'Mombasa Depot', '2026-07-14 01:54:52', 1, '$2y$12$AFuB0Fp49iBT99upi1Un9uDCSoWq3HUhvHWqcuiZnWZZ/Sx7nuu8y', '2026-07-10 03:34:03', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:03', '2026-07-10 03:34:03', NULL),
(62, 'Alice Gituto Mworia', 'hr@kimfay.com', 'Sales Operations', 8, 'hod', 'hod', 57, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P049', 'P049', 'Head of People and Culture', 'Human Resource', '2026-07-14 01:54:52', 1, '$2y$12$okatGigvwQ.a1nf/YWGuv.IIwv5yRxX12oGnHdW2.SGVrC3u/4ure', '2026-07-10 04:11:53', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:03', '2026-07-10 04:11:53', NULL),
(63, 'Ann Christine Njeri Kahira', 'sales@kimfay.com', 'Sales Consultant', NULL, 'member', 'gap', 22, 'both', 'deny_all', 1, NULL, 1, NULL, NULL, 'P322', 'P322', 'Customer Care Representative', 'Customer Care', '2026-07-14 01:54:52', 1, '$2y$12$14vTTa5xlsiy6Kv7H5zxX.4MhwfrksmcSKtLaYN270VI5FAsX7x8K', '2026-07-10 04:11:52', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:04', '2026-07-10 04:11:52', NULL),
(64, 'Antony Kiema', 'it@kimfay.com', 'Sales Operations', 13, 'hod', 'hod', 91, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P498', 'P498', 'IT Manager', 'IT', '2026-07-14 01:54:52', 1, '$2y$12$3MNCh9URndhP5CYAD5sYreD6hbuH6Va9gZZV4fOi3dmYQ0.5qecEa', '2026-07-10 03:34:04', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:04', '2026-07-10 03:34:04', NULL),
(65, 'Brettah Karambu', 'bkarambu@kimfay.com', 'Sales Operations', 13, 'member', 'gap', NULL, 'both', 'deny_all', 0, NULL, 1, NULL, NULL, 'P085', 'P085', NULL, NULL, '2026-07-14 01:54:52', 1, '$2y$12$YCqDtn1CftwmbcMGy/1DNOImI5BFavVZHA4BU7e9dhsdSKnKRhJk.', '2026-07-10 03:34:04', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:04', '2026-07-10 03:34:04', NULL),
(66, 'Brian Muindi Justus', 'salessysadmin@kimfay.com', 'Sales Consultant', 5, 'member', 'sales', 1, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P490', 'P490', 'Sales System Administrator', 'Marketing', '2026-07-14 01:54:52', 1, '$2y$12$hpWw7WewvrwQWCIPZSXByu7Gd4JYdLjNsxUdhmCEpJMeEfD83R47S', '2026-07-10 03:34:05', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:05', '2026-07-10 03:34:05', NULL),
(67, 'Caroline Macharia', 'performancemanager@kimfay.com', 'Sales Operations', 8, 'hod', 'hod', NULL, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P241', 'P241', NULL, NULL, '2026-07-14 01:54:52', 1, '$2y$12$P.V1yqRklA6AFjg9m6sWD.Tq6ovkWdX3nxC4LZVhG4wWU77TFruki', '2026-07-10 03:34:05', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:05', '2026-07-10 03:34:05', NULL),
(68, 'Anne Christine Muthoni', 'partnerbrands@kimfay.com', 'Sales Operations', 12, 'hod', 'hod', 5, 'trading', 'scoped', 0, NULL, 1, NULL, NULL, 'P086', 'P086', 'Head of Partner Brands', 'Partner Brands', '2026-07-14 01:54:52', 1, '$2y$12$pmgV1PoMFgwhHVTyzOqE0OZDxq0m0t3dtdF1KnzbCN8J9kR/Pvd2S', '2026-07-10 04:11:53', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:06', '2026-07-10 04:11:53', NULL),
(69, 'Collins Opiyo', 'stocks@kimfay.com', 'Sales Operations', 13, 'member', 'gap', NULL, 'both', 'deny_all', 0, NULL, 1, NULL, NULL, 'P376', 'P376', NULL, NULL, '2026-07-14 01:54:52', 1, '$2y$12$j3vu/NctRnt0bPjRYEYa1OpJ2jzedqk83xnxgeM5zMw/EXTV3h/lW', '2026-07-10 03:34:06', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:06', '2026-07-10 03:34:06', NULL),
(70, 'Dennis Kememwa', 'application.support@kimfay.com', 'Sales Operations', 13, 'member', 'gap', NULL, 'both', 'deny_all', 0, NULL, 1, NULL, NULL, 'P501', 'P501', NULL, NULL, '2026-07-14 01:54:52', 1, '$2y$12$CYEwxiFTbl5syCPq0KGaruYAGaM12rm/iaqySti..O82UFo/LLI7S', '2026-07-10 03:34:06', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:06', '2026-07-10 03:34:06', NULL),
(71, 'Dickens Ozengo', 'dickens.marketing@kimfay.com', 'Sales Operations', 5, 'hod', 'hod', 5, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P284', 'P284', 'Marketing Manager', 'Marketing', '2026-07-14 01:54:52', 1, '$2y$12$bxtloO3K1gx0yMUX84QYketLNoPSCqUxTpDbIp7VsnoG5a1cNq/y6', '2026-07-10 03:34:07', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:07', '2026-07-10 03:34:07', NULL),
(72, 'Dorcus Mawuda Mwandawiro', 'admin.officer@kimfay.com', 'Sales Operations', 8, 'hod', 'hod', 57, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P450', 'P450', 'Compliance and Safety Manager', 'Administration', '2026-07-14 01:54:52', 1, '$2y$12$PWpqssoKrhI2VAPiOHK5wOf12ESmUX0Hw3PnqjZAjP24Bc9z3ab2q', '2026-07-10 03:34:07', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:07', '2026-07-10 03:34:07', NULL),
(73, 'Erick Otieno Onyango', 'eonyango@kimfay.com', 'Sales Consultant', 1, 'member', 'sales', 8, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P491', 'P491', 'Data Analyst - Modern Trade', 'Modern Trade', '2026-07-14 01:54:52', 1, '$2y$12$p4EIgsmaIdz/gWzjvHTS9uvt8NXZVe/bflZMb7zu2/nGBTOxxnuoO', '2026-07-10 03:34:07', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:07', '2026-07-10 03:34:07', NULL),
(74, 'Esther Karanja', 'purchasing.assistant@kimfay.com', 'Sales Operations', 14, 'member', 'gap', NULL, 'both', 'deny_all', 0, NULL, 1, NULL, NULL, 'P452', 'P452', NULL, NULL, '2026-07-14 01:54:52', 1, '$2y$12$unBjAktAa/uesB8JHXOnPu.H06kHa2RWE9/7kjXAjkfa3M6FFQm2e', '2026-07-10 03:34:08', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:08', '2026-07-10 03:34:08', NULL),
(75, 'Florence Wanjiru Karaya', 'florencek@kimfay.com', 'Sales Consultant', NULL, 'member', 'sales', 24, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P201', 'P201', 'Professional Sales Consultant', 'Professional Sales', '2026-07-14 01:54:52', 1, '$2y$12$dpojN2lZY4bm97vtSXoeiu/ENzVq9N7vt3emuZiuYZkEMjO7FuBF.', '2026-07-10 03:34:08', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:08', '2026-07-10 03:34:08', NULL),
(76, 'Francis Muhoro Thiong\'o', 'exportsales@kimfay.com', 'Sales Operations', 1, 'hod', 'hod', 5, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P084', 'P084', 'Export Sales Manager - East Africa', 'Export Sales', '2026-07-14 01:54:52', 1, '$2y$12$qIWs.5cgFs7yFrcerPGYV.EA7tp/avYrO.n6onN85wEktpfdVDe5C', '2026-07-10 03:34:08', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:08', '2026-07-10 03:34:08', NULL),
(77, 'Fredrick Omondi Dede', 'shiftsupervisor2@kimfay.com', 'Sales Consultant', NULL, 'member', 'sales', 24, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P051', 'P051', 'KP Technician', 'Professional Sales', '2026-07-14 01:54:52', 1, '$2y$12$517PHoNjdDAfA0dtBZZ8uuRM8IoM56IZs5LhQc1MkPWiy/c4RQk32', '2026-07-10 03:34:09', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:09', '2026-07-10 03:34:09', NULL),
(78, 'George Odhiambo Oketch', 'inventorycontroller@kimfay.com', 'Sales Operations', 13, 'hod', 'hod', 91, 'both', 'deny_all', 0, NULL, 1, NULL, NULL, 'P398', 'P398', 'Inventory Controller', 'Accounts', '2026-07-14 01:54:52', 1, '$2y$12$iLJn4z3aDJu0pEfE0c1s0OztQ9TwOq4PxI56ZLtzNw1uQ.I3.I2AK', '2026-07-10 03:34:09', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:09', '2026-07-10 03:34:09', NULL),
(79, 'Gidraf Abiero', 'procurement1@kimfay.com', 'Executive', 14, 'executive', 'c_suite', NULL, 'both', 'org_wide', 0, NULL, 1, NULL, NULL, 'P364', 'P364', NULL, NULL, '2026-07-14 01:54:52', 1, '$2y$12$uH07EX/FgPKFftOzxGNaoOgJtdY6.jcWMeuX1y/hUz0Qv2Xu8BaTi', '2026-07-10 03:34:09', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:09', '2026-07-10 03:34:09', NULL),
(80, 'Grace Njeri Gatonye', 'dtc@kimfay.com', 'Sales Operations', NULL, 'member', 'gap', 22, 'both', 'deny_all', 0, NULL, 1, NULL, NULL, 'P385', 'P385', 'Customer Care Representative', 'Customer Care', '2026-07-14 01:54:52', 1, '$2y$12$2Xba8z94xPa/yjDPuOb8KedZmIIaZUrypZm7KsHGMUHxqnp8iQiuG', '2026-07-10 03:34:10', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:10', '2026-07-10 03:34:10', NULL),
(81, 'Immaculate Kaminika', 'immaculate@kimfay.com', 'Sales Operations', 13, 'member', 'gap', NULL, 'both', 'deny_all', 0, NULL, 1, NULL, NULL, 'P399', 'P399', NULL, NULL, '2026-07-14 01:54:52', 1, '$2y$12$x60uZj8q/075P49bR0A/hOfE48sxZ0s/g6cHv6EfoJFoNWmjLTfdW', '2026-07-10 03:34:10', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:10', '2026-07-10 03:34:10', NULL),
(82, 'Imran Cocker', 'fleet@kimfay.com', 'Sales Operations', NULL, 'hod', 'hod', 57, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P396', 'P396', 'Fleet Workshop Manager', 'Fleet', '2026-07-14 01:54:52', 1, '$2y$12$FXwjI.TAGuLntJZ.yjJp0ODb0ic6K.95qdSGajqkjy3BTj4YpeAHS', '2026-07-10 03:34:10', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:10', '2026-07-10 03:34:10', NULL),
(83, 'Isaya Omondi Kobe', 'salesdataanalyst@kimfay.com', 'Sales Consultant', 5, 'member', 'sales', 8, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P437', 'P437', 'Data Analyst', 'Trade Marketing', '2026-07-14 01:54:52', 1, '$2y$12$3GrOXgSmMK/79t7PC//U7eZMLtCDtUjSjyqDK/D8lstDZumZlwKP.', '2026-07-10 03:34:11', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:11', '2026-07-10 03:34:11', NULL),
(84, 'Jackson Musyoka Pius', 'jmusyoka@kimfay.com', 'Sales Operations', 1, 'member', 'sales', 8, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P028', 'P028', 'Area Sales Manager', 'General Trade', '2026-07-14 01:54:52', 1, '$2y$12$Me6EEVqjQQ1FCuQNKtNhyesKf2rs1PGsVPoFImvOG4/S9dPqXWLv.', '2026-07-10 03:34:11', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:11', '2026-07-10 03:34:11', NULL),
(85, 'Jeritah Michira Moraa', 'jeritah.marketing@kimfay.com', 'Executive', 5, 'member', 'sales', 71, 'both', 'org_wide', 0, NULL, 1, NULL, NULL, 'P485', 'P485', 'Marketing Coordinator-Partner Brands', 'Marketing', '2026-07-14 01:54:52', 1, '$2y$12$3omB1GVkBK7rnAzACs0XfexcOuBwnXlTCbGZe5KRr8wej5067Ll2O', '2026-07-10 03:34:12', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:12', '2026-07-10 03:34:12', NULL),
(86, 'John Gichuki', 'jgichuki.accounts@kimfay.com', 'Sales Operations', 13, 'member', 'gap', NULL, 'both', 'deny_all', 0, NULL, 1, NULL, NULL, 'P458', 'P458', NULL, NULL, '2026-07-14 01:54:52', 1, '$2y$12$1BGzsYE1lQXhaJtMBVArFeMBr93PPFetCaqjrxtex.WmqmHQEBVEG', '2026-07-10 03:34:12', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:12', '2026-07-10 03:34:12', NULL),
(87, 'Joyce Njeri', 'brandoperations.dabur@kimfay.com', 'Sales Operations', 12, 'member', 'brandsops', 68, 'trading', 'scoped', 0, NULL, 1, NULL, NULL, 'P436', 'P436', 'Brands Operations Manager', 'Partner Brands', '2026-07-14 01:54:52', 1, '$2y$12$/u2SgXuO3WQ9HdK3OYayF.GorfO47BRPfYRbnvKjqQtuLqlK3wQqe', '2026-07-10 03:34:12', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:12', '2026-07-10 03:34:12', NULL),
(88, 'Laureen Wangari', 'audit.assistant@kimfay.com', 'Sales Operations', 7, 'member', 'gap', NULL, 'both', 'deny_all', 0, NULL, 1, NULL, NULL, 'P475', 'P475', NULL, NULL, '2026-07-14 01:54:52', 1, '$2y$12$z3yZblbwRXaJTbzl2lNOuele/aIIf18m/kSgpFAyMJ0rcGoJYtGJm', '2026-07-10 03:34:13', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:13', '2026-07-10 03:34:13', NULL),
(89, 'Linet Wavinya Nzioka', 'qc@kimfay.com', 'Sales Operations', 11, 'hod', 'hod', 57, 'both', 'deny_all', 0, NULL, 1, NULL, NULL, 'P017', 'P017', 'Quality Controller', 'Production', '2026-07-14 01:54:52', 1, '$2y$12$egI3LrohVYmNEHNqcCtHjOdRVEwvc9fQlIa7qybY8vHeiRtrNn1fS', '2026-07-10 03:34:13', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:13', '2026-07-10 03:34:13', NULL),
(90, 'Lydiah Chepng\'eno Cheruyoit', 'lydiah.mr@kimfay.com', 'Sales Consultant', 12, 'member', 'brandsops', 68, 'trading', 'scoped', 0, NULL, 1, NULL, NULL, 'P397', 'P397', 'Medical Representative', 'Partner Brands', '2026-07-14 01:54:52', 1, '$2y$12$xXGLBBkbYrdtvGzFCMnV1eZyxYAHwwcZW5pYNbD2TX.jAEIsWNeme', '2026-07-10 03:34:13', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:13', '2026-07-10 03:34:13', NULL),
(91, 'Manan Anilkumar Shah', 'finance@kimfay.com', 'Sales Operations', 13, 'hod', 'hod', 57, 'both', 'deny_all', 0, NULL, 1, NULL, NULL, 'P424', 'P424', 'Chief Financial Officer', 'Accounts', '2026-07-14 01:54:52', 1, '$2y$12$U2TFsELHu0ATvRTLBs0qPOrlehy0LTarrZhoDCt/dhEivckdICB2a', '2026-07-10 03:34:14', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:14', '2026-07-10 03:34:14', NULL),
(92, 'Mercy Nyagano', 'mercy.nyagano@kimfay.com', 'Sales Operations', 13, 'member', 'gap', NULL, 'both', 'deny_all', 0, NULL, 1, NULL, NULL, 'P480', 'P480', NULL, NULL, '2026-07-14 01:54:52', 1, '$2y$12$YkaTRgDWz/uhFKOdBPSvfeG40FeqK2wqEGPcWLiuOojzp/gG9DF1W', '2026-07-10 03:34:14', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:14', '2026-07-10 03:34:14', NULL),
(93, 'Mercy Mumo', 'logisticscoordinator@kimfay.com', 'Executive', 14, 'executive', 'c_suite', 95, 'both', 'org_wide', 0, NULL, 1, NULL, NULL, 'P426', 'P426', NULL, NULL, '2026-07-14 01:54:52', 1, '$2y$12$niU4RGwsSHCGGQSgDYjNxeKQH.rIivAvFtAgE2FlICXNbYFNnp9ey', '2026-07-10 03:34:14', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:14', '2026-07-10 03:34:14', NULL),
(94, 'Moses Mwaka Daniel', 'wholesale@kimfay.com', 'Sales Consultant', 1, 'member', 'sales', 8, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P455', 'P455', 'Consumer Sales Representative - Wholesale and Van Operations', 'General Trade', '2026-07-14 01:54:52', 1, '$2y$12$8usT0bxJtJRdeyi4CiANSOVJgMNhYep58tgpVNku/3m1O91Jo7B.2', '2026-07-10 03:34:15', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:15', '2026-07-10 03:34:15', NULL),
(95, 'Nicodemus Muthini Kivuva', 'purchasing@kimfay.com', 'Sales Operations', 14, 'hod', 'hod', 57, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P227', 'P227', 'Supply Chain Manager', 'Procurement', '2026-07-14 01:54:52', 1, '$2y$12$PGQenusJ6tmSmFEGXGG7juYfdQ9q0zrpAcS2CY2LkauG9QAqvQzYG', '2026-07-10 03:34:15', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:15', '2026-07-10 03:34:15', NULL),
(96, 'Bonface Okomba', 'bokomba@kimfay.com', 'Sales Consultant', 12, 'member', 'brandsops', 68, 'trading', 'scoped', 0, NULL, 1, NULL, NULL, 'P493', 'P493', 'Pharmacy Sales Representative', 'Partner Brands', '2026-07-14 01:54:52', 1, '$2y$12$Cl/fFM8REyjGm3i7fPMWa./llbwBwYa5hC7YskXMFkpCiI96wZs3m', '2026-07-10 03:34:15', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:15', '2026-07-10 03:34:15', NULL),
(97, 'Patrick Kariuki Karanja', 'projects@kimfay.com', 'Sales Operations', 11, 'hod', 'hod', 57, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P063', 'P063', 'Technical Projects Manager', 'Production', '2026-07-14 01:54:52', 1, '$2y$12$RX5UmhqhDtmsvhQkbzbSwuNQi3OyL1ZHkURpiP0FSbEBFI7J7MEjS', '2026-07-10 03:34:16', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:16', '2026-07-10 03:34:16', NULL),
(98, 'Qais Yunus Osman', 'internalaudit@kimfay.com', 'Sales Operations', 7, 'hod', 'hod', 57, 'both', 'deny_all', 1, NULL, 1, NULL, NULL, 'P175', 'P175', 'Internal Auditor', 'Internal Audit', '2026-07-14 01:54:52', 1, '$2y$12$B6miX0A6J/7kuseNtsChPOermIvyeR6kBmpG6sfoJ6FiZl6Rmi7fS', '2026-07-10 03:34:16', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:16', '2026-07-10 03:34:16', NULL),
(99, 'Rajpreet Bains', 'rajpreet@kimfay.com', 'Executive', NULL, 'executive', 'executive', NULL, 'both', 'org_wide', 0, NULL, 1, NULL, NULL, 'P303', 'P303', NULL, NULL, '2026-07-14 01:54:52', 1, '$2y$12$ZRYifs34aJfkD9rF1gA3VOCLog54HycPqSjCNgBmCt9J5rVfa1uta', '2026-07-10 03:34:16', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:16', '2026-07-10 03:34:16', NULL),
(100, 'Reuben Thuita Githinji', 'productionanalyst@kimfay.com', 'Sales Operations', 13, 'hod', 'hod', 91, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P055', 'P055', 'Business Intelligence Manager', 'Accounts', '2026-07-14 01:54:52', 1, '$2y$12$KGOznOLT.DxbTH/WNQLmPuOdOTAIWZcvsMYCDKnK4pEpTAYtoViyG', '2026-07-10 03:34:17', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:17', '2026-07-10 03:34:17', NULL),
(101, 'Seraphine Masanga', 'logistics@kimfay.com', 'Executive', 13, 'executive', 'c_suite', 106, 'both', 'org_wide', 0, NULL, 1, NULL, NULL, 'P141', 'P141', NULL, NULL, '2026-07-14 01:54:52', 1, '$2y$12$ej62fWgdAXOyFCDUQv5Pqu6klXNjw/GzwVkeLP48hGsYPpUesek1q', '2026-07-10 03:34:17', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:17', '2026-07-10 03:34:17', NULL),
(102, 'Stephanie Kerubo Ayieni', 'supervisor.coast@kimfay.com', 'Sales Consultant', 1, 'member', 'sales', 8, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P481', 'P481', 'Territory Supervisor- Coast Region', 'General Trade', '2026-07-14 01:54:52', 1, '$2y$12$XXyyTsi.jArAupE2NnW58OamunJcv2ViC3MUvddEmxPUf2Zug45Di', '2026-07-10 03:34:17', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:17', '2026-07-10 03:34:17', NULL),
(103, 'Thadius Omondi', 'payables@kimfay.com', 'Sales Operations', 13, 'member', 'gap', NULL, 'both', 'deny_all', 0, NULL, 1, NULL, NULL, 'P476', 'P476', NULL, NULL, '2026-07-14 01:54:52', 1, '$2y$12$ccU8nvltOdHXxHZN4AAv2OgbgweBd1yso9N/XJQJwDdwvACxM1p2e', '2026-07-10 03:34:18', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:18', '2026-07-10 03:34:18', NULL),
(104, 'Timothy Piwa', 'salesmanager.ug@kimfay.com', 'Sales Operations', 1, 'member', 'sales', 76, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P431', 'P431', 'Consumer Sales Manager', 'Export Sales', '2026-07-14 01:54:52', 1, '$2y$12$0Jb6yidFvsNMo7FlgmNtYenaeBP7gEMne.yajl1baL3ItrVluw4ZO', '2026-07-10 03:34:18', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:18', '2026-07-10 03:34:18', NULL),
(105, 'Victor Ochieng Wamira', 'chiefaccountant@kimfay.com', 'Sales Operations', 13, 'hod', 'hod', 91, 'both', 'deny_all', 0, NULL, 1, NULL, NULL, 'P030', 'P030', 'Chief Accountant', 'Accounts', '2026-07-14 01:54:52', 1, '$2y$12$IHDRZb9BxK51KMIerVE7eOpzkCfnIJSYuDAyWSc6/Lzhhib3p66Li', '2026-07-10 03:34:19', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:19', '2026-07-10 03:34:19', NULL),
(106, 'Vincent Matheka Musyoka', 'dispatch@kimfay.com', 'Sales Operations', 13, 'hod', 'hod', 91, 'both', 'scoped', 0, NULL, 1, NULL, NULL, 'P277', 'P277', 'Dispatch Manager', 'Dispatch', '2026-07-14 01:54:52', 1, '$2y$12$n.Z6YYJOkFv5gVWT81rT2uLYHTrEz7tVnx6/KjAvCXCiAC5oNbas2', '2026-07-10 04:11:53', 0, NULL, NULL, 0, 0, '2026-07-10 03:34:19', '2026-07-10 04:11:53', NULL),
(129, 'Savi', 'savigudka@gmail.com', 'Administrator', NULL, 'member', 'sales', NULL, 'both', 'scoped', 0, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-24 10:59:11', 1, '$2y$12$ES6s8cHLe7Qw1v6mrrKst.Eg/pYm8RXPJFAXv9UrFKQOhQRS/rAKi', '2026-07-24 10:59:11', 0, NULL, NULL, 0, 0, '2026-07-24 10:59:11', '2026-07-24 10:59:11', NULL),
(130, 'Savi', 'titusk.mutiso@gmail.com', 'Administrator', NULL, 'member', 'sales', NULL, 'both', 'scoped', 0, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-24 11:13:06', 1, '$2y$12$0BsRxX5r.vFYolu6G0clD.FzsP2w105a.BAOJr0GLhh51HykkXfiO', '2026-07-24 11:13:07', 0, NULL, NULL, 0, 0, '2026-07-24 11:13:07', '2026-07-24 11:13:07', NULL),
(131, 'Savi', 'sparkworldke@gmail.com', 'Administrator', NULL, 'member', 'sales', NULL, 'both', 'scoped', 0, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-24 11:39:47', 1, '$2y$12$wqAmaI2SqyrXaNHjm1P9uOA33Udlm2bTF0Lf9O.RlSguUVDKBBqc6', '2026-07-24 11:39:48', 0, NULL, NULL, 0, 0, '2026-07-24 11:39:48', '2026-07-24 11:39:48', NULL),
(132, 'Savi', 'sparkappske@gmail.com', 'Administrator', NULL, 'member', 'sales', NULL, 'both', 'scoped', 0, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-24 11:40:48', 1, '$2y$12$WViixxS1CKtdr1El1JlKDeslD.EsMOZgTnWs6Z8wZXPAilpP2NMJq', '2026-07-24 11:40:48', 0, NULL, NULL, 0, 0, '2026-07-24 11:40:48', '2026-07-24 11:40:48', NULL),
(133, 'Pricillah Njeri Gichuhi', 'brandoperations@kimfay.com', 'Sales Operations', 12, 'member', 'brandsops', 68, 'trading', 'scoped', 0, NULL, 0, NULL, NULL, 'P506', 'P506', 'Brand Operations Manager - KC, Bio & Duracell', 'Partner Brands', '2026-07-31 13:29:19', 1, '$2y$12$DeX359woUZzQ9Yw5PYFTZ.8deS1rNJXorLmrtJXTbmLwiLH7yvyDW', NULL, 0, NULL, NULL, 0, 0, '2026-07-31 13:29:19', '2026-07-31 13:29:19', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_email_unique` (`email`),
  ADD KEY `users_rep_code_index` (`rep_code`),
  ADD KEY `users_employee_number_index` (`employee_number`),
  ADD KEY `users_department_id_foreign` (`department_id`),
  ADD KEY `users_reports_to_user_id_index` (`reports_to_user_id`),
  ADD KEY `users_org_level_index` (`org_level`),
  ADD KEY `users_trained_by_foreign` (`trained_by`),
  ADD KEY `users_trained_at_index` (`trained_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=134;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `users_reports_to_user_id_foreign` FOREIGN KEY (`reports_to_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `users_trained_by_foreign` FOREIGN KEY (`trained_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
