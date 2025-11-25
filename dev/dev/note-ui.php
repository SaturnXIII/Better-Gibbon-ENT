<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Create a config.php file with your database credentials.
$config = include __DIR__ . '/config.php';
$dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset=utf8mb4";
$pdo = new PDO($dsn, $config['user'], $config['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $personID = $_POST['personID'] ?? '';
    $columnID = $_POST['columnID'] ?? '';
    $note = $_POST['note'] ?? '';
    $noteType = $_POST['noteType'] ?? '20';
    $password = $_POST['password'] ?? '';

    if ($personID && $columnID && $note !== '' && $password !== '') {
        // Validation et conversion de la note
        $note = str_replace(',', '.', $note); // Remplacer virgule par point
        $noteFloat = floatval($note);
        $noteFinal = 0;
        
        // Validation selon le type de note
        if ($noteType === '20') {
            if ($noteFloat < 0 || $noteFloat > 20) {
                $errorMessage = "La note doit être entre 0 et 20.";
            } else {
                $noteFinal = $noteFloat;
            }
        } else { // noteType === '100'
            if ($noteFloat < 0 || $noteFloat > 100) {
                $errorMessage = "La note doit être entre 0 et 100.";
            } else {
                // Conversion sur 20
                $noteFinal = round(($noteFloat / 100) * 20, 2);
            }
        }

        // Si pas d'erreur de validation, on continue
        if (empty($errorMessage)) {
            // Récupération du hash et du sel depuis gibbonPerson
            $stmt = $pdo->prepare("SELECT passwordStrong, passwordStrongSalt FROM gibbonPerson WHERE gibbonPersonID = :personID");
            $stmt->execute(['personID' => $personID]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $errorMessage = "Utilisateur inconnu.";
            } else {
                // Vérification du mot de passe avec SHA256(salt + password)
                $hash = hash('sha256', $user['passwordStrongSalt'] . $password);

                if ($hash !== $user['passwordStrong']) {
                    $errorMessage = "Mot de passe incorrect.";
                } else {
                    // Mot de passe correct : on insère ou met à jour la note
                    try {
                        $stmt = $pdo->prepare("
                            SELECT gibbonMarkbookEntryID 
                            FROM gibbonMarkbookEntry 
                            WHERE gibbonPersonIDStudent = :personID 
                              AND gibbonMarkbookColumnID = :columnID
                        ");
                        $stmt->execute(['personID' => $personID, 'columnID' => $columnID]);
                        $entry = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($entry) {
                            // Mise à jour
                            $update = $pdo->prepare("
                                UPDATE gibbonMarkbookEntry 
                                SET attainmentValue = :note, attainmentValueRaw = :note, gibbonPersonIDLastEdit = :personID
                                WHERE gibbonMarkbookEntryID = :entryID
                            ");
                            $update->execute(['note' => $noteFinal, 'personID' => $personID, 'entryID' => $entry['gibbonMarkbookEntryID']]);
                            $successMessage = "Note mise à jour avec succès ! (" . number_format($noteFinal, 2) . "/20)";
                        } else {
                            // Insertion
                            $insert = $pdo->prepare("
                                INSERT INTO gibbonMarkbookEntry 
                                    (gibbonPersonIDStudent, gibbonMarkbookColumnID, attainmentValue, attainmentValueRaw, gibbonPersonIDLastEdit) 
                                VALUES (:personID, :columnID, :note, :note, :personID)
                            ");
                            $insert->execute(['personID' => $personID, 'columnID' => $columnID, 'note' => $noteFinal]);
                            $successMessage = "Note enregistrée avec succès ! (" . number_format($noteFinal, 2) . "/20)";
                        }
                    } catch (Exception $e) {
                        $errorMessage = "Erreur lors de l'enregistrement : " . $e->getMessage();
                    }
                }
            }
        }
    } else {
        $errorMessage = "Veuillez remplir tous les champs.";
    }
}

// Récupération des utilisateurs
$users = $pdo->query("SELECT gibbonPersonID, username FROM gibbonPerson ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Récupération des évaluations
$columns = $pdo->query("SELECT gibbonMarkbookColumnID, name FROM gibbonMarkbookColumn ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des notes - Gibbon</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, rgba(244, 239, 252, 0.4) 0%, rgba(154, 101, 229, 0.1) 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 700px;
            margin: 0 auto;
            padding: 40px 0;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: white;
            color: rgba(154, 101, 229, 1);
            border: 2px solid rgba(154, 101, 229, 1);
            padding: 12px 24px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            box-shadow: 0 2px 8px rgba(154, 101, 229, 0.1);
            margin-bottom: 20px;
        }

        .btn-back:hover {
            background: rgba(244, 239, 252, 1);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(154, 101, 229, 0.2);
        }

        .btn-back:active {
            transform: translateY(0);
        }

        .header {
            text-align: center;
            margin-bottom: 40px;
        }

        .header h1 {
            color: rgba(154, 101, 229, 1);
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .header p {
            color: #6b7280;
            font-size: 16px;
        }

        .card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05), 0 10px 20px rgba(154, 101, 229, 0.08);
            padding: 40px;
        }

        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        .form-group {
            margin-bottom: 24px;
        }

        label {
            display: block;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
            font-size: 14px;
        }

        input[type="text"],
        input[type="password"],
        select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 15px;
            font-family: inherit;
            transition: all 0.3s ease;
            background-color: #f9fafb;
        }

        input[type="text"]:focus,
        input[type="password"]:focus,
        select:focus {
            outline: none;
            border-color: rgba(154, 101, 229, 1);
            background-color: white;
            box-shadow: 0 0 0 3px rgba(154, 101, 229, 0.1);
        }

        select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%239a65e5' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 40px;
        }

        .security-notice {
            background: linear-gradient(135deg, rgba(154, 101, 229, 0.08) 0%, rgba(244, 239, 252, 0.5) 100%);
            border: 2px solid rgba(154, 101, 229, 0.2);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 24px;
            display: flex;
            align-items: start;
            gap: 12px;
        }

        .security-notice svg {
            width: 20px;
            height: 20px;
            color: rgba(154, 101, 229, 1);
            flex-shrink: 0;
            margin-top: 2px;
        }

        .security-notice p {
            color: #4b5563;
            font-size: 14px;
            line-height: 1.5;
        }

        /* Switch pour le type de note */
        .note-type-switch {
            background: linear-gradient(135deg, rgba(244, 239, 252, 0.5) 0%, rgba(221, 204, 245, 0.3) 100%);
            border: 2px solid rgba(154, 101, 229, 0.2);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }

        .switch-label {
            display: block;
            font-weight: 600;
            color: #374151;
            margin-bottom: 12px;
            font-size: 14px;
        }

        .switch-container {
            display: flex;
            align-items: center;
            gap: 16px;
            justify-content: center;
        }

        .switch-option {
            flex: 1;
            position: relative;
        }

        .switch-option input[type="radio"] {
            position: absolute;
            opacity: 0;
        }

        .switch-option label {
            display: block;
            padding: 14px 20px;
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            color: #6b7280;
        }

        .switch-option input[type="radio"]:checked + label {
            background: linear-gradient(135deg, rgba(154, 101, 229, 1) 0%, rgba(134, 81, 209, 1) 100%);
            border-color: rgba(154, 101, 229, 1);
            color: white;
            box-shadow: 0 4px 12px rgba(154, 101, 229, 0.3);
        }

        .switch-option label:hover {
            border-color: rgba(154, 101, 229, 0.5);
        }

        .button-group {
            margin-top: 32px;
        }

        button[type="submit"] {
            width: 100%;
            background: linear-gradient(135deg, rgba(154, 101, 229, 1) 0%, rgba(134, 81, 209, 1) 100%);
            color: white;
            border: none;
            padding: 14px 32px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(154, 101, 229, 0.3);
        }

        button[type="submit"]:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(154, 101, 229, 0.4);
        }

        button[type="submit"]:active {
            transform: translateY(0);
        }

        .icon {
            width: 20px;
            height: 20px;
        }

        .grade-input-wrapper {
            position: relative;
        }

        .grade-input-wrapper::before {
            content: "📝";
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 18px;
        }

        .grade-input-wrapper input {
            padding-left: 48px;
        }

        .password-input-wrapper {
            position: relative;
        }

        .password-input-wrapper::before {
            content: "🔒";
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 18px;
        }

        .password-input-wrapper input {
            padding-left: 48px;
        }

        #noteMaxIndicator {
            font-size: 13px;
            color: #6b7280;
            margin-top: 6px;
            font-weight: 500;
        }

        @media (max-width: 768px) {
            .card {
                padding: 24px;
            }

            .header h1 {
                font-size: 24px;
            }

            .switch-container {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="add.php" class="btn-back">← Retour</a>
        
        <div class="header">
            <h1>📊 Gestion des notes</h1>
            <p>Ajouter ou mettre à jour une note d'élève</p>
        </div>

        <div class="card">
            <?php if ($successMessage): ?>
                <div class="alert alert-success">
                    <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <?php echo htmlspecialchars($successMessage); ?>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage): ?>
                <div class="alert alert-error">
                    <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <?php echo htmlspecialchars($errorMessage); ?>
                </div>
            <?php endif; ?>

            <div class="security-notice">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                </svg>
                <p>Cette action est sécurisée et nécessite votre mot de passe pour valider l'enregistrement ou la modification de la note.</p>
            </div>

            <form method="post">
                <div class="form-group">
                    <label for="personID">Étudiant *</label>
                    <select name="personID" id="personID" required>
                        <option value="">-- Sélectionner un étudiant --</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo htmlspecialchars($user['gibbonPersonID']); ?>"><?php echo htmlspecialchars($user['username']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="columnID">Évaluation *</label>
                    <select name="columnID" id="columnID" required>
                        <option value="">-- Sélectionner une évaluation --</option>
                        <?php foreach ($columns as $col): ?>
                            <option value="<?php echo htmlspecialchars($col['gibbonMarkbookColumnID']); ?>"><?php echo htmlspecialchars($col['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="note-type-switch">
                    <span class="switch-label">Type de notation *</span>
                    <div class="switch-container">
                        <div class="switch-option">
                            <input type="radio" id="note20" name="noteType" value="20" checked>
                            <label for="note20">📊 Sur 20</label>
                        </div>
                        <div class="switch-option">
                            <input type="radio" id="note100" name="noteType" value="100">
                            <label for="note100">💯 Sur 100</label>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="note">Note *</label>
                    <div class="grade-input-wrapper">
                        <input type="text" name="note" id="note" placeholder="Ex: 15.5" required>
                    </div>
                    <div id="noteMaxIndicator">Note maximale : 20</div>
                </div>

                <div class="form-group">
                    <label for="password">Mot de passe de confirmation *</label>
                    <div class="password-input-wrapper">
                        <input type="password" name="password" id="password" placeholder="Entrez votre mot de passe" required>
                    </div>
                </div>

                <div class="button-group">
                    <button type="submit">✓ Enregistrer la note</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const note20 = document.getElementById('note20');
        const note100 = document.getElementById('note100');
        const noteInput = document.getElementById('note');
        const noteMaxIndicator = document.getElementById('noteMaxIndicator');

        function updateNoteType() {
            if (note20.checked) {
                noteInput.placeholder = 'Ex: 15.5';
                noteMaxIndicator.textContent = 'Note maximale : 20';
            } else {
                noteInput.placeholder = 'Ex: 75.5';
                noteMaxIndicator.textContent = 'Note maximale : 100 (sera convertie sur 20)';
            }
            noteInput.value = '';
        }

        note20.addEventListener('change', updateNoteType);
        note100.addEventListener('change', updateNoteType);

        // Validation en temps réel
        noteInput.addEventListener('input', function() {
            let value = this.value.replace(',', '.');
            let max = note20.checked ? 20 : 100;
            
            if (parseFloat(value) > max) {
                this.style.borderColor = '#ef4444';
            } else {
                this.style.borderColor = '';
            }
        });
    </script>
</body>
</html>
