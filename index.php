<?php
require_once 'config.php';

// Rediriger si déjà connecté
if (isLoggedIn()) {
    // Redirection selon le rôle
    $userRole = $_SESSION['user_role'] ?? '';
    if ($userRole === 'proprietaire') {
        redirect('dashboard.php');
    } elseif ($userRole === 'vendeur') {
        redirect('dashboard_vendeur.php');
    } else {
        redirect('dashboard.php');
    }
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Veuillez remplir tous les champs';
    } else {
        try {
            $db = Database::getInstance();
            
            // Rechercher l'utilisateur avec rôle spécifique
            $user = $db->fetch(
                "SELECT * FROM utilisateurs 
                 WHERE nom_utilisateur = ? 
                 AND statut = 'actif' 
                 AND role IN ('proprietaire', 'vendeur')", 
                [$username]
            );
            
            if ($user && password_verify($password, $user['mot_de_passe'])) {
                // Vérifier que le rôle est autorisé
                if (!in_array($user['role'], ['proprietaire', 'vendeur'])) {
                    $error = 'Accès non autorisé pour ce type de compte';
                } else {
                    // Créer la session
                    session_regenerate_id(true); // Sécurité - régénérer l'ID de session
                    
                    $_SESSION['user_id'] = $user['id_utilisateur'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['user_name'] = trim($user['prenom'] . ' ' . $user['nom']);
                    $_SESSION['login_time'] = time();
                    $_SESSION['user_status'] = $user['statut'];
                    
                    // Enregistrer la connexion dans les logs (optionnel)
                    $db->execute(
                        "INSERT INTO logs_connexion (id_utilisateur, ip_adresse, user_agent, date_connexion) 
                         VALUES (?, ?, ?, NOW())",
                        [
                            $user['id_utilisateur'],
                            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
                        ]
                    );
                    
                    // Message de succès
                    flashMessage('Connexion réussie ! Bienvenue ' . $_SESSION['user_name'], 'success');
                    
                    // Redirection selon le rôle
                    switch ($user['role']) {
                        case 'proprietaire':
                            redirect('dashboard.php');
                            break;
                        case 'vendeur':
                            redirect('Vendeur/dashboard_vendeur.php');
                            break;
                        default:
                            redirect('login.php');
                            break;
                    }
                }
            } else {
                $error = 'Nom d\'utilisateur ou mot de passe incorrect';
                
                // Optionnel : Enregistrer les tentatives de connexion échouées
                if ($user) {
                    $db->execute(
                        "INSERT INTO tentatives_connexion_echouees (nom_utilisateur, ip_adresse, date_tentative) 
                         VALUES (?, ?, NOW())",
                        [$username, $_SERVER['REMOTE_ADDR'] ?? 'unknown']
                    );
                }
            }
        } catch (Exception $e) {
            error_log("Erreur de connexion : " . $e->getMessage());
            $error = 'Une erreur est survenue. Veuillez réessayer.';
        }
    }
}

// Récupérer les messages flash
$flashMessages = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - <?= APP_NAME ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .role-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .proprietaire { background-color: #fef3c7; color: #92400e; }
        .vendeur { background-color: #dbeafe; color: #1e40af; }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full mx-4">
        <div class="bg-white rounded-xl shadow-2xl overflow-hidden">
            <div class="bg-gradient-to-r from-blue-600 to-indigo-600 px-8 py-6">
                <h2 class="text-2xl font-bold text-white text-center">
                    <i class="fas fa-store mr-2"></i><?= APP_NAME ?>
                </h2>
                <p class="text-blue-100 text-center mt-1">Système de gestion</p>
            </div>
            
            <div class="px-8 py-6">
                <?php if (!empty($flashMessages)): ?>
                    <?php foreach ($flashMessages as $message): ?>
                        <div class="bg-<?= $message['type'] === 'success' ? 'green' : 'red' ?>-50 border-l-4 border-<?= $message['type'] === 'success' ? 'green' : 'red' ?>-400 p-4 mb-6">
                            <div class="flex">
                                <i class="fas fa-<?= $message['type'] === 'success' ? 'check-circle' : 'exclamation-circle' ?> text-<?= $message['type'] === 'success' ? 'green' : 'red' ?>-400 mr-3 mt-0.5"></i>
                                <p class="text-<?= $message['type'] === 'success' ? 'green' : 'red' ?>-700"><?= htmlspecialchars($message['message']) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-6">
                        <div class="flex">
                            <i class="fas fa-exclamation-circle text-red-400 mr-3 mt-0.5"></i>
                            <p class="text-red-700"><?= htmlspecialchars($error) ?></p>
                        </div>
                    </div>
                <?php endif; ?>
                
                <form method="POST" class="space-y-6" id="loginForm">
                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-user mr-2"></i>Nom d'utilisateur
                        </label>
                        <input type="text" id="username" name="username" required
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-200"
                               placeholder="Entrez votre nom d'utilisateur"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                               autocomplete="username">
                    </div>
                    
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-lock mr-2"></i>Mot de passe
                        </label>
                        <div class="relative">
                            <input type="password" id="password" name="password" required
                                   class="w-full px-4 py-3 pr-12 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-200"
                                   placeholder="Entrez votre mot de passe"
                                   autocomplete="current-password">
                            <button type="button" id="togglePassword" 
                                    class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600">
                                <i class="fas fa-eye" id="eyeIcon"></i>
                            </button>
                        </div>
                    </div>
                    
                    <button type="submit" id="submitBtn"
                            class="w-full bg-gradient-to-r from-blue-600 to-indigo-600 text-white py-3 px-4 rounded-lg font-medium hover:from-blue-700 hover:to-indigo-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition duration-200 disabled:opacity-50 disabled:cursor-not-allowed">
                        <i class="fas fa-sign-in-alt mr-2"></i>Se connecter
                    </button>
                </form>

                <div class="mt-4 text-center">
                    <p class="text-xs text-gray-500">
                        <i class="fas fa-shield-alt mr-1"></i>
                        Connexion sécurisée - Vos données sont protégées
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Toggle password visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordField = document.getElementById('password');
            const eyeIcon = document.getElementById('eyeIcon');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                eyeIcon.classList.remove('fa-eye');
                eyeIcon.classList.add('fa-eye-slash');
            } else {
                passwordField.type = 'password';
                eyeIcon.classList.remove('fa-eye-slash');
                eyeIcon.classList.add('fa-eye');
            }
        });

        // Form validation and submit handling
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;

            if (!username || !password) {
                e.preventDefault();
                return;
            }

            // Disable submit button to prevent double submission
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Connexion...';
            
            // Re-enable after 3 seconds in case of error
            setTimeout(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-sign-in-alt mr-2"></i>Se connecter';
            }, 3000);
        });

        // Auto-focus on username field
        document.getElementById('username').focus();

        // Clear error messages after 5 seconds
        setTimeout(() => {
            const errorMessages = document.querySelectorAll('.bg-red-50');
            errorMessages.forEach(msg => {
                msg.style.transition = 'opacity 0.5s';
                msg.style.opacity = '0';
                setTimeout(() => msg.remove(), 500);
            });
        }, 5000);
    </script>
</body>
</html>