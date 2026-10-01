-- MySQL dump 10.13  Distrib 8.0.44, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: catt_prottocol
-- ------------------------------------------------------
-- Server version	5.5.5-10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `calendario`
--

DROP TABLE IF EXISTS `calendario`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `calendario` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `fecha_fin` date DEFAULT NULL,
  `actividad` varchar(180) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `profesor_id` int(10) unsigned NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_calendario_profesor` (`profesor_id`),
  KEY `idx_calendario_fecha` (`fecha`),
  CONSTRAINT `fk_calendario_profesor` FOREIGN KEY (`profesor_id`) REFERENCES `usuario` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `calendario`
--

LOCK TABLES `calendario` WRITE;
/*!40000 ALTER TABLE `calendario` DISABLE KEYS */;
INSERT INTO `calendario` VALUES (3,'2026-07-15','2026-09-04','Modificaciones al protocolo','15 de julio al 4 de septiembre de 2026',1,'2026-09-06 01:49:04'),(4,'2026-08-25',NULL,'Plática informativa (TT-I, TT-II y TTR 2027/1)',NULL,1,'2026-09-06 01:49:04'),(5,'2026-09-14',NULL,'Publicación de grupos',NULL,1,'2026-09-06 01:49:04'),(6,'2026-10-22',NULL,'Publicación calendario de presentaciones','Reprogramación por formato',1,'2026-09-06 01:49:04'),(7,'2026-10-29','2026-11-13','Presentación ordinaria del trabajo terminal (TT-I)','29 de octubre al 13 de noviembre de 2026',1,'2026-09-06 01:49:04'),(8,'2026-11-17','2026-12-01','Presentación ordinaria del trabajo terminal (TT-II)','17 de noviembre al 1 de diciembre de 2026',1,'2026-09-06 01:49:04'),(9,'2026-12-04','2026-12-08','Presentación extraordinaria del trabajo terminal I y II','4, 7 y 8 de diciembre de 2026',1,'2026-09-06 01:49:04'),(10,'2026-12-11',NULL,'Entrega de calificaciones finales ordinarias CATT',NULL,1,'2026-09-06 01:49:04'),(11,'2026-12-11','2026-12-15','Captura de calificaciones finales ordinarias docentes titulares','11, 14 y 15 de diciembre de 2026',1,'2026-09-06 01:49:04'),(12,'2026-10-28',NULL,'Entrega de calificaciones ordinarias de seguimiento TT-I',NULL,1,'2026-09-06 01:49:04'),(13,'2026-11-13',NULL,'Entrega de calificaciones ordinarias de seguimiento TT-II',NULL,1,'2026-09-06 01:49:04'),(14,'2026-11-13',NULL,'Entrega de calificaciones extraordinarias de seguimiento TT-I',NULL,1,'2026-09-06 01:49:04'),(15,'2026-12-01',NULL,'Entrega de calificaciones extraordinarias de seguimiento TT-II',NULL,1,'2026-09-06 01:49:04'),(16,'2026-12-16',NULL,'Entrega de calificaciones finales extraordinarias CATT',NULL,1,'2026-09-06 01:49:04'),(17,'2026-01-14','2026-01-16','Captura de calificaciones finales extraordinarias docentes titulares','14 al 16 de enero de 2026',1,'2026-09-06 01:49:04'),(18,'2026-11-03','2026-11-30','Apertura de Select (plan 2009) para ELECTIVA','3 al 30 de noviembre de 2026',1,'2026-09-06 01:49:04'),(19,'2026-09-07',NULL,'Publicación de propuestas de protocolo (A cursar en 2027-2)',NULL,1,'2026-09-06 01:49:04'),(20,'2026-09-11',NULL,'Plática informativa (A cursar en 2027-2)',NULL,1,'2026-09-06 01:49:04'),(21,'2026-09-21','2026-09-25','Registro de protocolos','21 al 25 de septiembre de 2026',1,'2026-09-06 01:49:04'),(22,'2026-10-08','2026-10-14','Asignación de sinodales','8 al 14 de octubre de 2026',1,'2026-09-06 01:49:04'),(23,'2026-10-19','2026-10-23','Primera evaluación de protocolos','19 al 23 de octubre de 2026',1,'2026-09-06 01:49:04'),(24,'2026-10-28',NULL,'Envío de dictámenes de la evaluación',NULL,1,'2026-09-06 01:49:04'),(25,'2026-11-03','2026-11-09','Registro de protocolos reestructurados para segunda evaluación','3 al 9 de noviembre de 2026',1,'2026-09-06 01:49:04'),(26,'2026-11-10','2026-11-17','Segunda evaluación de protocolos','10 al 17 de noviembre de 2026',1,'2026-09-06 01:49:04'),(27,'2026-11-20',NULL,'Entrega de dictámenes reestructurados',NULL,1,'2026-09-06 01:49:04'),(28,'2026-12-07',NULL,'Publicación de propuestas de protocolo (A recursar en 2027-2 TTR)',NULL,1,'2026-09-06 01:49:04'),(29,'2026-12-14','2026-12-18','Registro de protocolos (TTR)','14 al 18 de diciembre de 2026',1,'2026-09-06 01:49:04'),(30,'2027-01-08',NULL,'Junta con la CATT para asignación de sinodales (TTR)',NULL,1,'2026-09-06 01:49:04'),(31,'2027-01-11','2027-01-15','Asignación de sinodales (TTR)','11 al 15 de enero de 2027',1,'2026-09-06 01:49:04'),(32,'2027-01-18','2027-01-22','Primera evaluación de protocolos (TTR)','18 al 22 de enero de 2027',1,'2026-09-06 01:49:04'),(33,'2027-01-27',NULL,'Envío de dictámenes de la evaluación (TTR)',NULL,1,'2026-09-06 01:49:04'),(34,'2027-01-28','2027-02-04','Registro de protocolos reestructurados para segunda evaluación (TTR)','28 de enero al 4 de febrero de 2027',1,'2026-09-06 01:49:04'),(35,'2027-02-08','2027-02-12','Segunda evaluación de protocolos (TTR)','8 al 12 de febrero de 2027',1,'2026-09-06 01:49:04'),(36,'2027-02-15',NULL,'Entrega de dictámenes reestructurados (TTR)',NULL,1,'2026-09-06 01:49:04');
/*!40000 ALTER TABLE `calendario` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `usuario`
--

DROP TABLE IF EXISTS `usuario`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `usuario` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(60) NOT NULL,
  `apellido_paterno` varchar(60) NOT NULL,
  `apellido_materno` varchar(60) DEFAULT NULL,
  `correo` varchar(160) NOT NULL,
  `noBoleta` varchar(30) DEFAULT NULL,
  `numero_empleado` varchar(30) DEFAULT NULL,
  `rol` enum('estudiante','profesor','miembroCatt') NOT NULL DEFAULT 'estudiante',
  `password_hash` varchar(255) NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `correo` (`correo`),
  UNIQUE KEY `noBoleta` (`noBoleta`),
  UNIQUE KEY `numero_empleado` (`numero_empleado`),
  KEY `idx_usuario_rol` (`rol`),
  CONSTRAINT `chk_usuario_identificador` CHECK (`rol` = 'estudiante' and `noBoleta` is not null and `numero_empleado` is null or `rol` in ('profesor','miembroCatt') and `numero_empleado` is not null and `noBoleta` is null)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `usuario`
--

LOCK TABLES `usuario` WRITE;
/*!40000 ALTER TABLE `usuario` DISABLE KEYS */;
INSERT INTO `usuario` VALUES (1,'Profesor','CATT',NULL,'profesor@catt.local',NULL,'PROF-001','profesor','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llCq.D7k2lP8bKx9kqZy','2026-09-06 01:28:44'),(2,'Frida','Guerrero Camacho',NULL,'fguerreroc1900@alumno.ipn.mx','2023630831',NULL,'estudiante','$2y$10$o1VW/RW5lTGH0tQlSaZNqed03YGT2H3qPi4hnPHhRNisMTdXEZOd.','2026-09-06 01:34:48');
/*!40000 ALTER TABLE `usuario` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-01  8:12:59
