-- Structure de la base de données pour le système de gestion hospitalier
-- À exécuter dans MySQL/MariaDB

-- Créer les tables principales

-- Table des utilisateurs
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) DEFAULT 'user',
    status VARCHAR(20) DEFAULT 'active',
    phone VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login DATETIME,
    password_changed_at DATETIME,
    failed_login_attempts INT DEFAULT 0,
    last_failed_login DATETIME,
    locked_at DATETIME,
    two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
    two_factor_code VARCHAR(10) NULL,
    two_factor_expires_at DATETIME NULL,
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_status (status)
);

-- Table des patients
CREATE TABLE IF NOT EXISTS patients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nom VARCHAR(50) NOT NULL,
    prenom VARCHAR(50) NOT NULL,
    cin VARCHAR(255) UNIQUE NOT NULL,
    cin_fingerprint CHAR(64) NULL UNIQUE,
    date_naissance DATE,
    sexe CHAR(1),
    groupe_sanguin VARCHAR(5),
    adresse TEXT,
    phone VARCHAR(20),
    email VARCHAR(100),
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cin (cin),
    INDEX idx_nom (nom),
    FULLTEXT INDEX ft_nom_prenom (nom, prenom)
);

-- Table des médecins
CREATE TABLE IF NOT EXISTS medecins (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nom VARCHAR(50) NOT NULL,
    prenom VARCHAR(50) NOT NULL,
    specialite VARCHAR(100) NOT NULL,
    numero_licence VARCHAR(50),
    phone VARCHAR(20),
    email VARCHAR(100),
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_specialite (specialite)
);

-- Table des consultations
CREATE TABLE IF NOT EXISTS consultations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    patient_id INT NOT NULL,
    medecin_id INT NOT NULL,
    date_consultation DATETIME NOT NULL,
    motif TEXT NOT NULL,
    diagnostic TEXT,
    traitement TEXT,
    notes TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'completed',
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id),
    FOREIGN KEY (medecin_id) REFERENCES medecins(id),
    INDEX idx_patient (patient_id),
    INDEX idx_medecin (medecin_id),
    INDEX idx_date (date_consultation)
);

-- Table des factures
CREATE TABLE IF NOT EXISTS factures (
    id INT PRIMARY KEY AUTO_INCREMENT,
    patient_id INT NOT NULL,
    consultation_id INT,
    numero_facture VARCHAR(50) UNIQUE NOT NULL,
    montant DECIMAL(10, 2) NOT NULL,
    description TEXT,
    status VARCHAR(20) DEFAULT 'pending',
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    date_facturation DATETIME,
    date_paiement DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id),
    FOREIGN KEY (consultation_id) REFERENCES consultations(id),
    INDEX idx_patient (patient_id),
    INDEX idx_status (status),
    INDEX idx_numero (numero_facture)
);

-- Table des documents
CREATE TABLE IF NOT EXISTS documents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_type VARCHAR(100),
    file_size INT,
    uploaded_by INT NOT NULL,
    patient_id INT,
    title VARCHAR(255),
    version INT NOT NULL DEFAULT 1,
    parent_doc_id INT NULL,
    sharing_token VARCHAR(64) NULL UNIQUE,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id),
    FOREIGN KEY (patient_id) REFERENCES patients(id),
    INDEX idx_patient (patient_id),
    INDEX idx_uploaded (uploaded_at)
);

-- Rendez-vous médicaux planifiés
CREATE TABLE IF NOT EXISTS appointments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    patient_id INT NOT NULL,
    medecin_id INT NOT NULL,
    starts_at DATETIME NOT NULL,
    duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    reason VARCHAR(500) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
    notes TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id),
    FOREIGN KEY (medecin_id) REFERENCES medecins(id),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_appointments_start (starts_at),
    INDEX idx_appointments_doctor (medecin_id, starts_at),
    INDEX idx_appointments_patient (patient_id, starts_at),
    INDEX idx_appointments_status (status)
);

-- Services, lits et séjours d'hospitalisation
CREATE TABLE IF NOT EXISTS wards (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS beds (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ward_id INT NOT NULL,
    bed_number VARCHAR(30) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'available',
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ward_id) REFERENCES wards(id),
    UNIQUE KEY unique_ward_bed (ward_id, bed_number),
    INDEX idx_bed_status (status)
);

CREATE TABLE IF NOT EXISTS admissions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    patient_id INT NOT NULL,
    bed_id INT NOT NULL,
    attending_medecin_id INT NULL,
    admitted_at DATETIME NOT NULL,
    discharged_at DATETIME NULL,
    admission_reason TEXT NOT NULL,
    discharge_summary TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'admitted',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id),
    FOREIGN KEY (bed_id) REFERENCES beds(id),
    FOREIGN KEY (attending_medecin_id) REFERENCES medecins(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_admission_status (status, admitted_at),
    INDEX idx_admission_patient (patient_id, status),
    INDEX idx_admission_bed (bed_id, status)
);

-- Catalogue pharmaceutique, inventaire et prescriptions
CREATE TABLE IF NOT EXISTS medicines (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    form VARCHAR(80) NOT NULL,
    strength VARCHAR(80) NULL,
    unit VARCHAR(40) NOT NULL DEFAULT 'unité',
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    stock_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    minimum_stock INT UNSIGNED NOT NULL DEFAULT 0,
    expiry_date DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_medicine_name (name),
    INDEX idx_medicine_expiry (expiry_date),
    INDEX idx_medicine_stock (stock_quantity, minimum_stock)
);

