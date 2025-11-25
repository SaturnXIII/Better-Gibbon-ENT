<?php
// connexion PDO à la base Gibbon
// Create a config.php file with your database credentials.
$config = include __DIR__ . '/config.php';
$dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset=utf8mb4";
$pdo = new PDO($dsn, $config['user'], $config['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// récupération des classes disponibles
$classes = $pdo->query("SELECT gibbonCourseClassID, name FROM gibbonCourseClass ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// récupération des unités pour le menu déroulant
$units = $pdo->query("SELECT gibbonUnitID, name FROM gibbonUnit ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// ID de l'utilisateur (fixe pour test)
$personID = 'YOUR_PERSON_ID'; // TODO: Replace with a dynamic way to get the logged-in user's ID.

// traitement du formulaire
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $gibbonCourseClassID = $_POST['gibbonCourseClassID'] ?? '';
    $gibbonUnitID = $_POST['gibbonUnitID'] ?? '';
    $name = $_POST['name'] ?? '';
    $summary = $_POST['summary'] ?? '';
    $description = $_POST['description'] ?? '';
    $homeworkDueDateTime = $_POST['homeworkDueDateTime'] ?? '';
    $homework = 'Y';

    if ($gibbonCourseClassID && $gibbonUnitID && $name && $summary && $description && $homeworkDueDateTime) {
        // On fixe toutes les heures à 13:00
        $timeStart = '13:00';
        $timeEnd = '13:00';

        // Format homeworkDueDateTime correctement
        $homeworkDueDateTime = date('Y-m-d H:i:s', strtotime($homeworkDueDateTime));

        // Insertion dans la table
        $stmt = $pdo->prepare("
            INSERT INTO gibbonPlannerEntry 
            (gibbonCourseClassID, gibbonUnitID, date, timeStart, timeEnd, name, summary, description, teachersNotes, homework, homeworkDueDateTime, homeworkDetails, homeworkTimeCap, homeworkLocation, homeworkSubmission, homeworkSubmissionDateOpen, homeworkSubmissionDrafts, homeworkSubmissionType, homeworkSubmissionRequired, homeworkCrowdAssess, homeworkCrowdAssessOtherTeachersRead, homeworkCrowdAssessOtherParentsRead, homeworkCrowdAssessClassmatesParentsRead, homeworkCrowdAssessSubmitterParentsRead, homeworkCrowdAssessOtherStudentsRead, homeworkCrowdAssessClassmatesRead, viewableStudents, viewableParents, gibbonPersonIDCreator, gibbonPersonIDLastEdit, fields)
            VALUES
            (:gibbonCourseClassID, :gibbonUnitID, :date, :timeStart, :timeEnd, :name, :summary, :description, '', :homework, :homeworkDueDateTime, :description, NULL, 'Out of Class', 'N', NULL, NULL, '', NULL, 'N', 'N', 'N', 'N', 'N', 'N', 'N', 'Y', 'Y', :personID, :personID, '[]')
        ");

        $stmt->execute([
            'gibbonCourseClassID' => $gibbonCourseClassID,
            'gibbonUnitID' => $gibbonUnitID,
            'date' => date('Y-m-d'),
            'timeStart' => $timeStart,
            'timeEnd' => $timeEnd,
            'name' => $name,
            'summary' => $summary,
            'description' => $description,
            'homework' => $homework,
            'homeworkDueDateTime' => $homeworkDueDateTime,
            'personID' => $personID
        ]);

        $successMessage = "Devoir ajouté avec succès !";
    } else {
        $errorMessage = "Veuillez remplir tous les champs obligatoires.";
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajouter un devoir - Gibbon</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>

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
        }

        .btn-back:hover {
            background: rgba(244, 239, 252, 1);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(154, 101, 229, 0.2);
        }

        .btn-back:active {
            transform: translateY(0);
        }



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
            max-width: 800px;
            margin: 0 auto;
            padding: 40px 0;
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
        input[type="datetime-local"],
        select,
        textarea {
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
        input[type="datetime-local"]:focus,
        select:focus,
        textarea:focus {
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

        textarea {
            resize: vertical;
            min-height: 120px;
            line-height: 1.6;
        }

        .button-group {
            display: flex;
            gap: 12px;
            margin-top: 32px;
        }

        button[type="submit"] {
            flex: 1;
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

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .icon {
            width: 20px;
            height: 20px;
        }

        @media (max-width: 768px) {
            .card {
                padding: 24px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .header h1 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📚 Ajouter un nouveau devoir</h1>
            <p>Créez et assignez un devoir à vos élèves</p>
        </div>

        <div class="card">
            <?php if ($successMessage): ?>
                <div class="alert alert-success">
                    <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <?= htmlspecialchars($successMessage) ?>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage): ?>
                <div class="alert alert-error">
                    <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <?= htmlspecialchars($errorMessage) ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <div class="form-row">
                    <div class="form-group">
                        <label for="gibbonCourseClassID">Classe *</label>
                        <select name="gibbonCourseClassID" id="gibbonCourseClassID" required>
                            <option value="">-- Sélectionner une classe --</option>
                            <?php foreach($classes as $class): ?>
                                <option value="<?= $class['gibbonCourseClassID'] ?>"><?= htmlspecialchars($class['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="gibbonUnitID">Unité *</label>
                        <select name="gibbonUnitID" id="gibbonUnitID" required>
                            <option value="">-- Sélectionner une unité --</option>
                            <?php foreach($units as $unit): ?>
                                <option value="<?= $unit['gibbonUnitID'] ?>"><?= htmlspecialchars($unit['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="name">Nom du devoir *</label>
                    <input type="text" name="name" id="name" placeholder="Ex: Exercices de mathématiques chapitre 5" required>
                </div>

                <div class="form-group">
                    <label for="summary">Résumé *</label>
                    <input type="text" name="summary" id="summary" placeholder="Bref résumé du devoir" required>
                </div>

                <div class="form-group">
                    <label for="description">Description détaillée *</label>
                    <textarea name="description" id="description" placeholder="Décrivez le devoir en détail, incluez les instructions et les objectifs..." required></textarea>
                </div>

                <div class="form-group">
                    <label for="homeworkDueDateTime">Date et heure limite *</label>
                    <input type="datetime-local" name="homeworkDueDateTime" id="homeworkDueDateTime" required>
                </div>

                <div class="button-group">
                    <button type="submit">✓ Enregistrer le devoir</button>
                </div>
            </form>
        </div>
    </div>


        <a href="add.php" class="btn-back">
            ← Retour
        </a>


</body>
</html>
