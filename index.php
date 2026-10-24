<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSecureSession();

// Si déjà connecté, rediriger vers le tableau de bord
if (isLoggedIn() && checkSessionExpiration()) {
    redirect('/dashboard.php');
}

$pageTitle = 'Accueil - Système de Gestion Hospitalier';
$loggedIn = false;
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
        }

        .hero {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 100px 20px;
            text-align: center;
        }

        .hero h1 {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .hero p {
            font-size: 1.2rem;
            margin-bottom: 2rem;
        }

        .btn-group {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
            text-decoration: none;
            display: inline-block;
            transition: background-color 0.3s;
        }

        .btn-primary {
            background-color: white;
            color: #667eea;
            font-weight: bold;
        }

        .btn-primary:hover {
            background-color: #f0f0f0;
        }

        .btn-secondary {
            background-color: rgba(255, 255, 255, 0.2);
            color: white;
            border: 2px solid white;
        }

        .btn-secondary:hover {
            background-color: rgba(255, 255, 255, 0.3);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr));
            gap: 2rem;
            padding: 60px 20px;
        }

        .feature {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .feature i {
            font-size: 3rem;
            color: #667eea;
            margin-bottom: 1rem;
        }

        .feature h3 {
            margin-bottom: 0.5rem;
            color: #2c3e50;
        }

        .feature p {
            color: #666;
        }

        .footer {
            background-color: #2c3e50;
            color: white;
            text-align: center;
            padding: 2rem;
            margin-top: 4rem;
        }

        @media (max-width: 768px) {
            .hero h1 {
                font-size: 2rem;
            }

            .btn-group {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Hero Section -->
    <div class="hero">
        <div class="container">
            <h1><i class="fas fa-hospital"></i> Système de Gestion Hospitalier</h1>
            <p>Plateforme complète de gestion pour hôpitaux et cliniques</p>
            <div class="btn-group">
                <a href="/login.php" class="btn btn-primary">Se connecter</a>
                <a href="/register.php" class="btn btn-secondary">S'inscrire</a>
            </div>
        </div>
    </div>

    <!-- Features Section -->
    <div class="container">
        <div class="features">
            <div class="feature">
                <i class="fas fa-users"></i>
                <h3>Gestion des Patients</h3>
                <p>Gérez facilement les informations complètes de vos patients avec un système intuitif.</p>
            </div>

            <div class="feature">
                <i class="fas fa-stethoscope"></i>
                <h3>Consultations</h3>
                <p>Enregistrez et suivez toutes les consultations avec détails complets et diagnostics.</p>
            </div>

            <div class="feature">
                <i class="fas fa-calendar-check"></i>
                <h3>Rendez-vous</h3>
                <p>Planifiez les consultations, évitez les chevauchements et suivez leur statut.</p>
            </div>

            <div class="feature">
                <i class="fas fa-bed-pulse"></i>
                <h3>Hospitalisations</h3>
                <p>Suivez les admissions, les sorties et l’occupation des lits par service.</p>
            </div>

            <div class="feature">
                <i class="fas fa-capsules"></i>
                <h3>Pharmacie</h3>
                <p>Gérez les médicaments, les stocks, les seuils d’alerte et les prescriptions.</p>
            </div>

            <div class="feature">
                <i class="fas fa-flask"></i>
                <h3>Laboratoire</h3>
                <p>Suivez les demandes d’analyses, les prélèvements et la validation des résultats.</p>
            </div>

            <div class="feature">
                <i class="fas fa-receipt"></i>
                <h3>Facturation</h3>
                <p>Générez et gérez les factures automatiquement avec suivi de paiement.</p>
            </div>

            <div class="feature">
                <i class="fas fa-file-pdf"></i>
                <h3>Documents</h3>
                <p>Stockez et gérez les documents médicaux en toute sécurité.</p>
            </div>

            <div class="feature">
                <i class="fas fa-chart-bar"></i>
                <h3>Rapports</h3>
                <p>Générez des rapports détaillés et exportez en CSV ou PDF.</p>
            </div>

            <div class="feature">
                <i class="fas fa-lock"></i>
                <h3>Sécurité</h3>
                <p>Sécurité maximale avec authentification et chiffrement des données.</p>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <div class="container">
            <p>&copy; 2026 Système de Gestion Hospitalier. Tous droits réservés.</p>
            <p>
                <a href="#" style="color: white; text-decoration: none; margin: 0 1rem;">Confidentialité</a>
                <a href="#" style="color: white; text-decoration: none; margin: 0 1rem;">Conditions d'utilisation</a>
                <a href="#" style="color: white; text-decoration: none; margin: 0 1rem;">Contact</a>
            </p>
        </div>
    </div>
</body>
</html>
