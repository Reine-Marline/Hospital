# Système de Gestion Hospitalier

## Vue d'ensemble

Ce système est une application web complète de gestion hospitalière développée en PHP, HTML et CSS pur, utilisant une base de données MySQL.

## Fonctionnalités principales

### 1. Gestion des Utilisateurs
- **Authentification sécurisée** avec sessions PHP
- **Rôles et permissions** (admin, doctor, patient, staff)
- **Gestion des mots de passe** avec réinitialisation
- **Expiration automatique** des sessions après 2 heures d'inactivité
- **Verrouillage automatique** des comptes après 5 tentatives de connexion échouées
- **Historique des connexions** et journal des activités

### 2. Gestion des Données
- **Patients**: Ajout, modification, consultation, suppression
- **Médecins**: Gestion complète avec spécialités
- **Consultations**: Enregistrement des rendez-vous et diagnostics
- **Factures**: Génération et suivi des factures
- **Requêtes préparées** pour prévenir les injections SQL
- **Recherche avancée** et filtrage

### 3. Gestion Documentaire
- **Téléversement de fichiers** (PDF, DOC, JPG, PNG)
- **Téléchargement** sécurisé de documents
- **Association des documents** aux patients
- **Gestion des versions** de fichiers

### 4. Rapports et Statistiques
- **Tableau de bord** avec statistiques en temps réel
- **Rapports personnalisés** par type
- **Export en CSV** pour tous les rapports
- **Impressions formatées** pour les documents

### 5. Sécurité
- **Chiffrement des mots de passe** avec bcrypt
- **Tokens CSRF** pour tous les formulaires
- **Sessions sécurisées** avec httponly et secure flags
- **Validation et sanitisation** de toutes les entrées
- **Contrôle d'accès** basé sur les rôles

### 6. Communication
- **Notifications internes** pour les utilisateurs
- **Historique des activités** complète
- **Alertes automatiques** pour les événements importants

### 7. Administration
- **Panneau d'administration** réservé aux admins
- **Gestion des utilisateurs** et déverrouillage de comptes
- **Sauvegardes** automatiques et manuelles
- **Paramétrage du système**

## Architecture du projet

```
HopitalWeb/
├── config/
│   └── database.php          # Configuration de la base de données
├── includes/
│   ├── functions.php         # Fonctions utilitaires
│   ├── auth.php              # Système d'authentification
│   ├── header.php            # En-tête HTML
│   └── footer.php            # Pied de page HTML
├── admin/
│   ├── index.php             # Tableau de bord admin
│   ├── users.php             # Gestion des utilisateurs
│   ├── activity.php          # Journal des activités
│   └── backup.php            # Gestion des sauvegardes
├── js/
│   └── main.js               # Scripts JavaScript
├── css/
│   └── style.css             # Feuilles de style
├── uploads/                  # Stockage des documents uploadés
├── backups/                  # Stockage des sauvegardes
├── login.php                 # Page de connexion
├── register.php              # Page d'inscription
├── dashboard.php             # Tableau de bord principal
├── patients.php              # Gestion des patients
├── medecins.php              # Gestion des médecins
├── consultations.php         # Gestion des consultations
├── factures.php              # Gestion des factures
├── documents.php             # Gestion des documents
├── notifications.php         # Notifications utilisateur
├── reports.php               # Génération de rapports
├── profile.php               # Profil utilisateur
├── change-password.php       # Changement de mot de passe
├── logout.php                # Déconnexion
└── README.md                 # Ce fichier
```

## Installation

### Prérequis
- PHP 7.4+
- MySQL 5.7+
- Navigateur web moderne

### Étapes d'installation

1. **Créer la base de données**
   ```sql
   CREATE DATABASE hopital_db;
   ```

2. **Créer l'utilisateur MySQL**
   ```sql
   CREATE USER 'admin_hopital'@'localhost' IDENTIFIED BY 'Admin@123';
   GRANT ALL PRIVILEGES ON hopital_db.* TO 'admin_hopital'@'localhost';
   FLUSH PRIVILEGES;
   ```

