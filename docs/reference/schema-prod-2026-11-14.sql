-- Schéma de production (structure seule, sans données) — 2026-11-14
-- Base : registrar_db

CREATE TABLE `bk_notes_zero` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ancienne_note` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `candidat` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nom_complet` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `nom` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nom de famille',
  `prenom` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Prenom(s)',
  `date_naissance` date DEFAULT NULL COMMENT 'Date complete si connue',
  `annee_naissance` varchar(4) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Annee seule si date inconnue',
  `lieu_naissance` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `genre` enum('M','F') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `etat_civil` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Celibataire, marie(e)...',
  `pays_origine` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nationalite` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ville` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `adresse` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nom_pere` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nom_mere` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_parent` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tuteur` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_tuteur` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telephone` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mention` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mention_libelle` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `diplome_vise` enum('licence','master') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parcours` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Parcours au sein de la mention',
  `annee_bacc` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `statut` enum('nouveau','en_cours','accepte','refuse','converti') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'nouveau',
  `student_id` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `traite_par` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note_interne` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_traitement` datetime DEFAULT NULL,
  `archive_le` datetime DEFAULT NULL COMMENT 'Date de mise en archive. NULL = dossier actif',
  `archive_par` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Agent ayant archive le dossier',
  `notifie_le` datetime DEFAULT NULL COMMENT 'Dernier envoi au candidat (decision)',
  `notifie_sms_le` datetime DEFAULT NULL COMMENT 'Dernier SMS envoye au candidat (decision)',
  PRIMARY KEY (`id`),
  KEY `idx_statut` (`statut`),
  KEY `idx_date` (`date_creation`),
  KEY `idx_student` (`student_id`),
  KEY `idx_archive` (`archive_le`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `checklist` (
  `id` int NOT NULL AUTO_INCREMENT,
  `Sigle` varchar(20) DEFAULT NULL,
  `Title` varchar(200) DEFAULT NULL,
  `Credit` int DEFAULT '1',
  `Semester` int DEFAULT '1',
  `Departement` varchar(4) NOT NULL COMMENT 'THEO - Théologie\nGEST - Gestion\nINFO - Informatique\nNURS - Sciences Infirmières\nCOMM - Arts, Lettres et Sciences Humaines\nEDUC - Education',
  `Type_cours` int NOT NULL DEFAULT '0' COMMENT '0 - General course\n1 - Majour course\n2 - Elective course',
  `parcours` varchar(20) NOT NULL,
  `Date_entry` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_change_user_id` varchar(50) DEFAULT NULL,
  `last_change_datetime` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`,`Departement`),
  KEY `FK_checklist_filiere_parcours_shortcode` (`parcours`),
  CONSTRAINT `FK_checklist_filiere_parcours_shortcode` FOREIGN KEY (`parcours`) REFERENCES `filiere_parcours` (`shortcode`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `class` (
  `class_id` int NOT NULL AUTO_INCREMENT,
  `name` longtext CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `name_numeric` longtext CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `teacher_id` int NOT NULL,
  `last_change_user_id` varchar(50) DEFAULT NULL,
  `last_change_datetime` datetime DEFAULT NULL,
  PRIMARY KEY (`class_id`),
  KEY `Teacher_fk_idx` (`teacher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 AVG_ROW_LENGTH=1820;

