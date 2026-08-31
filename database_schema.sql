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
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_status (status)
);

-- Table des patients
CREATE TABLE IF NOT EXISTS patients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nom VARCHAR(50) NOT NULL,
    prenom VARCHAR(50) NOT NULL,
    cin VARCHAR(20) UNIQUE NOT NULL,
    date_naissance DATE,
    sexe CHAR(1),
    groupe_sanguin VARCHAR(5),
    adresse TEXT,
    phone VARCHAR(20),
    email VARCHAR(100),
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
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id),
    FOREIGN KEY (patient_id) REFERENCES patients(id),
    INDEX idx_patient (patient_id),
    INDEX idx_uploaded (uploaded_at)
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

-- Créer un utilisateur administrateur par défaut (mot de passe: admin123)
INSERT IGNORE INTO users (username, email, password, role, status, password_changed_at) VALUES
('admin', 'admin@hopital.com', '$2y$10$HO74rEhiSNDuWnSPh17hz.X5NKj1BRoe0y6pa4ktoWMZpJtCxg64S', 'admin', 'active', NOW());

-- Ajouter des exemples de données (optionnel)
-- Vous pouvez ajouter ici des données de test pour les patients, médecins, etc.