3. **Importer la structure de la base de données** (voir le fichier SQL joint)

4. **Placer les fichiers** dans le dossier web de votre serveur

5. **Configurer les permissions** des dossiers
   ```bash
   chmod 755 uploads/
   chmod 755 backups/
   ```

## Utilisation

### Connexion
- URL: `http://localhost/index.php` ou `http://localhost/login.php`
- Utilisateurs par défaut: (à créer lors de l'installation)
- Les mots de passe expirent après 2 heures d'inactivité

### Page d'accueil
- Affiche le tableau de bord avec les statistiques clés
- Accès rapide aux fonctionnalités principales
- Pas d'accès possible si non authentifié

### Gestion des Patients
1. Accéder à "Patients" depuis le menu
2. Voir la liste des patients avec recherche
3. Ajouter un nouveau patient avec les informations
4. Consulter ou éditer les informations existantes

### Gestion des Consultations
1. Aller à "Consultations"
2. Créer une nouvelle consultation
3. Sélectionner patient et médecin
4. Enregistrer motif, diagnostic et traitement

### Gestion des Factures
1. Accéder à "Factures"
2. Créer une nouvelle facture
3. Associer à un patient et consulta tion
4. Exporter en Excel pour comptabilité

### Rapports
1. Utiliser le menu "Rapports"
2. Sélectionner le type de rapport
3. Exporter en CSV ou imprimer

## Fonctionnalités de sécurité

### Protection contre les injections SQL
- Toutes les requêtes utilisent des requêtes préparées
- Utilisation de paramètres bind

### Protection CSRF
- Token CSRF généré et vérifié pour chaque formulaire
- Paramètres POST et GET vérifiés

### Gestion des sessions
- Sessions sécurisées avec flags httponly et secure
- Expiration automatique après 2 heures
- Vérification active de l'utilisateur pour chaque action

### Mot de passe
- Hachage avec bcrypt (PASSWORD_BCRYPT)
- Expiration après 2 heures
- Réinitialisation sécurisée avec tokens

### Validation des entrées
- Toutes les entrées sont sanitisées
- Validation des types de fichiers uploadés
- Limite de taille des fichiers (10 MB)

## Base de données

### Tables principales
- `users`: Utilisateurs du système
- `patients`: Informations des patients
- `medecins`: Informations des médecins
- `consultations`: Enregistrements des consultations
- `factures`: Factures générées
- `documents`: Fichiers uploadés
- `notifications`: Notifications des utilisateurs
- `activity_log`: Journal complet des activités

## Maintenance

### Sauvegardes
- Sauvegardes automatiques configurées dans MySQL
- Sauvegardes manuelles depuis le panneau admin
- Les fichiers de sauvegarde sont stockés dans `/backups/`

### Logs
- Toutes les activités sont enregistrées dans `activity_log`
- Accessible depuis le panneau admin
- Contient: user, action, détails, IP, user agent, timestamp

## Troubleshooting

### La page de connexion s'affiche en boucle
- Vérifiez que les sessions PHP sont activées
- Vérifiez la configuration du cookie
- Vérifiez les permissions du dossier `/tmp/`

### Les fichiers ne s'uploadent pas
- Vérifiez que le dossier `uploads/` existe et est accessible en écriture
- Vérifiez la taille maximale d'upload PHP (php.ini)
- Vérifiez le type de fichier uploaden (doit être dans la liste blanche)

### La base de données ne se connecte pas
- Vérifiez les identifiants dans `config/database.php`
- Vérifiez que MySQL est démarré
- Vérifiez que l'utilisateur a les droits nécessaires

## Support et amélioration

Ce système peut être amélioré avec:
- Authentification à deux facteurs
- API REST pour intégrations externes
- Notifications par email
- Chat en temps réel avec WebSocket
- Mobile app avec React Native
- Intégration avec systèmes de paiement

## Licence

© 2026 Système de Gestion Hospitalier. Tous droits réservés.

## Contact

Pour toute question ou assistance, veuillez contacter l'administrateur système.