CREATE TABLE `comity_members` (
  `id` int NOT NULL AUTO_INCREMENT,
  `fullName` char(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `position` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `groupe` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `active` tinyint(1) DEFAULT '0',
  `date_entry` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `communes` (
  `id_commune` int NOT NULL AUTO_INCREMENT,
  `id_district` int DEFAULT NULL,
  `id_region` int DEFAULT NULL,
  `id_faritany` int DEFAULT NULL,
  `name` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id_commune`),
  KEY `FK_communes_districts_id_district` (`id_district`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='Communes de Madagascar';

CREATE TABLE `compt_utilisateur` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `prenom` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `post` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `pseudo` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `mail` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `level` int NOT NULL,
  `password` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `privilege` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `photos` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `etat` int NOT NULL,
  `theme` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `update_user` int NOT NULL,
  `date_entry` date NOT NULL,
  `student_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Lien vers student_id si utilisateur est étudiant',
  `teacher_uid` int DEFAULT NULL COMMENT 'Lien vers teacher.uid si utilisateur est professeur',
  `user_type` enum('superadmin','admin','registrar','comptabilite','media','chef_mention','teacher','student','work_education') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'admin' COMMENT 'Type d utilisateur',
  `perimetre_cycle` enum('L','M') COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Cycle visible : L (Licence), M (Master), NULL = tout (data/perimetre.php)',
  `perimetre_mention` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Mention d un chef de mention : sa liste des etudiants (data/perimetre.php)',
  PRIMARY KEY (`id`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_teacher_uid` (`teacher_uid`),
  KEY `idx_user_type` (`user_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `coordinates` (
  `id_region` int NOT NULL AUTO_INCREMENT,
  `id_province` int DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `latitude` decimal(11,8) DEFAULT NULL,
  `longitude` decimal(10,8) DEFAULT NULL,
  PRIMARY KEY (`id_region`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='Coordonn';

CREATE TABLE `cours` (
  `id_cours` int NOT NULL AUTO_INCREMENT,
  `Sigle` varchar(45) NOT NULL COMMENT 'Sigle du cours',
  `title` varchar(200) DEFAULT NULL,
  `title_english` varchar(200) DEFAULT NULL,
  `Description` text COMMENT 'description du cours',
  `nb_crd` int DEFAULT NULL COMMENT 'Nombre de crédit',
  `Remark` varchar(255) DEFAULT NULL COMMENT 'Remarque',
  `dep_desc` char(4) NOT NULL COMMENT 'Département',
  `category` int DEFAULT '0' COMMENT '0 - Cours général\n1 - Cours majeur',
  `id_teacher` int NOT NULL COMMENT 'Identification de l''enseignant',
  `semester` int NOT NULL DEFAULT '1' COMMENT 'Semestre',
  `lab` int NOT NULL DEFAULT '0' COMMENT 'Avec lab ou pas (0 - no lab, 1 - Lab info, 2 - Lab langue)',
  `yearlevel` int DEFAULT '1' COMMENT 'Cours disponible pour l''année d''étude',
  `active` int DEFAULT '0',
  `dep_option` varchar(255) DEFAULT NULL,
  `new_course` int DEFAULT '0',
  `new_version` int DEFAULT '1',
  `link_id_cours` int DEFAULT NULL,
  `new_sigle` varchar(20) DEFAULT NULL,
  `parcours` varchar(20) DEFAULT 'TC',
  `date_entry` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_change_user_id` varchar(50) DEFAULT NULL,
  `last_change_datetime` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_cours`,`Sigle`,`dep_desc`,`id_teacher`),
  KEY `id_cours` (`id_cours`),
  KEY `FK_cours_teacher_uid` (`id_teacher`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 AVG_ROW_LENGTH=245;

CREATE TABLE `cours_transcript` (
  `Sigle` varchar(40) NOT NULL,
  `Title` varchar(200) DEFAULT NULL,
  `Description` text,
  `Nb_credit` int DEFAULT '3',
  `departement` varchar(255) NOT NULL,
  `course_category` int NOT NULL DEFAULT '0',
  `date_entry` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_change_user_id` varchar(50) DEFAULT NULL,
  `last_change_datetime` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`Sigle`,`departement`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 AVG_ROW_LENGTH=8192;

CREATE TABLE `coursession` (
  `id_cours` int NOT NULL,
  `options` varchar(45) NOT NULL,
  `annee_etude` varchar(45) NOT NULL,
  `credit` varchar(45) DEFAULT NULL,
  `lab` varchar(45) DEFAULT NULL,
  `dep_desc` varchar(45) NOT NULL,
  `session` int DEFAULT NULL,
  `date_entry` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_change_user_id` varchar(50) DEFAULT NULL,
  `last_change_datetime` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_cours`,`options`,`annee_etude`,`dep_desc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 AVG_ROW_LENGTH=108;

CREATE TABLE `diplome_arretes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `programme` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `accronyme` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `arrete_mesupres` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `arrete_cneat` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `last_change_user_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `last_change_datetime` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_programme` (`programme`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `districts` (
  `id_district` int NOT NULL AUTO_INCREMENT,
  `id_region` int DEFAULT NULL,
  `id_faritany` int DEFAULT NULL,
  `name` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id_district`),
  KEY `FK_districts_coordinates_id_region` (`id_region`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='District de Madagascar';

CREATE TABLE `employees` (
  `emp_no` varchar(10) NOT NULL,
  `last_name` varchar(150) DEFAULT NULL,
  `first_name` varchar(200) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `position` varchar(150) DEFAULT NULL,
  `position_fr` varchar(150) DEFAULT NULL,
  `type` varchar(30) DEFAULT NULL,
  `images` varchar(255) DEFAULT NULL,
  `date_entry` datetime DEFAULT CURRENT_TIMESTAMP,
  `user_id` int DEFAULT NULL,
  `active` int DEFAULT '0',
  PRIMARY KEY (`emp_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='Employés';

CREATE TABLE `etudiant` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` int DEFAULT NULL,
  `student_nom` varchar(50) NOT NULL,
  `student_prenom` varchar(100) DEFAULT NULL,
  `student_adresse` varchar(150) DEFAULT NULL,
  `student_region` varchar(255) DEFAULT NULL,
  `sex` int DEFAULT '0',
  `student_email` varchar(55) DEFAULT NULL,
  `dateNaissance` date DEFAULT NULL,
  `lieuNaissance` varchar(150) DEFAULT NULL,
  `student_tel` varchar(50) DEFAULT NULL,
  `religion` varchar(30) DEFAULT NULL,
  `etat_civil` int DEFAULT '0',
  `nom_conjoint` varchar(50) DEFAULT NULL,
  `nb_enfant` int DEFAULT '1',
  `father_name` varchar(100) DEFAULT NULL,
  `father_prof` varchar(40) DEFAULT NULL,
  `mother_name` varchar(100) DEFAULT NULL,
  `mother_prof` varchar(40) DEFAULT NULL,
  `parent_adresse` varchar(100) DEFAULT NULL,
  `parent_tel` varchar(50) DEFAULT NULL,
  `nationalite` varchar(30) DEFAULT NULL,
  `num_cin` varchar(20) DEFAULT NULL,
  `pays_origine` varchar(30) DEFAULT NULL,
  `cin_date_delivre` datetime DEFAULT NULL,
  `cin_region` varchar(30) DEFAULT NULL,
  `num_visa` varchar(20) DEFAULT NULL,
  `pers_contact_name` varchar(100) DEFAULT NULL,
  `pers_adresse` varchar(100) DEFAULT NULL,
  `pers_tel` varchar(50) DEFAULT NULL,
  `etude_envisage` varchar(100) DEFAULT NULL,
  `etude_option` varchar(150) DEFAULT NULL,
  `sponsor_nom` varchar(50) DEFAULT NULL,
  `sponsor_prenom` varchar(100) DEFAULT NULL,
  `sponsor_adresse` varchar(100) DEFAULT NULL,
  `sponsor_tel` varchar(50) DEFAULT NULL,
  `sponsor_dure_f` varchar(100) DEFAULT NULL,
  `externe` tinyint(1) DEFAULT NULL,
  `image_student` varchar(255) DEFAULT NULL,
  `etat` int NOT NULL DEFAULT '0',
  `image` mediumblob,
  `lookup_code` varchar(13) DEFAULT NULL,
  `annee_etude` int DEFAULT '1',
  `graduated` tinyint(1) DEFAULT '0',
  `graduation_date` datetime DEFAULT NULL,
  `bursary_status` int DEFAULT '1',
  `new_student` int DEFAULT '0',
  `type_retrait` int DEFAULT '0' COMMENT '0 - Active, 1 - Retrait momentannée, 2 - Retrait definitif, 3 - Changer de majeur, 4 - Gradué(e)',
  `retrait_date` datetime DEFAULT NULL,
  `champ` varchar(20) DEFAULT NULL,
  `bacc_serie` char(10) DEFAULT NULL,
  `date_entry` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_change_user_id` varchar(50) DEFAULT NULL,
  `last_change_datetime` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UK_etudiant_student_id` (`student_id`),
  KEY `index_id` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 AVG_ROW_LENGTH=944;

CREATE TABLE `exam_slip` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` int NOT NULL,
  `student_nom` varchar(50) NOT NULL,
  `student_prenom` varchar(100) DEFAULT NULL,
  `student_adresse` varchar(150) DEFAULT NULL,
  `sex` int DEFAULT '0',
  `dateNaissance` date DEFAULT NULL,
  `lieuNaissance` varchar(30) DEFAULT NULL,
  `student_tel` varchar(50) DEFAULT NULL,
  `religion` varchar(30) DEFAULT NULL,
  `etat_civil` int DEFAULT '0',
  `nom_conjoint` varchar(50) DEFAULT NULL,
  `nb_enfant` int DEFAULT '1',
  `father_name` varchar(100) DEFAULT NULL,
  `father_prof` varchar(40) DEFAULT NULL,
  `mother_name` varchar(100) DEFAULT NULL,
  `mother_prof` varchar(40) DEFAULT NULL,
  `parent_adresse` varchar(100) DEFAULT NULL,
  `parent_tel` varchar(50) DEFAULT NULL,
  `nationalite` varchar(30) DEFAULT NULL,
  `num_cin` varchar(20) DEFAULT NULL,
  `pays_origine` varchar(30) DEFAULT NULL,
  `cin_date_delivre` date DEFAULT NULL,
  `cin_region` varchar(30) DEFAULT NULL,
  `num_visa` varchar(20) DEFAULT NULL,
  `pers_contact_name` varchar(100) DEFAULT NULL,
  `pers_adresse` varchar(100) DEFAULT NULL,
  `pers_tel` varchar(50) DEFAULT NULL,
  `etude_envisage` varchar(100) DEFAULT NULL,
  `etude_option` varchar(50) DEFAULT NULL,
  `sponsor_nom` varchar(50) DEFAULT NULL,
  `sponsor_prenom` varchar(100) DEFAULT NULL,
  `sponsor_adresse` varchar(100) DEFAULT NULL,
  `sponsor_tel` varchar(50) DEFAULT NULL,
  `sponsor_dure_f` varchar(15) DEFAULT NULL,
  `externe` tinyint(1) DEFAULT NULL,
  `image_student` varchar(255) DEFAULT NULL,
  `etat` int NOT NULL DEFAULT '0',
  `image` mediumblob,
  `lookup_code` varchar(13) NOT NULL,
  `ACCESS` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`student_id`),
  UNIQUE KEY `uk_exam_slip_id` (`id`),
  KEY `student_id` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 AVG_ROW_LENGTH=952;

CREATE TABLE `fee_struct` (
  `id` int NOT NULL AUTO_INCREMENT,
  `designation` varchar(200) DEFAULT NULL,
  `detail` int DEFAULT '1',
  `montant` double DEFAULT '0',
  `remarque` varchar(200) DEFAULT NULL,
  `semestre` int DEFAULT '1' COMMENT '[1-permier semestre][2-deuxième semestre][3-inter semestre][4-premier et deuxième semestre]',
  `type` varchar(255) DEFAULT NULL,
  `index_type` int DEFAULT '0',
  `date_entry` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_change_user_id` varchar(50) DEFAULT NULL,
  `last_change_datetime` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 AVG_ROW_LENGTH=963;

CREATE TABLE `filiere` (
  `filiere_id` int NOT NULL AUTO_INCREMENT,
  `filiere_sigle` varchar(4) DEFAULT NULL,
  `filiere_description` varchar(100) DEFAULT NULL,
  `filiere_description_ang` varchar(255) NOT NULL,
  `domaine_signe` varchar(10) NOT NULL,
  `domaine_name` varchar(200) NOT NULL,
  `last_change_user_id` varchar(50) DEFAULT NULL,
  `last_change_datetime` datetime DEFAULT CURRENT_TIMESTAMP,
  `ouverte` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`filiere_id`),
  UNIQUE KEY `UK_filiere_filiere_sigle` (`filiere_sigle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `filiere_parcours` (
  `id` int NOT NULL AUTO_INCREMENT,
  `shortcode` varchar(20) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `description_ang` varchar(255) NOT NULL,
  `departement` varchar(15) DEFAULT NULL,
  `cycles` varchar(10) NOT NULL DEFAULT 'L,M',
  `date_entry` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_change_user_id` varchar(50) DEFAULT NULL,
  `last_change_datetime` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`shortcode`),
  UNIQUE KEY `uk_filiere_parcours_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `finance` (
  `student_id` int NOT NULL,
  `session_id` int NOT NULL DEFAULT '21',
  `std_etude` varchar(40) DEFAULT NULL COMMENT 'Etude ou filière envisagé',
  `residence` int DEFAULT '0' COMMENT 'Résidence: 0 - interne, 1 - externe',
  `std_yearlevel` int DEFAULT '1',
  `semester` int DEFAULT '1',
  `new_student` int DEFAULT '0',
  `encadrement` int DEFAULT '0',
  `graduated` int DEFAULT '0' COMMENT 'Gradué: 0 - non, 1 - oui',
  `total_credit` int DEFAULT '1',
  `lab_info` int DEFAULT '0' COMMENT 'if lab info exists',
  `lab_langue` int DEFAULT '0' COMMENT 'if lab langue exists',
  `plan` char(1) DEFAULT 'A' COMMENT 'Le plan choisi',
  `total_payment` varchar(40) DEFAULT '0',
  `amount_pay_1` varchar(50) DEFAULT NULL,
  `amount_pay_2` varchar(50) DEFAULT NULL,
  `amount_pay_3` varchar(50) DEFAULT NULL,
  `amount_pay_4` varchar(50) DEFAULT NULL,
  `amount_pay_5` varchar(50) DEFAULT NULL,
  `balance_amount` decimal(15,2) DEFAULT NULL COMMENT 'Solde restant = total_payment - sommes des tranches payees',
  `date_payment_1` varchar(255) DEFAULT NULL,
  `date_payment_2` varchar(255) DEFAULT NULL,
  `date_payment_3` varchar(255) DEFAULT NULL,
  `date_payment_4` varchar(255) DEFAULT NULL,
  `date_payment_5` varchar(255) DEFAULT NULL,
  `retard` int DEFAULT '0',
  `date_entry` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_change_user_id` varchar(50) DEFAULT NULL,
  `last_change_datetime` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`student_id`,`session_id`),
  KEY `session_id_fk_idx` (`session_id`),
  KEY `idx_finance_session_student` (`session_id`,`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 AVG_ROW_LENGTH=301;

CREATE TABLE `finance_plan` (
  `Id` int NOT NULL AUTO_INCREMENT,
  `category` varchar(255) DEFAULT 'Plan A',
  `percentage` varchar(255) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `payment` date DEFAULT NULL,
  `date_entry` datetime DEFAULT CURRENT_TIMESTAMP,
  `type` varchar(255) DEFAULT NULL,
  `last_change_user_id` varchar(50) DEFAULT NULL,
  `last_change_datetime` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`Id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 AVG_ROW_LENGTH=1260;

CREATE TABLE `general_fee` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code_filiere` varchar(255) DEFAULT NULL,
  `year_level` int DEFAULT '1',
  `amount` double DEFAULT '0',
  `nb_days` int DEFAULT '1',
  `session_id` int DEFAULT NULL,
  `residence` int DEFAULT '0',
  `date_entry` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC;

CREATE TABLE `grade_worked_chapelle` (
  `student_id` int NOT NULL,
  `session_id` int NOT NULL,
  `work_ed` varchar(25) NOT NULL,
  `note_worked` double DEFAULT '20',
  `note_chapelle` double DEFAULT '20',
  `yearlevel` int DEFAULT NULL,
  `date_entry` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_change_user_id` varchar(50) DEFAULT NULL,
  `last_change_datetime` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`student_id`,`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='Notes pour Chapelle et Work Education';

CREATE TABLE `graduating_std` (
  `student_id` int NOT NULL,
  `session_id` int NOT NULL,
  `habilitation` text,
  `year_level` int DEFAULT '3',
  `final_gpa` double NOT NULL DEFAULT '0',
  `mention` varchar(255) DEFAULT NULL,
  `diploma_printed` tinyint DEFAULT '0',
  `graduation_date` datetime DEFAULT NULL,
  `date_entry` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_change_user_id` varchar(50) DEFAULT NULL,
  `last_change_datetime` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`student_id`,`session_id`),
  KEY `session_id_fk_idx` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 AVG_ROW_LENGTH=135 COMMENT='Temporary Table for graduating students';

CREATE TABLE `head_account` (
  `head_id` varchar(200) NOT NULL,
  `head_name` varchar(100) NOT NULL,
  `head_password` varchar(255) DEFAULT NULL,
  `email` varchar(200) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `departement` varchar(50) DEFAULT NULL,
  `permission` int NOT NULL DEFAULT '3',
  `date_entry` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_change_user_id` varchar(50) DEFAULT NULL,
  `last_change_datetime` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`head_id`,`permission`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 AVG_ROW_LENGTH=819;

CREATE TABLE `images` (
  `user_id` int NOT NULL,
  `user_image` mediumblob,
  `date_entry` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_change_user_id` varchar(50) DEFAULT NULL,
  `last_change_datetime` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `important_dates` (
  `designation` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `date` date DEFAULT NULL,
  `session_id` int DEFAULT '0',
  PRIMARY KEY (`designation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `inscription` (
  `idnumber` int NOT NULL,
  `idcourse` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`idnumber`,`idcourse`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `login_locations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `latitude` decimal(10,8) DEFAULT NULL COMMENT 'Latitude GPS',
  `longitude` decimal(11,8) DEFAULT NULL COMMENT 'Longitude GPS',
  `accuracy` decimal(10,2) DEFAULT NULL COMMENT 'Précision en mètres',
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Adresse IP de connexion',
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Navigateur utilisé',
  `login_method` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'password' COMMENT 'Méthode: password, google',
  `city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Ville (optionnel, rempli par géocodage inverse)',
  `country` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Pays (optionnel)',
  `gps_denied` tinyint(1) DEFAULT '0' COMMENT '1 si l''utilisateur a refusé la géolocalisation',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_location` (`latitude`,`longitude`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `mark` (
  `mark_id` int NOT NULL AUTO_INCREMENT,
  `student_id` int NOT NULL,
  `subject_id` int NOT NULL,
  `class_id` int NOT NULL,
  `exam_id` int NOT NULL,
  `mark_obtained` double DEFAULT NULL,
  `mark_total` double DEFAULT NULL,
  `comment` longtext CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `semester` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `year` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `crd` int DEFAULT NULL,
  `date_entry` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_change_user_id` varchar(50) DEFAULT NULL,
  `date_modified` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `session_id` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`mark_id`,`session_id`),
  KEY `id_class` (`class_id`),
  KEY `id_std` (`student_id`),
  KEY `id_subject` (`subject_id`),
  KEY `session_id_markfk_idx` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 AVG_ROW_LENGTH=129;

CREATE TABLE `msg_blocage` (
  `user_id` int NOT NULL COMMENT 'Celui qui bloque',
  `bloque_id` int NOT NULL COMMENT 'Celui qui est bloque',
  `le` datetime NOT NULL,
  PRIMARY KEY (`user_id`,`bloque_id`),
  KEY `idx_bloque` (`bloque_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Messagerie : personnes bloquees (discussions a deux)';

CREATE TABLE `msg_conversation` (
  `id` int NOT NULL AUTO_INCREMENT,
  `type` enum('direct','groupe') COLLATE utf8mb4_general_ci NOT NULL,
  `nom` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Groupe seulement',
  `cle_directe` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `cree_par` int NOT NULL COMMENT 'compt_utilisateur.id',
  `cree_le` datetime NOT NULL,
  `dernier_message_id` int DEFAULT NULL,
  `dernier_le` datetime(3) DEFAULT NULL COMMENT 'Ordre de la liste des discussions',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_directe` (`cle_directe`),
  KEY `idx_dernier` (`dernier_le`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Messagerie : une discussion a deux ou un groupe';

CREATE TABLE `msg_evenement` (
  `id` int NOT NULL AUTO_INCREMENT,
  `conversation_id` int NOT NULL,
  `type` enum('message','modif','lu','conversation') COLLATE utf8mb4_general_ci NOT NULL,
  `message_id` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `le` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_conv` (`conversation_id`,`id`),
  KEY `idx_le` (`le`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Messagerie : ce qui a change, pour les navigateurs ouverts';

CREATE TABLE `msg_fichier` (
  `id` int NOT NULL AUTO_INCREMENT,
  `proprietaire_id` int NOT NULL,
  `jeton` char(32) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Nom du fichier sur le disque',
  `nom` varchar(200) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Nom d''origine, nettoye',
  `mime` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `genre` enum('image','video','audio','fichier') COLLATE utf8mb4_general_ci NOT NULL,
  `taille` bigint unsigned NOT NULL,
  `recu` bigint unsigned NOT NULL DEFAULT '0' COMMENT 'Octets deja recus (envoi par morceaux)',
  `chemin` varchar(120) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Relatif a data/messagerie',
  `apercu` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Vignette des images',
  `largeur` smallint unsigned DEFAULT NULL,
  `hauteur` smallint unsigned DEFAULT NULL,
  `duree_ms` int unsigned DEFAULT NULL COMMENT 'Vocal ou video, mesure par le navigateur',
  `statut` enum('envoi','pret') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'envoi',
  `cree_le` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_jeton` (`jeton`),
  KEY `idx_proprietaire` (`proprietaire_id`,`cree_le`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Messagerie : fichiers envoyes (photos, videos, vocaux, documents)';

CREATE TABLE `msg_message` (
  `id` int NOT NULL AUTO_INCREMENT,
  `conversation_id` int NOT NULL,
  `auteur_id` int DEFAULT NULL COMMENT 'NULL pour un message du systeme',
  `type` enum('texte','image','video','audio','fichier','systeme') COLLATE utf8mb4_general_ci NOT NULL,
  `texte` text COLLATE utf8mb4_general_ci COMMENT 'Message, ou legende du fichier',
  `fichier_id` int DEFAULT NULL,
  `reponse_a` int DEFAULT NULL COMMENT 'Message cite',
  `systeme` varchar(40) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'groupe_cree, membres_ajoutes, membre_retire, membre_parti, groupe_renomme',
  `donnees` varchar(1000) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'JSON du message systeme',
  `envoye_le` datetime(3) NOT NULL,
  `retire_le` datetime DEFAULT NULL COMMENT 'Retire pour tout le monde',
  `retire_par` int DEFAULT NULL COMMENT 'L''auteur, ou le superadmin apres un signalement',
  `cle_client` char(36) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_client` (`auteur_id`,`cle_client`),
  KEY `idx_conv` (`conversation_id`,`id`),
  KEY `idx_auteur` (`auteur_id`,`envoye_le`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Messagerie : messages';

CREATE TABLE `msg_participant` (
  `conversation_id` int NOT NULL,
  `user_id` int NOT NULL COMMENT 'compt_utilisateur.id',
  `role` enum('membre','admin') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'membre',
  `ajoute_par` int DEFAULT NULL,
  `rejoint_le` datetime NOT NULL,
  `debut_message_id` int NOT NULL DEFAULT '0' COMMENT 'Voit les messages d''id superieur',
  `fin_message_id` int DEFAULT NULL COMMENT 'NULL = membre actuel',
  `lu_jusqu_a` int NOT NULL DEFAULT '0' COMMENT 'Dernier message lu (accuses de lecture)',
  `ecrit_le` datetime DEFAULT NULL COMMENT '« … est en train d''ecrire »',
  `efface_jusqu_a` int NOT NULL DEFAULT '0' COMMENT 'Discussion supprimee pour soi jusqu''a ce message',
  `sourdine` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`conversation_id`,`user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Messagerie : membres d''une discussion, et ou chacun en est';

CREATE TABLE `msg_presence` (
  `user_id` int NOT NULL,
  `vu_le` datetime NOT NULL,
  `regarde_le` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Messagerie : derniere activite, mise a jour au plus une fois par minute';

CREATE TABLE `msg_push_abonnement` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empreinte` char(64) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'sha256 de l adresse : une adresse = un navigateur',
  `utilisateur_id` int NOT NULL,
  `adresse` varchar(1000) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Adresse du service de push (Google, Apple, Mozilla)',
  `cle_p256dh` varchar(120) COLLATE utf8mb4_general_ci NOT NULL,
  `cle_auth` varchar(40) COLLATE utf8mb4_general_ci NOT NULL,
  `appareil` varchar(120) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `cree_le` datetime NOT NULL,
  `vu_le` datetime NOT NULL COMMENT 'Dernier envoi reussi ou dernier renouvellement',
  `echecs` tinyint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_empreinte` (`empreinte`),
  KEY `idx_utilisateur` (`utilisateur_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Messagerie : abonnements aux notifications push';

CREATE TABLE `msg_push_cle` (
  `id` tinyint NOT NULL,
  `publique` varchar(100) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'base64url, 65 octets',
  `privee_pem` text COLLATE utf8mb4_general_ci NOT NULL,
  `cree_le` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Messagerie : cle de signature des notifications push';

CREATE TABLE `msg_reaction` (
  `message_id` int NOT NULL,
  `user_id` int NOT NULL,
  `emoji` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `le` datetime NOT NULL,
  PRIMARY KEY (`message_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Messagerie : une reaction par personne et par message';

CREATE TABLE `msg_signalement` (
  `id` int NOT NULL AUTO_INCREMENT,
  `message_id` int NOT NULL,
  `signale_par` int NOT NULL,
  `motif` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `le` datetime NOT NULL,
  `statut` enum('ouvert','retire','rejete') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'ouvert',
  `traite_par` int DEFAULT NULL,
  `traite_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_signalement` (`message_id`,`signale_par`),
  KEY `idx_statut` (`statut`,`le`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Messagerie : messages signales au superadmin';

CREATE TABLE `names` (
  `id` int NOT NULL AUTO_INCREMENT,
  `names` char(250) DEFAULT NULL,
  `date` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `officersaccount` (
  `officer_id` varchar(50) NOT NULL,
  `officer_name` varchar(100) DEFAULT NULL,
  `position` varchar(50) DEFAULT NULL,
  `departement` varchar(30) DEFAULT NULL,
  `officers_password` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`officer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 AVG_ROW_LENGTH=1820;

CREATE TABLE `parcours` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nom` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mention_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `parcours_mention_id_foreign` (`mention_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `prohibited` (
  `id` int NOT NULL AUTO_INCREMENT,
  `Name` varchar(50) DEFAULT NULL,
  `stdFirstName` varchar(50) DEFAULT NULL,
  `SunPLusCode` varchar(255) DEFAULT NULL,
  `Departement` varchar(255) DEFAULT NULL,
  `Student_id` int DEFAULT NULL,
  `Balance` varchar(255) DEFAULT NULL,
  `Date` datetime DEFAULT NULL,
  `dateEntry` datetime DEFAULT NULL,
  `Access` tinyint DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `prohibited_ibfk` (`Student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 AVG_ROW_LENGTH=138 COMMENT='Temporary table';

CREATE TABLE `provinces` (
  `id_province` int DEFAULT NULL,
  `name` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='Provinces de Madagscar';

CREATE TABLE `region` (
  `id` int NOT NULL AUTO_INCREMENT,
  `region` varchar(50) DEFAULT NULL,
  `chefLieu` varchar(100) DEFAULT NULL,
  `province` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `remember_tokens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `token_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Token hashé en SHA-256',
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_token` (`user_id`),
  KEY `idx_token_hash` (`token_hash`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `rg_eval_answer_options` (
  `id` int NOT NULL AUTO_INCREMENT,
  `answer_id` int NOT NULL,
  `option_id` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `answer_id` (`answer_id`),
  KEY `option_id` (`option_id`),
  CONSTRAINT `rg_eval_answer_options_ibfk_1` FOREIGN KEY (`answer_id`) REFERENCES `rg_eval_answers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rg_eval_answer_options_ibfk_2` FOREIGN KEY (`option_id`) REFERENCES `rg_eval_options` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `rg_eval_answers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `response_id` int NOT NULL,
  `question_id` int NOT NULL,
  `answer_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `answer_number` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `response_id` (`response_id`),
  KEY `question_id` (`question_id`),
  CONSTRAINT `rg_eval_answers_ibfk_1` FOREIGN KEY (`response_id`) REFERENCES `rg_eval_responses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rg_eval_answers_ibfk_2` FOREIGN KEY (`question_id`) REFERENCES `rg_eval_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `rg_eval_forms` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `cours_id` int DEFAULT NULL,
  `session_id` int DEFAULT NULL,
  `is_published` tinyint(1) DEFAULT '0',
  `start_date` datetime DEFAULT NULL,
  `end_date` datetime DEFAULT NULL,
  `target_role` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'all',
  `limit_one_response` tinyint(1) DEFAULT '1',
  `created_by` int NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `rg_eval_options` (
  `id` int NOT NULL AUTO_INCREMENT,
  `question_id` int NOT NULL,
  `option_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_order` int DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `question_id` (`question_id`),
  CONSTRAINT `rg_eval_options_ibfk_1` FOREIGN KEY (`question_id`) REFERENCES `rg_eval_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `rg_eval_questions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `form_id` int NOT NULL,
  `question_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `question_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_required` tinyint(1) DEFAULT '1',
  `display_order` int DEFAULT '0',
  `target_prof_id` int DEFAULT NULL,
  `target_course_id` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `form_id` (`form_id`),
  CONSTRAINT `rg_eval_questions_ibfk_1` FOREIGN KEY (`form_id`) REFERENCES `rg_eval_forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `rg_eval_responses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `form_id` int NOT NULL,
  `user_id` int NOT NULL,
  `respondent_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `submitted_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `form_id` (`form_id`),
  CONSTRAINT `rg_eval_responses_ibfk_1` FOREIGN KEY (`form_id`) REFERENCES `rg_eval_forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `security_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `event_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Type: login, logout, failed_login, csrf_fail, etc.',
  `user_id` int DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_event_type` (`event_type`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_ip` (`ip_address`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `security_logs_chk_1` CHECK (json_valid(`details`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `session` (
  `session_id` int NOT NULL AUTO_INCREMENT,
  `short_code` varchar(45) NOT NULL,
  `semester` varchar(100) DEFAULT NULL,
  `year` varchar(100) DEFAULT NULL,
  `date_entry` datetime DEFAULT CURRENT_TIMESTAMP,
  `sem` int DEFAULT '1',
  `selected` int NOT NULL DEFAULT '0',
  `graduation_date` date DEFAULT NULL,
  PRIMARY KEY (`session_id`,`short_code`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 AVG_ROW_LENGTH=712;

CREATE TABLE `settings` (
  `settings_id` int NOT NULL AUTO_INCREMENT,
  `type` longtext CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `description` longtext CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `last_change_user_id` varchar(50) DEFAULT NULL,
  `last_change_datetime` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`settings_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 AVG_ROW_LENGTH=48 ROW_FORMAT=DYNAMIC;

CREATE TABLE `std_bursary` (
  `id` int NOT NULL AUTO_INCREMENT,
  `description` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `std_course_info` (
  `id_cours` int NOT NULL,
  `sigle` varchar(50) DEFAULT NULL,
  `title_cours` varchar(200) DEFAULT NULL,
  `student_id` int NOT NULL,
  `session_id` int NOT NULL,
  `credit` int DEFAULT NULL,
  `isadd` int DEFAULT '0',
  `ajout_date` datetime DEFAULT NULL,
  `retrait_date` datetime DEFAULT NULL,
  PRIMARY KEY (`id_cours`,`student_id`,`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `std_course_sub` (
  `id_cours` bigint NOT NULL DEFAULT '0',
  `session` int NOT NULL,
  `student_id` int NOT NULL,
  `lab` varchar(5) DEFAULT NULL,
  `credit` int DEFAULT '3',
  `grade` double DEFAULT '0',
  `date_entry` datetime DEFAULT CURRENT_TIMESTAMP,
  `date_modified` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`session`,`student_id`,`id_cours`),
  KEY `id_index` (`id_cours`),
  KEY `student_id` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 AVG_ROW_LENGTH=76;

CREATE TABLE `std_finance_session` (
  `student_id` int NOT NULL,
  `session_id` int NOT NULL,
  `etude` varchar(100) DEFAULT '"Théologie"',
  `yearlevel` int DEFAULT '1',
  `new_student` int DEFAULT '0',
  `plan` varchar(1) DEFAULT 'A',
  `credit` int DEFAULT '1',
  `assurance` varchar(45) DEFAULT NULL,
  `dortoir` varchar(45) DEFAULT NULL,
  `admission_fee` varchar(45) DEFAULT NULL,
  `entrance_fee` varchar(45) DEFAULT NULL,
  `ecolage` varchar(45) DEFAULT NULL,
  `submission_fee` varchar(45) DEFAULT NULL,
  `general_fee` varchar(45) DEFAULT NULL,
  `yearbook` varchar(45) DEFAULT NULL,
  `field_trip` varchar(45) DEFAULT NULL,
  `cafetariat` varchar(45) DEFAULT NULL,
  `depot_dortoir` varchar(45) DEFAULT NULL,
  `lab_info` varchar(45) DEFAULT NULL,
  `lab_langue` varchar(45) DEFAULT NULL,
  `retard_inscription` varchar(45) DEFAULT NULL,
  `ajout_cours` varchar(45) DEFAULT NULL,
  `retrait_cours` varchar(45) DEFAULT NULL,
  `graduation_fee` varchar(45) DEFAULT NULL,
  `medical` varchar(45) DEFAULT NULL,
  `encadrement_soutenance` varchar(45) DEFAULT NULL,
  `Total` varchar(45) DEFAULT '0',
  `date_entry` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_change_user_id` varchar(45) DEFAULT NULL,
  `last_change_datetime` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`student_id`,`session_id`),
  KEY `session_id_tuitionFK_idx` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='Detail de l''écolage de chaque étudiant';

CREATE TABLE `std_inscription` (
  `id_cours` int NOT NULL,
  `Sigle` varchar(50) DEFAULT NULL,
  `title_cours` varchar(255) DEFAULT NULL,
  `student_id` int NOT NULL,
  `teacher_id` int DEFAULT NULL,
  `teacher_name` varchar(200) DEFAULT NULL,
  `session_id` int NOT NULL,
  `credit` int DEFAULT '2',
  `lab` int DEFAULT '0',
  `cours_category` int DEFAULT '0',
  `grade` double DEFAULT '0',
  `grade_w_credit` double DEFAULT NULL,
  `retrait` tinyint(1) DEFAULT '0',
  `retrait_date` datetime DEFAULT NULL,
  `ajout` tinyint(1) DEFAULT '0',
  `ajout_date` datetime DEFAULT NULL,
  `transfert` tinyint(1) NOT NULL DEFAULT '0',
  `origine_transfert` varchar(255) DEFAULT NULL,
  `dis` tinyint(1) DEFAULT '0' COMMENT 'Cours dirigé',
  `step` int DEFAULT '0',
  `date_entry` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_change_user_id` varchar(50) DEFAULT NULL,
  `last_change_datetime` datetime DEFAULT CURRENT_TIMESTAMP,
  `title_english` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id_cours`,`student_id`,`session_id`),
  KEY `inscription_ibfk` (`student_id`),
  KEY `session_id_inscriptionfk_idx` (`session_id`),
  KEY `teacher_id_ibfk_idx` (`teacher_id`),
  KEY `teacher_ibfk_idx` (`teacher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 AVG_ROW_LENGTH=92;

CREATE TABLE `std_room` (
  `id` int NOT NULL AUTO_INCREMENT,
  `room_name` varchar(10) DEFAULT NULL,
  `room_description` varchar(50) DEFAULT NULL,
  `capacity` int DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `std_room_settings` (
  `session_id` int NOT NULL,
  `id` int NOT NULL AUTO_INCREMENT,
  `room` varchar(5) NOT NULL,
  `number` int DEFAULT NULL,
  `id_cours` int NOT NULL,
  `sigle` varchar(10) DEFAULT NULL,
  `date_exam` date DEFAULT NULL,
  `type_exam` int DEFAULT '0',
  `start_time` int DEFAULT '8',
  `end_time` int DEFAULT '10',
  PRIMARY KEY (`id`,`session_id`,`room`,`id_cours`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `std_select_cours` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_cours` int DEFAULT NULL,
  `sigle` varchar(50) DEFAULT NULL,
  `mention` varchar(100) DEFAULT NULL,
  `parcours` varchar(100) DEFAULT NULL,
  `year` int DEFAULT NULL,
  `session` int DEFAULT '1',
  `date_entry` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `std_sub_session` (
  `student_id` int NOT NULL,
  `session_id` int NOT NULL,
  `app_registrar` tinyint(1) DEFAULT '0',
  `app_academic` tinyint(1) DEFAULT '0',
  `app_dean` tinyint(1) DEFAULT '0',
  `app_finance` tinyint(1) DEFAULT '0',
  `app_cashier` tinyint(1) DEFAULT '0',
  `app_worked` tinyint(1) DEFAULT '0',
  `app_yearbook` tinyint(1) DEFAULT '0',
  `grad_class` tinyint(1) DEFAULT '0',
  `step` int DEFAULT '0',
  `study_yearlevel` int DEFAULT '1',
  `residence` int DEFAULT '0',
  `new_student` int DEFAULT '0',
  `negociation` varchar(200) DEFAULT NULL,
  `work_ed` varchar(25) NOT NULL DEFAULT 'UNKOWN',
  `note_worked` double DEFAULT '0',
  `note_chapelle` double DEFAULT NULL,
  `absence` int DEFAULT NULL,
  `date_insc` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_change_user_id` varchar(50) DEFAULT NULL,
  `last_change_datetime` datetime DEFAULT CURRENT_TIMESTAMP,
  `photo` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`student_id`,`session_id`),
  KEY `worked_fk_idx` (`work_ed`),
  KEY `session_id_subsessionfk_idx` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 AVG_ROW_LENGTH=124;

CREATE TABLE `stdfinancial` (
  `student_id` int NOT NULL,
  `session_id` int NOT NULL,
  `financial_id` varchar(15) NOT NULL,
  `student_name` varchar(255) NOT NULL,
  `department` varchar(30) NOT NULL,
  `status` varchar(20) NOT NULL,
  `year` int DEFAULT NULL,
  `slip_code` varchar(15) DEFAULT NULL,
  `date_entry` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`session_id`,`student_id`),
  KEY `financial_ibfk` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 AVG_ROW_LENGTH=183;

CREATE TABLE `student_list` (
  `student_id` int NOT NULL,
  `student_name` varchar(255) DEFAULT NULL,
  `date_entry` datetime DEFAULT CURRENT_TIMESTAMP,
  `date_modify` datetime DEFAULT CURRENT_TIMESTAMP,
  `promotion` varchar(50) DEFAULT NULL,
  `year` year DEFAULT NULL,
  `faculte` varchar(50) DEFAULT NULL,
  `status` int DEFAULT '1' COMMENT '1 - Actif\n2 - Quitté\n3 - Changé de majeur\n4 - Institut de langue\n5 - Gradué',
  `sexe` int DEFAULT '1',
  `moyenne` double DEFAULT '0',
  `mention` varchar(100) DEFAULT NULL,
  `graduated` int DEFAULT '0',
  PRIMARY KEY (`student_id`),
  KEY `student_id` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 AVG_ROW_LENGTH=116;

CREATE TABLE `studentaccount` (
  `student_id` int NOT NULL,
  `student_password` varchar(128) DEFAULT NULL,
  `student_session` varchar(45) NOT NULL DEFAULT '0',
  `logged_in` int NOT NULL DEFAULT '0',
  `balance` varchar(255) DEFAULT '0',
  `graduated` int DEFAULT '0',
  `shacode` varchar(250) DEFAULT NULL,
  `date_entry` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_change_user_id` varchar(50) DEFAULT NULL,
  `last_change_datetime` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 AVG_ROW_LENGTH=94;

CREATE TABLE `students` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `matricule` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nom` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `prenom` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sexe` enum('M','F') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_naissance` date NOT NULL,
  `lieu_naissance` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `nationalite` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Malagasy',
  `religion` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Adventiste du 7ème jour',
  `etat_civil` enum('célibataire','marié','divorcé','veuf') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `passport_status` tinyint(1) NOT NULL DEFAULT '0',
  `passport_numero` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `passport_pays_emission` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `passport_date_emission` date DEFAULT NULL,
  `passport_date_expiration` date DEFAULT NULL,
  `nom_conjoint` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nb_enfant` int NOT NULL DEFAULT '0',
  `cin_numero` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cin_date_delivrance` date DEFAULT NULL,
  `cin_lieu_delivrance` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nom_pere` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `profession_pere` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_pere` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `nom_mere` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `profession_mere` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_mere` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `adresse_parents` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `telephone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `adresse` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `region` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `district` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `bacc_serie` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bacc_date_obtention` int DEFAULT NULL,
  `bursary_status` tinyint(1) NOT NULL DEFAULT '0',
  `sponsor_nom` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sponsor_prenom` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sponsor_telephone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sponsor_adresse` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `year_level_id` bigint unsigned DEFAULT NULL,
  `mention_id` bigint unsigned DEFAULT NULL,
  `semester_id` bigint unsigned DEFAULT NULL,
  `parcours_id` bigint unsigned DEFAULT NULL,
  `academic_year_id` bigint unsigned DEFAULT NULL,
  `statut_interne` enum('interne','externe') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'externe',
  `abonne_caf` tinyint(1) NOT NULL DEFAULT '0',
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `plain_password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `taille` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fee_check` tinyint(1) NOT NULL DEFAULT '0',
  `last_change_user_id` bigint unsigned DEFAULT NULL,
  `last_change_datetime` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `students_email_unique` (`email`),
  UNIQUE KEY `students_matricule_unique` (`matricule`),
  UNIQUE KEY `students_account_code_unique` (`account_code`),
  KEY `students_year_level_id_foreign` (`year_level_id`),
  KEY `students_mention_id_foreign` (`mention_id`),
  KEY `students_semester_id_foreign` (`semester_id`),
  KEY `students_parcours_id_foreign` (`parcours_id`),
  KEY `students_academic_year_id_foreign` (`academic_year_id`),
  KEY `students_last_change_user_id_foreign` (`last_change_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `students11` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `matricule` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nom` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `prenom` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sexe` enum('M','F') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_naissance` date NOT NULL,
  `lieu_naissance` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `nationalite` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Malagasy',
  `religion` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Adventiste du 7ème jour',
  `etat_civil` enum('célibataire','marié','divorcé','veuf') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `passport_status` tinyint(1) NOT NULL DEFAULT '0',
  `passport_numero` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `passport_pays_emission` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `passport_date_emission` date DEFAULT NULL,
  `passport_date_expiration` date DEFAULT NULL,
  `nom_conjoint` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nb_enfant` int NOT NULL DEFAULT '0',
  `cin_numero` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cin_date_delivrance` date DEFAULT NULL,
  `cin_lieu_delivrance` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nom_pere` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `profession_pere` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_pere` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `nom_mere` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `profession_mere` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_mere` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `adresse_parents` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `telephone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `adresse` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `region` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `district` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `bacc_serie` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bacc_date_obtention` int DEFAULT NULL,
  `bursary_status` tinyint(1) NOT NULL DEFAULT '0',
  `sponsor_nom` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sponsor_prenom` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sponsor_telephone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sponsor_adresse` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `year_level_id` bigint unsigned DEFAULT NULL,
  `mention_id` bigint unsigned DEFAULT NULL,
  `semester_id` bigint unsigned DEFAULT NULL,
  `parcours_id` bigint unsigned DEFAULT NULL,
  `academic_year_id` bigint unsigned DEFAULT NULL,
  `statut_interne` enum('interne','externe') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'externe',
  `abonne_caf` tinyint(1) NOT NULL DEFAULT '0',
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `plain_password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `taille` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fee_check` tinyint(1) NOT NULL DEFAULT '0',
  `last_change_user_id` bigint unsigned DEFAULT NULL,
  `last_change_datetime` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `students11_email_unique` (`email`),
  UNIQUE KEY `students11_matricule_unique` (`matricule`),
  UNIQUE KEY `students11_account_code_unique` (`account_code`),
  KEY `students11_year_level_id_foreign` (`year_level_id`),
  KEY `students11_mention_id_foreign` (`mention_id`),
  KEY `students11_semester_id_foreign` (`semester_id`),
  KEY `students11_parcours_id_foreign` (`parcours_id`),
  KEY `students11_academic_year_id_foreign` (`academic_year_id`),
  KEY `students11_last_change_user_id_foreign` (`last_change_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `t_2023_archives` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` int DEFAULT NULL,
  `student_nom` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `student_prenom` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `student_adresse` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `student_region` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sex` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `student_email` varchar(55) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `dateNaissance` date DEFAULT NULL,
  `lieuNaissance` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `student_tel` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `religion` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etat_civil` int DEFAULT NULL,
  `nom_conjoint` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `nb_enfant` int DEFAULT NULL,
  `father_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `father_prof` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `mother_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `mother_prof` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `parent_adresse` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `parent_tel` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `nationalite` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `num_cin` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `pays_origine` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `cin_date_delivre` date DEFAULT NULL,
  `cin_region` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `num_visa` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `pers_contact_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `pers_adresse` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `pers_tel` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etude_envisage` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etude_option` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sponsor_nom` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sponsor_prenom` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sponsor_adresse` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sponsor_tel` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sponsor_dure_f` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `externe` int DEFAULT NULL,
  `image_student` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `appartement` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `situationf` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etat` int DEFAULT NULL,
  `image` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `lookup_code` varchar(13) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `annee_etude` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `graduated` int DEFAULT NULL,
  `graduation_date` date DEFAULT NULL,
  `bursary_status` int DEFAULT NULL,
  `new_student` int DEFAULT NULL,
  `type_retrait` int DEFAULT NULL,
  `retrait_date` date DEFAULT NULL,
  `champ` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `bacc_serie` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_entry` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `last_change_user_id` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `last_change_datetime` date DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `t_2023_cours` (
  `id` int NOT NULL AUTO_INCREMENT,
  `Sigle` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sigle_affiche` varchar(45) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `title_english` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `Description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `nb_crd` int DEFAULT NULL,
  `Remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `dep_desc` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `category` int DEFAULT NULL,
  `id_teacher` int DEFAULT NULL,
  `semester` int DEFAULT NULL,
  `lab` int DEFAULT NULL,
  `cout` float NOT NULL,
  `cout_lab` float NOT NULL,
  `ajout` int NOT NULL,
  `yearlevel` int DEFAULT NULL,
  `active` int DEFAULT NULL,
  `dep_option` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `new_course` int DEFAULT NULL,
  `new_version` int DEFAULT NULL,
  `link_id_cours` int DEFAULT NULL,
  `new_sigle` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `parcours` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `remove` int NOT NULL,
  `date_entry` date DEFAULT NULL,
  `last_change_user_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `last_change_datetime` date DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `t_2023_course_sessions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `course_id` int NOT NULL,
  `session_label` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `academic_year` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `offered` tinyint(1) NOT NULL DEFAULT '1',
  `date_entry` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_change` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_course_session_year` (`course_id`,`session_label`,`academic_year`),
  KEY `course_id` (`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `t_2023_notes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_cours` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `Sigle` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `title_cours` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `student_id` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `teacher_id` int DEFAULT NULL,
  `teacher_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `session_id` int DEFAULT NULL,
  `semester` int DEFAULT NULL,
  `yearlevel` int DEFAULT NULL,
  `annee_scolaire` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `credit` int DEFAULT NULL,
  `lab` int DEFAULT NULL,
  `cours_category` int DEFAULT NULL,
  `grade` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `grade_w_credit` int DEFAULT NULL,
  `retrait_date` datetime DEFAULT NULL,
  `ajout` int DEFAULT NULL,
  `ajout_date` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `transfert` int DEFAULT NULL,
  `origine_transfert` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `mask` int DEFAULT NULL,
  `step` int DEFAULT NULL,
  `remove` int NOT NULL,
  `user_id_entry` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_entry` datetime DEFAULT NULL,
  `last_change_user_id` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `last_change_datetime` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `title_english` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notes_etudiant_session` (`student_id`,`session_id`),
  KEY `idx_notes_cours_etudiant` (`id_cours`,`student_id`),
  KEY `idx_notes_sigle_annee` (`Sigle`,`annee_scolaire`),
  KEY `idx_notes_session_etudiant` (`session_id`,`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `t_2023_promotion_notes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` int DEFAULT NULL,
  `session_id` int NOT NULL,
  `semester` int DEFAULT NULL,
  `yearlevel` int DEFAULT NULL,
  `annee_scolaire` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `grade_w_credit` int DEFAULT NULL,
  `grade_work_educ` double DEFAULT NULL,
  `grade_chapel_part` double DEFAULT NULL,
  `grade_remark_acad` double DEFAULT NULL,
  `remove` int NOT NULL,
  `date_entry` date DEFAULT NULL,
  `last_change_user_id` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `last_change_datetime` date DEFAULT NULL,
  `title_english` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `t_2023_session` (
  `session_id` int NOT NULL AUTO_INCREMENT,
  `session_code` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `session_name` varchar(70) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `session_year` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `session_semester` int NOT NULL,
  `selected` int NOT NULL,
  `date_entry` date NOT NULL,
  `graduation_date` date DEFAULT NULL,
  PRIMARY KEY (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `t_2024_bacc` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_obtent` date NOT NULL,
  `bacc_serie` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `user_id` int NOT NULL,
  `date_entry` date NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `t_2024_conflits_horaire` (
  `id` int NOT NULL AUTO_INCREMENT,
  `emploi_du_temps_id` int NOT NULL,
  `type_conflit` enum('salle','enseignant','etudiant','multiple') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `conflit_avec_id` int DEFAULT NULL,
  `gravite` enum('faible','moyenne','elevee') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'moyenne',
  `resolu` tinyint(1) DEFAULT '0',
  `date_detection` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_resolution` timestamp NULL DEFAULT NULL,
  `resolu_par` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type_conflit`),
  KEY `idx_resolu` (`resolu`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `t_2024_cours_finance` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `session_id` int NOT NULL,
  `cours_id` int NOT NULL,
  `cours_sigle` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `cours_title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `cours_credit` int NOT NULL,
  `cours_cout` float NOT NULL,
  `lab_cout` float NOT NULL,
  `remove` int NOT NULL,
  `date_entry` date NOT NULL,
  `last_change_user_id` int NOT NULL,
  `last_change_datetime` date NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cours_finance_dossier` (`student_id`,`session_id`),
  KEY `idx_cours_finance_cours` (`cours_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `t_2024_diplome_preced` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `diplome_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_obtent` date NOT NULL,
  `user_id` int NOT NULL,
  `date_entry` date NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `t_2024_emploi_du_temps` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cours_id` int NOT NULL,
  `cours_sigle` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cours_title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type_seance` enum('cours','td','tp','examen','controle') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cours',
  `niveau` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mention` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `parcours` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `semester` int NOT NULL,
  `annee_scolaire` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `jour_semaine` enum('Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `heure_debut` time NOT NULL,
  `heure_fin` time NOT NULL,
  `date_seance` date DEFAULT NULL,
  `salle_id` int DEFAULT NULL,
  `salle_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_teacher` int DEFAULT NULL,
  `teacher_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `duree_minutes` int DEFAULT NULL,
  `remarque` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `statut` enum('planifie','confirme','annule','reporte') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'planifie',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_jour` (`jour_semaine`),
  KEY `idx_niveau_mention` (`niveau`,`mention`),
  KEY `idx_horaire` (`jour_semaine`,`heure_debut`,`heure_fin`),
  KEY `idx_salle` (`salle_id`,`jour_semaine`),
  KEY `idx_teacher` (`id_teacher`,`jour_semaine`),
  KEY `idx_annee` (`annee_scolaire`),
  KEY `idx_statut` (`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `t_2024_etudiant_finace` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `session_id` int NOT NULL,
  `mention` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `level` int NOT NULL,
  `status` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `cout_logement` float NOT NULL,
  `cout_fraix_generaux` float NOT NULL,
  `cout_fondDepot_dortoir` float NOT NULL,
  `cout_abonment` double NOT NULL,
  `cout_frais_graduation` float NOT NULL,
  `cout_totalCours` float NOT NULL,
  `cout_totalLab` float NOT NULL,
  `cout_costume` double NOT NULL,
  `cout_voyage` double NOT NULL,
  `mode_payement` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `remove` int NOT NULL,
  `date_entry` date NOT NULL,
  `last_change_user_id` int NOT NULL,
  `last_change_datetime` date NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_finance_dossier` (`student_id`,`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `t_2024_etudiant_frais_perso` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `session_id` int NOT NULL,
  `frais_id` int NOT NULL,
  `libelle` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `montant` double NOT NULL DEFAULT '0',
  `remove` tinyint(1) NOT NULL DEFAULT '0',
  `date_entry` datetime DEFAULT NULL,
  `last_change_user_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_etudiant_frais` (`student_id`,`session_id`,`frais_id`),
  KEY `idx_etudiant_dossier` (`student_id`,`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `t_2024_finance_detail_licence` (
  `id` int NOT NULL AUTO_INCREMENT,
  `session_id` int NOT NULL,
  `categorie` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `std_status` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `std_mention` varchar(70) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `level` int NOT NULL,
  `semester` int NOT NULL,
  `frais_generaux` float NOT NULL,
  `ecolage` float NOT NULL,
  `laboratory_info` float NOT NULL,
  `laboratory_lang` float NOT NULL,
  `dortoir` double NOT NULL,
  `nb_jours_semestre` int NOT NULL,
  `cafeteria` float NOT NULL,
  `fond_depot` float NOT NULL,
  `frais_graduation` double NOT NULL,
  `frais_costume` float NOT NULL,
  `frais_voyage` double NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `t_2024_inscription_session` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `etude_mention` varchar(70) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `status` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `new_student` int NOT NULL,
  `graduated` int NOT NULL,
  `caisse_verification` int NOT NULL,
  `data_completion` int NOT NULL,
  `data_verification` int NOT NULL,
  `cours_selected` int NOT NULL,
  `mode_payement` int NOT NULL,
  `verification_signatures` int NOT NULL,
  `depot_list` int NOT NULL,
  `session_id` int NOT NULL,
  `nbr_semester` int NOT NULL,
  `test_niveau` int NOT NULL,
  `adresse_actuel_std` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `parcours_std` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `niveau_std` int NOT NULL,
  `sponsor_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `sponsor_lastName` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `sponsor_contact` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `sponsor_address` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `etat_civil_std` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `conjoint_name` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `nb_enfant` int NOT NULL,
  `abonment_std` int NOT NULL,
  `remove` int NOT NULL,
  `annee_scolaire` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_entry` date NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_inscription_dossier` (`student_id`,`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `t_2024_salles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `salle_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `salle_nom` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `capacite` int NOT NULL DEFAULT '30',
  `type_salle` enum('amphi','td','tp','labo','autre') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'td',
  `batiment` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `etage` int DEFAULT NULL,
  `equipements` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `disponible` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `salle_code` (`salle_code`),
  KEY `idx_disponible` (`disponible`),
  KEY `idx_type` (`type_salle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `t_account_code_import_20260413` (
  `account_code` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `nom` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `prenom` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `st_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `t_annonces` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Titre de l''annonce',
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Contenu complet de l''annonce',
  `excerpt` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'R??sum?? court pour aper??u',
  `image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nom du fichier image (dans app/uploads/annonces/)',
  `category` enum('info','event','academic','urgent','sport','culture') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info' COMMENT 'Cat??gorie de l''annonce',
  `is_pinned` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Annonce ??pingl??e en priorit??',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Annonce visible ou masqu??e',
  `author` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Auteur de l''annonce',
  `publish_date` date DEFAULT NULL COMMENT 'Date de publication',
  `expire_date` date DEFAULT NULL COMMENT 'Date d''expiration (NULL = pas d''expiration)',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Date de cr??ation',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Derni??re modification',
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category`),
  KEY `idx_publish_date` (`publish_date`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_is_pinned` (`is_pinned`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `t_audit_modification` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `requete_id` bigint unsigned NOT NULL,
  `ordre` smallint unsigned NOT NULL,
  `date_action` datetime(3) NOT NULL,
  `operation` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL,
  `table_nom` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lignes` int DEFAULT NULL,
  `requete_sql` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `parametres` text COLLATE utf8mb4_unicode_ci,
  `changements` mediumtext COLLATE utf8mb4_unicode_ci,
  `transaction_statut` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'hors',
  `erreur` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_audit_modif_requete` (`requete_id`),
  KEY `idx_audit_modif_table` (`table_nom`,`date_action`),
  CONSTRAINT `fk_audit_modif_requete` FOREIGN KEY (`requete_id`) REFERENCES `t_audit_requete` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `t_audit_requete` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `date_action` datetime(3) NOT NULL,
  `duree_ms` int unsigned DEFAULT NULL,
  `type_action` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `libelle` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int DEFAULT NULL,
  `user_pseudo` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_nom` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_niveau` tinyint DEFAULT NULL,
  `methode` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `url` varchar(2000) COLLATE utf8mb4_unicode_ci NOT NULL,
  `chemin` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `referent` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `statut_http` smallint DEFAULT NULL,
  `redirection` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ajax` tinyint(1) NOT NULL DEFAULT '0',
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `appareil` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `session_empreinte` char(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `donnees` mediumtext COLLATE utf8mb4_unicode_ci,
  `fichiers` text COLLATE utf8mb4_unicode_ci,
  `evenements` text COLLATE utf8mb4_unicode_ci,
  `nb_modifications` int unsigned NOT NULL DEFAULT '0',
  `modifications_ignorees` int unsigned NOT NULL DEFAULT '0',
  `tables_touchees` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `erreur` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `idx_audit_date` (`date_action`),
  KEY `idx_audit_user_date` (`user_id`,`date_action`),
  KEY `idx_audit_type_date` (`type_action`,`date_action`),
  KEY `idx_audit_ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `t_bulk_email_mailbox` (
  `mailbox_id` int NOT NULL AUTO_INCREMENT,
  `mailbox_folder` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `preview_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `preview_image` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `message_html` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `recipient_total` int NOT NULL DEFAULT '0',
  `sent_total` int NOT NULL DEFAULT '0',
  `failed_total` int NOT NULL DEFAULT '0',
  `created_by` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sender_email` varchar(190) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`mailbox_id`),
  KEY `idx_mailbox_folder_date` (`mailbox_folder`,`created_at`),
  KEY `idx_mailbox_sender` (`sender_email`,`mailbox_folder`,`mailbox_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `t_document_verification` (
  `id` int NOT NULL AUTO_INCREMENT,
  `doc_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Code unique du document (ex: UAZ-2026-9F3A7X)',
  `doc_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Hash SHA-256 pour validation',
  `doc_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Type de document (bulletin, transcript, certificat...)',
  `student_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Matricule étudiant',
  `student_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nom complet de l''étudiant',
  `session_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Session concernée (si applicable)',
  `level` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Niveau (L1, L2, L3, M1, M2)',
  `semester` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Semestre',
  `doc_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin COMMENT 'Données supplémentaires du document en JSON',
  `date_emission` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Date d''émission',
  `date_expiration` datetime DEFAULT NULL COMMENT 'Date d''expiration (si applicable)',
  `statut` enum('valide','annule','expire','suspendu') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'valide' COMMENT 'Statut du document',
  `motif_annulation` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Motif si annulé',
  `emis_par` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Utilisateur qui a émis le document',
  `nb_verifications` int DEFAULT '0' COMMENT 'Nombre de fois vérifié',
  `derniere_verification` datetime DEFAULT NULL COMMENT 'Date de dernière vérification',
  `ip_derniere_verif` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'IP de dernière vérification',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `doc_code` (`doc_code`),
  KEY `idx_student` (`student_id`),
  KEY `idx_doc_code` (`doc_code`),
  KEY `idx_doc_hash` (`doc_hash`),
  KEY `idx_statut` (`statut`),
  KEY `idx_date_emission` (`date_emission`),
  CONSTRAINT `t_document_verification_chk_1` CHECK (json_valid(`doc_data`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `t_espace_etudiant_config` (
  `cle` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `valeur` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `modifie_le` datetime DEFAULT NULL,
  `modifie_par` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `t_frais_perso` (
  `id` int NOT NULL AUTO_INCREMENT,
  `libelle` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  `ordre` int NOT NULL DEFAULT '0',
  `condition_frais` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aucune',
  `semestres` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `date_entry` datetime DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_frais_perso_libelle` (`libelle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `t_frais_perso_montant` (
  `id` int NOT NULL AUTO_INCREMENT,
  `frais_id` int NOT NULL,
  `session_id` int NOT NULL DEFAULT '0',
  `std_mention` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `level` int NOT NULL,
  `std_status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `semester` int NOT NULL,
  `montant` double NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_frais_perso_cle` (`frais_id`,`session_id`,`std_mention`,`level`,`std_status`,`semester`),
  KEY `idx_frais_perso_session` (`session_id`,`std_mention`,`semester`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `t_inscription_calendrier` (
  `session_id` int NOT NULL,
  `fin_normale` date NOT NULL COMMENT 'dernier jour des inscriptions normales',
  `limite_tardive` date NOT NULL COMMENT 'dernier jour des inscriptions tardives',
  `penalite_jour` int NOT NULL DEFAULT '10000' COMMENT 'Ariary par jour ouvrable de retard',
  `jours_exclus` varchar(1000) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'jours non comptes (feries), AAAA-MM-JJ separes par des virgules',
  `modifie_par` int DEFAULT NULL,
  `modifie_le` datetime DEFAULT NULL,
  PRIMARY KEY (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `t_inscription_calendrier_cas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `session_id` int NOT NULL,
  `mention` varchar(10) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'filiere.filiere_sigle ; NULL : toutes les mentions',
  `niveau` tinyint DEFAULT NULL COMMENT '0 (remise a niveau) a 5 (M2) ; NULL : tous les niveaux',
  `student_id` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'un etudiant ; prime sur mention et niveau',
  `fin_normale` date NOT NULL COMMENT 'dernier jour des inscriptions sans penalite',
  `limite_tardive` date NOT NULL COMMENT 'dernier jour des inscriptions tardives',
  `motif` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `modifie_par` int DEFAULT NULL,
  `modifie_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cas_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `t_inscription_trace` (
  `id` int NOT NULL AUTO_INCREMENT,
  `session_id` int NOT NULL,
  `student_id` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `inscrit_le` datetime NOT NULL COMMENT 'premiere creation de l inscription au guichet',
  `inscrit_par` int DEFAULT NULL,
  `origine` varchar(10) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'guichet' COMMENT 'guichet, ou reprise (inscription anterieure au suivi)',
  `jours_retard` int NOT NULL DEFAULT '0' COMMENT 'jours ouvrables apres la fin des inscriptions normales',
  `hors_delai` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'apres la date limite des inscriptions tardives',
  `penalite` int NOT NULL DEFAULT '0' COMMENT 'jours_retard x penalite_jour',
  `exoneration` int NOT NULL DEFAULT '0' COMMENT 'part de la penalite remise',
  `exoneration_motif` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `exonere_par` int DEFAULT NULL,
  `exonere_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inscription_trace` (`session_id`,`student_id`),
  KEY `idx_trace_retard` (`session_id`,`jours_retard`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `t_moodle_perimetre` (
  `session_id` int NOT NULL,
  `mention` varchar(10) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'filiere.filiere_sigle (t_2023_cours.dep_desc)',
  `ajoute_le` datetime DEFAULT NULL,
  PRIMARY KEY (`session_id`,`mention`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `t_newsletter_campaigns` (
  `id` int NOT NULL AUTO_INCREMENT,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `body_text` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `body_html` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'draft',
  `scheduled_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_by` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `recipient_total` int NOT NULL DEFAULT '0',
  `sent_total` int NOT NULL DEFAULT '0',
  `failed_total` int NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_newsletter_campaign_status` (`status`,`scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `t_newsletter_deliveries` (
  `id` int NOT NULL AUTO_INCREMENT,
  `campaign_id` int NOT NULL,
  `subscriber_id` int NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `sent_at` datetime DEFAULT NULL,
  `error_message` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_newsletter_campaign_subscriber` (`campaign_id`,`subscriber_id`),
  KEY `idx_newsletter_delivery_status` (`status`),
  KEY `idx_newsletter_delivery_campaign` (`campaign_id`),
  KEY `idx_newsletter_delivery_subscriber` (`subscriber_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `t_newsletter_subscribers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `full_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `unsubscribe_token` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `source` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'public-form',
  `subscribed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `unsubscribed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_newsletter_email` (`email`),
  UNIQUE KEY `uniq_newsletter_token` (`unsubscribe_token`),
  KEY `idx_newsletter_status` (`status`),
  KEY `idx_newsletter_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `t_notes_modification_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `note_id` int NOT NULL COMMENT 'ID de la note modifiée dans t_2023_notes',
  `student_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ID de l étudiant',
  `session_id` int DEFAULT NULL COMMENT 'ID de la session',
  `cours_sigle` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Sigle du cours',
  `cours_titre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Titre du cours',
  `old_grade` decimal(5,2) DEFAULT NULL COMMENT 'Ancienne note',
  `new_grade` decimal(5,2) DEFAULT NULL COMMENT 'Nouvelle note',
  `action_type` enum('creation','modification','suppression','restauration') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'modification',
  `action_by` int NOT NULL COMMENT 'ID de l utilisateur qui a fait la modification',
  `action_date` datetime NOT NULL COMMENT 'Date et heure de la modification',
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Adresse IP de l utilisateur',
  `commentaire` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Commentaire optionnel',
  PRIMARY KEY (`id`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_note_id` (`note_id`),
  KEY `idx_action_by` (`action_by`),
  KEY `idx_action_date` (`action_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Historique des modifications de notes étudiants';

CREATE TABLE `t_notes_publication` (
  `session_id` int NOT NULL,
  `publiee` tinyint(1) NOT NULL DEFAULT '0',
  `modifie_le` datetime DEFAULT NULL,
  `modifie_par` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `t_paiement_echeance` (
  `id` int NOT NULL AUTO_INCREMENT,
  `session_id` int NOT NULL,
  `type_paiement` char(1) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'A, B, C, D ou E',
  `tranche` tinyint NOT NULL COMMENT '1 a 4, dans l ordre des parts du type',
  `date_limite` date NOT NULL,
  `modifie_par` int DEFAULT NULL,
  `modifie_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_echeance` (`session_id`,`type_paiement`,`tranche`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `t_presence_feuille` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cours_id` int NOT NULL COMMENT 't_2023_cours.id, comme t_remise_notes',
  `sigle` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Copie : le cours peut etre renomme ensuite',
  `titre_cours` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `teacher_uid` int DEFAULT NULL COMMENT 'teacher.uid, titulaire a l''ouverture',
  `annee_scolaire` varchar(20) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Format « 2025 - 2026 »',
  `semestre` int DEFAULT NULL COMMENT '1, 2, 3 (ete), 4 (hiver)',
  `seances_prevues` smallint DEFAULT NULL,
  `cree_par` int DEFAULT NULL COMMENT 'compt_utilisateur.id',
  `cree_le` datetime DEFAULT NULL,
  `modifie_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_feuille` (`cours_id`,`annee_scolaire`,`semestre`),
  KEY `idx_teacher` (`teacher_uid`),
  KEY `idx_annee` (`annee_scolaire`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Registre de presence d''un cours pour une annee et un semestre';

CREATE TABLE `t_presence_ligne` (
  `id` int NOT NULL AUTO_INCREMENT,
  `seance_id` int NOT NULL,
  `student_id` varchar(200) COLLATE utf8mb4_general_ci NOT NULL,
  `statut` enum('present','retard','absent') COLLATE utf8mb4_general_ci DEFAULT NULL,
  `minutes_retard` smallint DEFAULT NULL COMMENT 'Pour un retard : c''est lui qui decide s''il compte (§476)',
  `justificatif` enum('medical','autre') COLLATE utf8mb4_general_ci DEFAULT NULL,
  `modifie_par` int DEFAULT NULL,
  `modifie_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ligne` (`seance_id`,`student_id`),
  KEY `idx_student` (`student_id`),
  CONSTRAINT `fk_ligne_seance` FOREIGN KEY (`seance_id`) REFERENCES `t_presence_seance` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Presence d''un etudiant a une seance';

CREATE TABLE `t_presence_seance` (
  `id` int NOT NULL AUTO_INCREMENT,
  `feuille_id` int NOT NULL,
  `date_seance` date NOT NULL,
  `heure_prevue` time DEFAULT NULL COMMENT 'Debut prevu (emploi du temps ou saisi)',
  `arrivee_enseignant` time DEFAULT NULL COMMENT '§474 : heure d''arrivee de l''enseignant',
  `message_retard` tinyint(1) NOT NULL DEFAULT '0' COMMENT '§474 : l''enseignant a prevenu les etudiants de son retard',
  `commentaire` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `cree_par` int DEFAULT NULL,
  `cree_le` datetime DEFAULT NULL,
  `modifie_par` int DEFAULT NULL,
  `modifie_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_feuille_date` (`feuille_id`,`date_seance`),
  CONSTRAINT `fk_seance_feuille` FOREIGN KEY (`feuille_id`) REFERENCES `t_presence_feuille` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Seances tenues d''un cours';

CREATE TABLE `t_presence_signalement` (
  `id` int NOT NULL AUTO_INCREMENT,
  `feuille_id` int NOT NULL,
  `student_id` varchar(200) COLLATE utf8mb4_general_ci NOT NULL,
  `absences` smallint NOT NULL COMMENT 'Absences comptees (retards convertis) au signalement',
  `seances_prevues` smallint NOT NULL,
  `signale_le` datetime NOT NULL,
  `signale_par` int DEFAULT NULL COMMENT 'Compte qui a pointe l''absence de trop',
  `traite_le` datetime DEFAULT NULL COMMENT 'Pris en compte par le registrariat',
  `traite_par` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_signalement` (`feuille_id`,`student_id`),
  KEY `idx_traite` (`traite_le`),
  CONSTRAINT `fk_signalement_feuille` FOREIGN KEY (`feuille_id`) REFERENCES `t_presence_feuille` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Depassements des 15 % d''absences, a traiter par le registrariat';

CREATE TABLE `t_queue_batch_calls` (
  `id` int NOT NULL AUTO_INCREMENT,
  `session_id` int NOT NULL,
  `ticket_numbers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Liste des numéros appelés: [12, 13, 14]',
  `batch_size` int NOT NULL DEFAULT '1',
  `called_by` int NOT NULL,
  `called_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `announcement_text` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Texte affiché sur écran public',
  PRIMARY KEY (`id`),
  KEY `idx_session` (`session_id`),
  KEY `idx_called_at` (`called_at`),
  CONSTRAINT `t_queue_batch_calls_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `t_queue_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `t_queue_batch_calls_chk_1` CHECK (json_valid(`ticket_numbers`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `t_queue_global_music` (
  `id` int NOT NULL AUTO_INCREMENT,
  `music_playlist` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `music_current_index` int DEFAULT '0',
  `music_youtube_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `music_playing` tinyint(1) DEFAULT '0',
  `music_volume` int DEFAULT '50',
  `video_fullscreen` tinyint(1) DEFAULT '0',
  `tts_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `tts_timestamp` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `plan_affiche` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `t_queue_plan` (
  `id` tinyint unsigned NOT NULL DEFAULT '1',
  `plan` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Plan en JSON : batiment, entree, pieces, etapes',
  `plan_precedent` mediumtext COLLATE utf8mb4_unicode_ci COMMENT 'La version remplacee par le dernier enregistrement',
  `modifie_par` int DEFAULT NULL COMMENT 'compt_utilisateur.id',
  `modifie_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `t_queue_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `session_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Ex: Inscription S1 2026',
  `session_date` date NOT NULL,
  `status` enum('active','paused','closed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `last_ticket_number` int DEFAULT '0' COMMENT 'Dernier numéro de ticket généré',
  `created_by` int NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `closed_at` datetime DEFAULT NULL,
  `music_youtube_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `music_playing` tinyint(1) DEFAULT '0',
  `music_volume` int DEFAULT '50',
  `music_playlist` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `music_current_index` int DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_date` (`session_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `t_queue_tickets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `session_id` int NOT NULL,
  `ticket_number` int NOT NULL COMMENT 'Numéro affiché (001, 002...)',
  `student_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nom optionnel',
  `student_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Matricule optionnel',
  `mention` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Mention demandée',
  `status` enum('waiting','called','serving','done','skipped','absent') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'waiting',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `called_at` datetime DEFAULT NULL,
  `served_at` datetime DEFAULT NULL,
  `called_by` int DEFAULT NULL COMMENT 'Agent qui a appelé',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_session_ticket` (`session_id`,`ticket_number`),
  KEY `idx_status` (`status`),
  KEY `idx_session_status` (`session_id`,`status`),
  CONSTRAINT `t_queue_tickets_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `t_queue_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `t_reinscription_reinitialisation` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `lot` char(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'identifiant du lot, commun a toutes ses lignes',
  `student_db_id` int NOT NULL COMMENT 'tbl_2024_etudiant.id',
  `student_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'matricule, pour lecture humaine',
  `colonne` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'authorized_reinscription ou dossier_ok',
  `ancienne_valeur` tinyint(1) NOT NULL DEFAULT '1',
  `ancien_par` int DEFAULT NULL COMMENT 'auteur de la valeur remplacee',
  `ancienne_date` datetime DEFAULT NULL COMMENT 'date de la valeur remplacee',
  `fait_par` int NOT NULL,
  `fait_le` datetime NOT NULL,
  `annule_par` int DEFAULT NULL,
  `annule_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lot` (`lot`),
  KEY `idx_etudiant` (`student_db_id`),
  KEY `idx_fait_le` (`fait_le`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Etat des drapeaux de reinscription avant chaque reinitialisation globale';

CREATE TABLE `t_remise_notes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cours_id` int NOT NULL COMMENT 't_2023_cours.id — jointure fiable avec t_2023_notes.id_cours',
  `sigle` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Copie au moment de la remise : le cours peut etre renomme ensuite',
  `titre_cours` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `teacher_uid` int DEFAULT NULL COMMENT 'teacher.uid — auteur de la remise',
  `annee_scolaire` varchar(20) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Format « 2025 - 2026 », comme t_2023_notes.annee_scolaire',
  `semestre` int DEFAULT NULL COMMENT '1, 2, 3 (ete), 4 (hiver)',
  `poids_quiz` decimal(5,2) NOT NULL DEFAULT '10.00',
  `poids_recherche` decimal(5,2) NOT NULL DEFAULT '20.00',
  `poids_autres` decimal(5,2) NOT NULL DEFAULT '10.00',
  `poids_mi_semestre` decimal(5,2) NOT NULL DEFAULT '25.00',
  `poids_final` decimal(5,2) NOT NULL DEFAULT '35.00',
  `statut` enum('brouillon','soumise','validee','rejetee') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'brouillon',
  `motif_rejet` text COLLATE utf8mb4_general_ci,
  `cree_par` int DEFAULT NULL COMMENT 'compt_utilisateur.id',
  `cree_le` datetime DEFAULT NULL,
  `modifie_le` datetime DEFAULT NULL COMMENT 'Derniere ecriture, quelle qu''elle soit',
  `soumis_par` int DEFAULT NULL,
  `soumis_le` datetime DEFAULT NULL,
  `traite_par` int DEFAULT NULL COMMENT 'Registraire ou superadmin qui a valide ou rejete',
  `traite_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_remise` (`cours_id`,`annee_scolaire`,`semestre`),
  KEY `idx_teacher` (`teacher_uid`),
  KEY `idx_statut` (`statut`),
  KEY `idx_annee` (`annee_scolaire`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Remises de notes des enseignants, en attente de validation du registrariat';

CREATE TABLE `t_remise_notes_journal` (
  `id` int NOT NULL AUTO_INCREMENT,
  `remise_id` int NOT NULL,
  `ligne_id` int DEFAULT NULL COMMENT 'NULL pour une action portant sur la remise entiere',
  `student_id` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `action` enum('creation','saisie','ponderation','soumission','validation','rejet','reouverture') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'saisie',
  `champ` varchar(40) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'quiz, recherche, autres, mi_semestre, examen_final, note_finale, statut_special…',
  `ancienne_valeur` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `nouvelle_valeur` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `auteur_id` int DEFAULT NULL COMMENT 'compt_utilisateur.id',
  `auteur_nom` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Copie : un compte peut etre supprime, le journal doit rester lisible',
  `auteur_role` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_action` datetime NOT NULL,
  `ip` varchar(45) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_remise` (`remise_id`),
  KEY `idx_ligne` (`ligne_id`),
  KEY `idx_date` (`date_action`),
  KEY `idx_auteur` (`auteur_id`),
  CONSTRAINT `fk_journal_remise` FOREIGN KEY (`remise_id`) REFERENCES `t_remise_notes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Journal des modifications d''une remise de notes (auteur, champ, avant/apres, heure)';

CREATE TABLE `t_remise_notes_ligne` (
  `id` int NOT NULL AUTO_INCREMENT,
  `remise_id` int NOT NULL,
  `note_id` int DEFAULT NULL COMMENT 't_2023_notes.id — cible de l''ecriture a la validation',
  `student_id` varchar(200) COLLATE utf8mb4_general_ci NOT NULL,
  `quiz` decimal(5,2) DEFAULT NULL,
  `recherche` decimal(5,2) DEFAULT NULL,
  `autres` decimal(5,2) DEFAULT NULL,
  `mi_semestre` decimal(5,2) DEFAULT NULL,
  `examen_final` decimal(5,2) DEFAULT NULL,
  `note_finale` decimal(5,2) DEFAULT NULL COMMENT 'Calculee depuis les composantes, ou saisie si note_forcee = 1',
  `note_forcee` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = l''enseignant a impose la note finale',
  `statut_special` varchar(5) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `commentaire` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `modifie_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ligne` (`remise_id`,`student_id`),
  KEY `idx_remise` (`remise_id`),
  KEY `idx_note` (`note_id`),
  KEY `idx_student` (`student_id`),
  CONSTRAINT `fk_ligne_remise` FOREIGN KEY (`remise_id`) REFERENCES `t_remise_notes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Detail des notes par etudiant dans une remise';

CREATE TABLE `t_retrait_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type_retrait` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'momentane ou definitif',
  `date_entree_universite` date DEFAULT NULL,
  `date_depart` date NOT NULL,
  `date_retour_probable` date DEFAULT NULL,
  `cause_depart` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `details_cause` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `action_by` int NOT NULL,
  `action_date` datetime NOT NULL,
  `action_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'retrait, annulation_retrait, retour',
  PRIMARY KEY (`id`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_action_date` (`action_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `t_student_modification_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Matricule étudiant',
  `student_db_id` int NOT NULL COMMENT 'ID dans la table tbl_2024_etudiant',
  `field_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nom du champ modifié',
  `field_label` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Libellé du champ en français',
  `old_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Ancienne valeur',
  `new_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Nouvelle valeur',
  `action_type` enum('creation','modification','suppression','suspension','levee_suspension','retrait','annulation_retrait','image') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'modification' COMMENT 'Type d action',
  `action_by` int NOT NULL COMMENT 'ID utilisateur qui a effectué la modification',
  `action_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Date et heure de la modification',
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Adresse IP',
  `user_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Navigateur utilisé',
  `commentaire` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Commentaire optionnel',
  PRIMARY KEY (`id`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_student_db_id` (`student_db_id`),
  KEY `idx_action_date` (`action_date`),
  KEY `idx_action_by` (`action_by`),
  KEY `idx_action_type` (`action_type`),
  KEY `idx_field_name` (`field_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Historique des modifications d informations étudiantes';

CREATE TABLE `t_studio_journal` (
  `id` int NOT NULL AUTO_INCREMENT,
  `visuel_id` int DEFAULT NULL,
  `preset` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `titre` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `format` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `sortie` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'png, jpg, pdf, imprimeur, zip',
  `statut_visuel` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'etat du visuel a l export',
  `auteur_id` int NOT NULL,
  `auteur_nom` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `cree_le` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_studio_journal_date` (`cree_le`),
  KEY `idx_studio_journal_visuel` (`visuel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `t_studio_media` (
  `id` int NOT NULL AUTO_INCREMENT,
  `fichier` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL,
  `titre` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `alt` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `largeur` int NOT NULL,
  `hauteur` int NOT NULL,
  `ajoute_par` int NOT NULL,
  `ajoute_nom` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `ajoute_le` datetime NOT NULL,
  `supprime` tinyint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_studio_media_fichier` (`fichier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `t_studio_reglage` (
  `cle` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `valeur` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `modifie_par` int NOT NULL,
  `modifie_le` datetime NOT NULL,
  PRIMARY KEY (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `t_studio_role` (
  `user_id` int NOT NULL,
  `role` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'validateur',
  `cree_par` int NOT NULL,
  `cree_le` datetime NOT NULL,
  PRIMARY KEY (`user_id`,`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `t_studio_version` (
  `id` int NOT NULL AUTO_INCREMENT,
  `visuel_id` int NOT NULL,
  `numero` int NOT NULL,
  `format` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '4:5',
  `donnees` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `images` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `mise_en_page` mediumtext COLLATE utf8mb4_unicode_ci,
  `blocs` mediumtext COLLATE utf8mb4_unicode_ci,
  `legende` text COLLATE utf8mb4_unicode_ci,
  `motif` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'auto' COMMENT 'auto, manuel, restauration, duplication',
  `auteur_id` int NOT NULL,
  `auteur_nom` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `cree_le` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_studio_version` (`visuel_id`,`numero`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `t_studio_visuel` (
  `id` int NOT NULL AUTO_INCREMENT,
  `preset` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `titre` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `format` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '4:5',
  `donnees` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'JSON : les champs, tels que saisis',
  `images` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'JSON : par emplacement, fichier + options',
  `mise_en_page` mediumtext COLLATE utf8mb4_unicode_ci COMMENT 'JSON : { format: { id: { dx, dy, l, h, k } } }',
  `blocs` mediumtext COLLATE utf8mb4_unicode_ci COMMENT 'JSON : blocs ajoutes, dans l ordre des calques',
  `legende` text COLLATE utf8mb4_unicode_ci,
  `statut` enum('brouillon','a_valider','valide','refuse') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'brouillon',
  `motif_refus` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signature` char(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'sha1 du contenu au moment de la validation ou de la demande',
  `auteur_id` int NOT NULL,
  `auteur_nom` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `valide_par` int DEFAULT NULL,
  `valide_nom` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `valide_le` datetime DEFAULT NULL,
  `exporte_le` datetime DEFAULT NULL,
  `cree_le` datetime NOT NULL,
  `modifie_le` datetime NOT NULL,
  `supprime` tinyint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_studio_visuel_auteur` (`auteur_id`,`supprime`,`modifie_le`),
  KEY `idx_studio_visuel_statut` (`statut`,`supprime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `t_suspension_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_debut` date NOT NULL,
  `date_fin` date DEFAULT NULL,
  `motif` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `action_by` int NOT NULL COMMENT 'ID utilisateur qui a effectué l''action',
  `action_date` datetime NOT NULL,
  `action_type` enum('suspension','levee','modification') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'suspension',
  PRIMARY KEY (`id`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_action_date` (`action_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Historique des suspensions étudiants';

CREATE TABLE `t_verification_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `doc_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `verification_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `resultat` enum('valide','invalide','annule','expire','non_trouve') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `pays` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_doc_code` (`doc_code`),
  KEY `idx_date` (`verification_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `t_work_education` (
  `id` int NOT NULL AUTO_INCREMENT,
  `session_id` int NOT NULL,
  `student_id` varchar(20) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'matricule, comme t_2024_inscription_session',
  `tache` varchar(200) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'ce que fait l etudiant, tel que saisi',
  `attribue_par` int DEFAULT NULL,
  `attribue_le` datetime DEFAULT NULL,
  `modifie_par` int DEFAULT NULL,
  `modifie_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_work_education` (`session_id`,`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `tbl_2024_deans` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `student_dep` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `general_cumul` double NOT NULL,
  `mageur_cumul` double NOT NULL,
  `annee_scolaire` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `tbl_2024_etudiant` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `student_nom` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `student_prenom` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `student_adresse` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `student_region` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sex` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `student_email` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `password` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `dateNaissance` date DEFAULT NULL,
  `lieuNaissance` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `student_tel` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `religion` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etat_civil` int DEFAULT NULL,
  `nom_conjoint` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `nb_enfant` int DEFAULT NULL,
  `father_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `father_prof` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `mother_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `mother_prof` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `parent_adresse` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `parent_tel` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `nationalite` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `num_cin` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `pays_origine` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `cin_date_delivre` date DEFAULT NULL,
  `cin_region` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `num_visa` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `pers_contact_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `pers_adresse` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `pers_tel` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etude_envisage` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etude_option` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sponsor_nom` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sponsor_prenom` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sponsor_adresse` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sponsor_tel` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sponsor_dure_f` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `externe` int DEFAULT NULL,
  `image_student` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `appartement` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `situationf` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etat` int DEFAULT NULL,
  `image` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `lookup_code` varchar(13) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `account_code` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Code compte ├®tudiant import├®',
  `annee_etude` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `graduated` int DEFAULT NULL,
  `abonment` int NOT NULL,
  `graduation_date` date DEFAULT NULL,
  `bursary_status` int DEFAULT NULL,
  `new_student` int DEFAULT NULL,
  `type_retrait` int DEFAULT NULL,
  `retrait_date` date DEFAULT NULL,
  `champ` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `bacc_serie` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `annee_scolaire` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `period` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `remove` int NOT NULL,
  `date_entry` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `last_change_user_id` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `last_change_datetime` date DEFAULT NULL,
  `suspended` tinyint(1) DEFAULT '0' COMMENT 'Statut suspendu: 0=non, 1=oui',
  `authorized_reinscription` tinyint(1) DEFAULT '0' COMMENT '0=non autorisé, 1=autorisé pour réinscription',
  `authorized_by` int DEFAULT NULL COMMENT 'ID de l''utilisateur qui a donné l''autorisation',
  `authorized_date` datetime DEFAULT NULL COMMENT 'Date de l''autorisation de réinscription',
  `dossier_ok` tinyint(1) DEFAULT '0' COMMENT '0=dossier non validé, 1=dossier validé par registraire',
  `dossier_checked_by` int DEFAULT NULL COMMENT 'ID de l''utilisateur (registraire) qui a validé le dossier',
  `dossier_checked_date` datetime DEFAULT NULL COMMENT 'Date de la vérification du dossier',
  `date_debut_suspension` date DEFAULT NULL COMMENT 'Date début de suspension',
  `date_fin_suspension` date DEFAULT NULL COMMENT 'Date fin de suspension',
  `motif_suspension` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci COMMENT 'Motif de la suspension',
  `suspended_by` int DEFAULT NULL COMMENT 'ID utilisateur qui a suspendu',
  `suspended_date` datetime DEFAULT NULL COMMENT 'Date/heure de l''action de suspension',
  `retrait_universite` tinyint(1) DEFAULT '0' COMMENT '0=actif, 2=retrait momentané, 3=retrait définitif',
  `date_entree_universite` date DEFAULT NULL,
  `date_depart_universite` date DEFAULT NULL,
  `date_retour_probable` date DEFAULT NULL,
  `cause_depart` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `details_cause_depart` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `retrait_by` int DEFAULT NULL,
  `taille` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_suspended` (`suspended`),
  KEY `idx_suspension_dates` (`date_debut_suspension`,`date_fin_suspension`),
  KEY `idx_authorized_reinscription` (`authorized_reinscription`),
  KEY `idx_dossier_ok` (`dossier_ok`),
  KEY `idx_account_code` (`account_code`),
  KEY `idx_student_id` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `tbl_theme` (
  `id` int NOT NULL AUTO_INCREMENT,
  `them_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `color_1` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `color_2` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `color_3` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `color_4` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `color_5` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `color_6` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `color_7` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `teacher` (
  `teacher_id` int NOT NULL AUTO_INCREMENT,
  `uid` int NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `lastName` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `lieuN` varchar(100) DEFAULT NULL,
  `sex` tinyint(1) NOT NULL,
  `religion` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `blood_group` varchar(5) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `address` longtext CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci,
  `phone` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `diplome` varchar(255) DEFAULT NULL,
  `email` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `position` varchar(45) DEFAULT NULL,
  `password` longtext CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci,
  `teacher_image` mediumblob,
  `category` int DEFAULT NULL,
  `remove` int NOT NULL,
  `date_entry` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_change_user_id` varchar(50) DEFAULT NULL,
  `last_change_datetime` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`teacher_id`,`uid`),
  UNIQUE KEY `UK_teacher_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 AVG_ROW_LENGTH=420;

CREATE TABLE `transcript` (
  `student_id` int NOT NULL,
  `session_id` int NOT NULL,
  `id_cours` int NOT NULL,
  `Sigle` varchar(40) NOT NULL,
  `nb_credit` int DEFAULT '1',
  `note_cours` double DEFAULT '0',
  `note_w_credit` double DEFAULT '0',
  `course_category` int DEFAULT '0',
  `date_entry` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`student_id`,`session_id`,`Sigle`,`id_cours`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 AVG_ROW_LENGTH=83;

CREATE TABLE `user` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(200) DEFAULT NULL,
  `fullName` varchar(200) DEFAULT NULL,
  `permission` int NOT NULL DEFAULT '0',
  `date_entry` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_change_user_id` varchar(50) DEFAULT NULL,
  `last_change_datetime` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`,`username`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 AVG_ROW_LENGTH=5461;

CREATE TABLE `users` (
  `users_id` int NOT NULL,
  `username` varchar(255) DEFAULT NULL,
  `useremail` varchar(255) DEFAULT NULL,
  `userfiliere` varchar(255) DEFAULT NULL,
  `options` varchar(45) CHARACTER SET big5 COLLATE big5_chinese_ci DEFAULT NULL,
  `userpass` varchar(255) DEFAULT NULL,
  `usertype` varchar(255) DEFAULT NULL,
  `pay_mode` varchar(45) DEFAULT NULL,
  `student_complete` varchar(45) DEFAULT '0',
  `dean_complete` varchar(45) DEFAULT NULL,
  `Finance_complete` varchar(45) DEFAULT NULL,
  `worked_complete` varchar(45) DEFAULT NULL,
  `registrar_complete` varchar(45) DEFAULT NULL,
  `newstudent` int DEFAULT NULL,
  `gradue` varchar(45) DEFAULT NULL,
  `facebook_name` varchar(100) DEFAULT NULL,
  `phone1` varchar(45) DEFAULT '_',
  `phone2` varchar(45) DEFAULT '_',
  `urgence_name` varchar(45) DEFAULT NULL,
  `urgence_phone` varchar(45) DEFAULT NULL,
  `annee_etude` varchar(45) DEFAULT NULL,
  `totalcredit` varchar(45) DEFAULT NULL,
  `appouveur` varchar(45) DEFAULT NULL,
  `vague` varchar(45) DEFAULT '0',
  PRIMARY KEY (`users_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_inscription_etudiants_date_entry` AS select `ins`.`student_id` AS `student_id`,`e`.`student_nom` AS `nom`,`e`.`student_prenom` AS `prenom`,`ins`.`date_entry` AS `date_entry` from (`t_2024_inscription_session` `ins` join `tbl_2024_etudiant` `e` on((`e`.`student_id` = `ins`.`student_id`))) where (`ins`.`date_entry` > '2026-03-15');

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_inscription_etudiants_finance_date_entry` AS select `ins`.`student_id` AS `matricule`,`e`.`student_nom` AS `nom`,`e`.`student_prenom` AS `prenom`,`ins`.`date_entry` AS `date_entry` from ((`t_2024_inscription_session` `ins` join `tbl_2024_etudiant` `e` on((`e`.`student_id` = `ins`.`student_id`))) join `t_2024_etudiant_finace` `fin` on(((`fin`.`student_id` = `ins`.`student_id`) and (`fin`.`session_id` = `ins`.`session_id`)))) where ((`ins`.`date_entry` > '2026-03-15') and (`fin`.`date_entry` > '2026-03-15'));

CREATE ALGORITHM=UNDEFINED DEFINER=`hary`@`127.0.0.1` SQL SECURITY DEFINER VIEW `v_moodle_cours` AS select `x`.`idnumber` AS `idnumber`,(case when (`x`.`doublons` > 1) then concat(`x`.`court`,' #',`x`.`cours_id`) else `x`.`court` end) AS `shortname`,`x`.`fullname` AS `fullname`,`x`.`categorie` AS `categorie`,`x`.`categorie_nom` AS `categorie_nom`,`x`.`session_categorie` AS `session_categorie`,`x`.`session_nom` AS `session_nom`,`x`.`cours_id` AS `cours_id`,`x`.`session_id` AS `session_id`,`x`.`mention` AS `mention` from (select concat('uaz-c',`c`.`id`,'-s',`s`.`session_id`) AS `idnumber`,concat(trim(coalesce(nullif(trim(`c`.`sigle_affiche`),''),`c`.`Sigle`)),' ',`c`.`dep_desc`,' ',(case `s`.`session_semester` when 1 then 'S1' when 2 then 'S2' when 3 then 'Été' when 4 then 'Hiver' else `s`.`session_name` end),' ',left(`s`.`session_year`,4),'-',right(trim(`s`.`session_year`),2)) AS `court`,count(0) OVER (PARTITION BY `s`.`session_id`,`c`.`dep_desc`,trim(coalesce(nullif(trim(`c`.`sigle_affiche`),''),`c`.`Sigle`)) )  AS `doublons`,concat(trim(coalesce(nullif(trim(`c`.`sigle_affiche`),''),`c`.`Sigle`)),' - ',trim(`c`.`title`),' (',`s`.`session_name`,' ',`s`.`session_year`,')') AS `fullname`,concat('uaz-s',`s`.`session_id`,'-',`c`.`dep_desc`) AS `categorie`,coalesce(convert(`f`.`filiere_description` using utf8mb4),`c`.`dep_desc`) AS `categorie_nom`,concat('uaz-s',`s`.`session_id`) AS `session_categorie`,concat(`s`.`session_name`,' ',`s`.`session_year`) AS `session_nom`,`c`.`id` AS `cours_id`,`s`.`session_id` AS `session_id`,`c`.`dep_desc` AS `mention` from (((`t_moodle_perimetre` `p` join `t_2023_session` `s` on((`s`.`session_id` = `p`.`session_id`))) join `t_2023_cours` `c` on(((`c`.`dep_desc` = `p`.`mention`) and (coalesce(`c`.`remove`,0) <> 1)))) left join `filiere` `f` on((`f`.`filiere_sigle` = `c`.`dep_desc`))) where (exists(select 1 from `t_2023_course_sessions` `cs` where ((`cs`.`course_id` = `c`.`id`) and (`cs`.`offered` = 1) and (`cs`.`session_label` = `s`.`session_name`) and (`cs`.`academic_year` = `s`.`session_year`))) or exists(select 1 from `t_2023_notes` `n` where ((`n`.`id_cours` = `c`.`id`) and (`n`.`session_id` = `s`.`session_id`) and (`n`.`remove` = 0))))) `x`;

CREATE ALGORITHM=UNDEFINED DEFINER=`hary`@`127.0.0.1` SQL SECURITY DEFINER VIEW `v_moodle_inscriptions` AS select concat('uaz-c',`n`.`id_cours`,'-s',`n`.`session_id`) AS `cours`,lower(trim(`e`.`student_email`)) AS `email`,'student' AS `role` from (((`t_moodle_perimetre` `p` join `t_2023_notes` `n` on(((`n`.`session_id` = `p`.`session_id`) and (`n`.`remove` = 0)))) join `t_2023_cours` `c` on(((`c`.`id` = `n`.`id_cours`) and (`c`.`dep_desc` = `p`.`mention`)))) join `tbl_2024_etudiant` `e` on((`e`.`id` = (select `e2`.`id` from `tbl_2024_etudiant` `e2` where (`e2`.`student_id` = `n`.`student_id`) order by `e2`.`remove`,`e2`.`id` limit 1)))) where ((lower(trim(`e`.`student_email`)) like '%@zurcher.edu.mg') and exists(select 1 from `t_2024_inscription_session` `i` where ((`i`.`student_id` = `n`.`student_id`) and (`i`.`session_id` = `n`.`session_id`) and (coalesce(`i`.`remove`,0) <> 1)))) group by `n`.`id_cours`,`n`.`session_id`,lower(trim(`e`.`student_email`)) union select `v`.`idnumber` AS `cours`,lower(trim(`t`.`email`)) AS `email`,'editingteacher' AS `role` from ((`v_moodle_cours` `v` join `t_2023_cours` `c` on((`c`.`id` = `v`.`cours_id`))) join `teacher` `t` on((`t`.`uid` = `c`.`id_teacher`))) where ((lower(trim(`t`.`email`)) like '%@zurcher.edu.mg') and (coalesce(`t`.`remove`,0) <> 1));

CREATE TABLE `vitrine_config` (
  `cle` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `valeur` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `groupe` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general',
  `libelle` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `aide` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Precision affichee sous le champ',
  `type` enum('texte','long','date','url','image','liste') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'texte',
  `ordre` smallint NOT NULL DEFAULT '0',
  `modifie_le` datetime DEFAULT NULL,
  `modifie_par` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`cle`),
  KEY `idx_groupe` (`groupe`,`ordre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `work_education` (
  `worked_id` int NOT NULL AUTO_INCREMENT,
  `short_code` varchar(25) NOT NULL,
  `worked_desc` varchar(200) DEFAULT NULL,
  `nb_std` int DEFAULT '1',
  `nb_left` int DEFAULT '0',
  `date_entry` datetime DEFAULT CURRENT_TIMESTAMP,
  `date_modified` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`worked_id`,`short_code`),
  UNIQUE KEY `UK_work_education_short_code` (`short_code`),
  KEY `fk_worked` (`worked_id`,`short_code`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 AVG_ROW_LENGTH=334;