CREATE TABLE IF NOT EXISTS stock_movements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    medicine_id INT NOT NULL,
    user_id INT NULL,
    movement_type VARCHAR(20) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    reason VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (medicine_id) REFERENCES medicines(id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_stock_medicine_date (medicine_id, created_at)
);

CREATE TABLE IF NOT EXISTS prescriptions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    patient_id INT NOT NULL,
    consultation_id INT NULL,
    prescribed_by INT NULL,
    instructions TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'issued',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id),
    FOREIGN KEY (consultation_id) REFERENCES consultations(id) ON DELETE SET NULL,
    FOREIGN KEY (prescribed_by) REFERENCES medecins(id) ON DELETE SET NULL,
    INDEX idx_prescription_patient (patient_id, created_at),
    INDEX idx_prescription_status (status)
);

CREATE TABLE IF NOT EXISTS prescription_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    prescription_id INT NOT NULL,
    medicine_id INT NOT NULL,
    dosage VARCHAR(120) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    instructions VARCHAR(255) NULL,
    FOREIGN KEY (prescription_id) REFERENCES prescriptions(id) ON DELETE CASCADE,
    FOREIGN KEY (medicine_id) REFERENCES medicines(id),
    INDEX idx_prescription_item (prescription_id)
);

-- Analyses médicales et suivi des résultats
CREATE TABLE IF NOT EXISTS lab_tests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL UNIQUE,
    category VARCHAR(100) NOT NULL DEFAULT 'Général',
    description VARCHAR(255) NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS lab_orders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    patient_id INT NOT NULL,
    test_id INT NOT NULL,
    consultation_id INT NULL,
    ordered_by INT NULL,
    ordered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    collected_at DATETIME NULL,
    completed_at DATETIME NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'requested',
    result TEXT NULL,
    result_notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id),
    FOREIGN KEY (test_id) REFERENCES lab_tests(id),
    FOREIGN KEY (consultation_id) REFERENCES consultations(id) ON DELETE SET NULL,
    FOREIGN KEY (ordered_by) REFERENCES medecins(id) ON DELETE SET NULL,
    INDEX idx_lab_patient (patient_id, ordered_at),
    INDEX idx_lab_status (status, ordered_at)
);

-- Table des notifications
CREATE TABLE IF NOT EXISTS notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    type VARCHAR(50),
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_user (user_id),
    INDEX idx_read (is_read)
);

-- Table du journal des activités
CREATE TABLE IF NOT EXISTS activity_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_user (user_id),
    INDEX idx_action (action),
    INDEX idx_timestamp (timestamp)
);

-- Notes d'équipe associées aux dossiers et consultations
CREATE TABLE IF NOT EXISTS comments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    patient_id INT NULL,
    consultation_id INT NULL,
    user_id INT NOT NULL,
    comment TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (consultation_id) REFERENCES consultations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_comment_patient (patient_id, created_at),
    INDEX idx_comment_consultation (consultation_id, created_at)
);

-- Messagerie interne avec pièces jointes facultatives
CREATE TABLE IF NOT EXISTS chat_messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    message TEXT NULL,
    filename VARCHAR(255) NULL,
    original_name VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_chat_pair_time (sender_id, receiver_id, created_at)
);

-- Journal de remise des emails (la fonction actuelle consigne les emails localement)
CREATE TABLE IF NOT EXISTS email_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    recipient VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_recipient (recipient),
    INDEX idx_email_date (sent_at)
);

-- Table des permissions
CREATE TABLE IF NOT EXISTS permissions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    permission_name VARCHAR(100) UNIQUE NOT NULL,
    role VARCHAR(20) NOT NULL,
    description TEXT
);

-- Table des permissions utilisateur
CREATE TABLE IF NOT EXISTS user_permissions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    permission_id INT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (permission_id) REFERENCES permissions(id),
    UNIQUE KEY unique_user_perm (user_id, permission_id)
);

-- Table de réinitialisation de mot de passe
CREATE TABLE IF NOT EXISTS password_resets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    used BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_user (user_id),
    INDEX idx_used (used)
);

-- Insérer les permissions par défaut
INSERT IGNORE INTO permissions (permission_name, role, description) VALUES
('view_patients', 'user', 'Voir la liste des patients'),
('add_patient', 'user', 'Ajouter un patient'),
('edit_patient', 'user', 'Modifier un patient'),
('delete_patient', 'admin', 'Supprimer un patient'),
('view_consultations', 'user', 'Voir les consultations'),
('add_consultation', 'user', 'Ajouter une consultation'),
('view_invoices', 'user', 'Voir les factures'),
('add_invoice', 'user', 'Créer une facture'),
('upload_document', 'user', 'Uploader des documents'),
('view_reports', 'user', 'Voir les rapports'),
('manage_users', 'admin', 'Gérer les utilisateurs'),
('view_activities', 'admin', 'Voir le journal des activités');

-- Ajouter des exemples de données (optionnel)
-- Vous pouvez ajouter ici des données de test pour les patients, médecins, etc.
