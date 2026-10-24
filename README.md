# HôpitalWeb

Application de gestion hospitalière en PHP et MySQL/MariaDB. Elle regroupe les dossiers administratifs des patients, les activités cliniques et les fonctions de soutien dans une interface responsive.

## Modules

- Dossiers des patients, médecins, consultations et rendez-vous avec suivi de statut.
- Hospitalisations, services, occupation et disponibilité des lits.
- Pharmacie : catalogue, quantités en stock, seuils d’alerte, péremption, prescriptions et délivrances.
- Laboratoire : catalogue d’analyses, demandes, prélèvements et résultats.
- Facturation, documents médicaux, rapports et exports disponibles dans l’application.
- Comptes utilisateurs, journal d’activité, notifications, messagerie interne et sauvegardes administrateur.

## Prérequis

- PHP 7.4 ou plus avec extensions `mysqli`, `fileinfo` et `openssl`.
- MySQL 8 ou MariaDB compatible avec les clés étrangères et les index FULLTEXT.
- Serveur web configuré pour servir le dossier du projet en PHP.

## Installation d’une nouvelle base

1. Créez la base et un compte SQL dédié :

   ```sql
   CREATE DATABASE hopital_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'hopital_app'@'localhost' IDENTIFIED BY 'remplacer-par-un-mot-de-passe-fort';
   GRANT SELECT, INSERT, UPDATE, DELETE ON hopital_db.* TO 'hopital_app'@'localhost';
   ```

2. Importez `database_schema.sql` dans cette base.

   Réalisez cette étape avec un compte SQL autorisé à créer des tables et des index. Le compte `hopital_app` est destiné à l’exécution quotidienne et ne reçoit que les droits de lecture et d’écriture.

3. Configurez les variables d’environnement de la connexion :

   ```text
   DB_HOST=localhost
   DB_NAME=hopital_db
   DB_USER=hopital_app
   DB_PASSWORD=remplacer-par-un-mot-de-passe-fort
   APP_TIMEZONE=Africa/Bujumbura
   ```

   Générez aussi une clé de chiffrement propre à l’installation et configurez-la dans l’environnement du serveur :

   ```bash
   openssl rand -hex 32
   ```

   Copiez le résultat dans `HOSPITAL_DATA_KEY` et conservez-le dans le gestionnaire de secrets du serveur. Ne changez pas cette clé sans réencrypter les CIN déjà stockés.

   Les valeurs de secours dans `config/database.php` servent uniquement au développement local. Remplacez-les ou configurez les variables ci-dessus avant tout déploiement.

4. Démarrez le serveur PHP avec la racine du projet comme document root. Donnez au serveur les droits d’écriture nécessaires sur `uploads/` et le répertoire de sauvegarde configuré.

5. Créez un compte depuis l’écran d’inscription. Pour attribuer le rôle administrateur à ce compte, connectez-vous à MySQL et exécutez :

   ```sql
   UPDATE users SET role = 'admin' WHERE email = 'adresse-administrateur@example.com';
   ```

   Aucun compte administrateur avec un mot de passe public n’est créé par le schéma.

## Mise à niveau d’une installation existante

1. Faites une sauvegarde de la base et des fichiers téléversés.
2. Importez à nouveau `database_schema.sql` dans la base existante. Les tables déjà présentes sont conservées; les nouveaux modules sont créés.
3. Exécutez `database_migrations.sql` pour ajouter les colonnes manquantes aux tables déjà installées.

La migration vérifie les colonnes et index avant de les créer. Elle peut être relancée après un import partiel.

## Utilisation des modules

- **Rendez-vous** : créez un créneau en choisissant un patient, un médecin et une durée. Un contrôle empêche de réserver un créneau qui chevauche un autre rendez-vous du médecin.
- **Hospitalisations** : créez les services et les lits, admettez un patient sur un lit disponible puis enregistrez sa sortie pour libérer le lit.
- **Pharmacie** : créez le catalogue et le stock initial, saisissez les réapprovisionnements et délivrez les prescriptions. La délivrance réduit le stock et conserve une trace du mouvement.
- **Laboratoire** : créez le catalogue d’analyses, prescrivez un examen, suivez le prélèvement et renseignez le résultat avant de le clôturer.

Les modules utilisent la même liste de patients et de médecins que les autres écrans. Les changements de statut sont soumis avec protection CSRF et les opérations de lits et de stocks sont transactionnelles.

## Configuration et limites

- La durée d’inactivité d’une session connectée est de deux heures.
- Le journal d’emails conserve les messages en base; configurez un véritable service d’envoi avant de dépendre des emails de réinitialisation ou de vérification.
- Les données de patients sont sensibles. Les nouveaux CIN sont chiffrés en AES-GCM et leur empreinte sert à détecter les doublons; les anciens CIN restent lisibles pendant la migration. Déployez l’application derrière HTTPS, limitez les comptes et droits SQL, protégez les sauvegardes et configurez `HOSPITAL_DATA_KEY` avant toute utilisation avec des données réelles.
- Cette application constitue un outil de gestion et ne remplace pas les procédures cliniques, la validation professionnelle ni les exigences réglementaires applicables à votre établissement.
