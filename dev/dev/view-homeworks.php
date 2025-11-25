<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Connexion PDO à la base Gibbon
// Create a config.php file with your database credentials.
$config = include __DIR__ . '/config.php';
$dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset=utf8mb4";
$pdo = new PDO($dsn, $config['user'], $config['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// 1️⃣ Récupération de l'utilisateur connecté
$sessionValue = null;
foreach ($_COOKIE as $name => $value) {
    if (preg_match('/^[a-f0-9]{32}$/', $value)) {
        $sessionValue = $value;
        break;
    }
}

if (!$sessionValue) {
    die("Aucune session valide trouvée.");
}

$stmt = $pdo->prepare("
    SELECT gibbonPersonID 
    FROM gibbonSession 
    WHERE gibbonSessionID = :session
    LIMIT 1
");
$stmt->execute(['session' => $sessionValue]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    die("Session inconnue.");
}

$gibbonPersonID = $session['gibbonPersonID'];

$stmt = $pdo->prepare("
    SELECT surname, preferredName
    FROM gibbonPerson 
    WHERE gibbonPersonID = :id
    LIMIT 1
");
$stmt->execute(['id' => $gibbonPersonID]);
$person = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$person) {
    die("Utilisateur introuvable.");
}

// 2️⃣ Gestion AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $plannerEntryID = $_POST['plannerEntryID'] ?? null;
    
    if (!$plannerEntryID) {
        echo json_encode(['success' => false, 'message' => 'ID manquant']);
        exit;
    }
    
    try {
        // Récupérer l'entrée existante
        $stmt = $pdo->prepare("
            SELECT gibbonPlannerEntryStudentHomeworkID, homeworkDetails
            FROM gibbonPlannerEntryStudentHomework 
            WHERE gibbonPlannerEntryID = :plannerEntryID 
            AND gibbonPersonID = :personID
            LIMIT 1
        ");
        $stmt->execute([
            'plannerEntryID' => $plannerEntryID,
            'personID' => $gibbonPersonID
        ]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($_POST['action'] === 'toggle_homework') {
            $isCompleted = $_POST['isCompleted'] ?? 'false';
            
            if ($isCompleted === 'true') {
                // Marquer comme fait
                if (!$existing) {
                    $maxId = $pdo->query("SELECT MAX(gibbonPlannerEntryStudentHomeworkID) as maxId FROM gibbonPlannerEntryStudentHomework")->fetch();
                    $nextId = ($maxId['maxId'] ?? 0) + 1;
                    
                    $todoData = json_encode(['completed' => true, 'todos' => []]);
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO gibbonPlannerEntryStudentHomework 
                        (gibbonPlannerEntryStudentHomeworkID, gibbonPlannerEntryID, gibbonPersonID, homeworkDueDateTime, homeworkDetails, homeworkComplete)
                        VALUES (:id, :plannerEntryID, :personID, NOW(), :details, 'Y')
                    ");
                    $stmt->execute([
                        'id' => $nextId,
                        'plannerEntryID' => $plannerEntryID,
                        'personID' => $gibbonPersonID,
                        'details' => $todoData
                    ]);
                } else {
                    // Mettre à jour le statut
                    $currentData = json_decode($existing['homeworkDetails'], true) ?: ['completed' => false, 'todos' => []];
                    $currentData['completed'] = true;
                    
                    $stmt = $pdo->prepare("
                        UPDATE gibbonPlannerEntryStudentHomework 
                        SET homeworkDetails = :details, homeworkComplete = 'Y'
                        WHERE gibbonPlannerEntryID = :plannerEntryID 
                        AND gibbonPersonID = :personID
                    ");
                    $stmt->execute([
                        'details' => json_encode($currentData),
                        'plannerEntryID' => $plannerEntryID,
                        'personID' => $gibbonPersonID
                    ]);
                }
            } else {
                // Marquer comme non fait
                if ($existing) {
                    $currentData = json_decode($existing['homeworkDetails'], true) ?: ['completed' => false, 'todos' => []];
                    $currentData['completed'] = false;
                    
                    // Si pas de todos, supprimer l'entrée
                    if (empty($currentData['todos'])) {
                        $stmt = $pdo->prepare("
                            DELETE FROM gibbonPlannerEntryStudentHomework 
                            WHERE gibbonPlannerEntryID = :plannerEntryID 
                            AND gibbonPersonID = :personID
                        ");
                        $stmt->execute([
                            'plannerEntryID' => $plannerEntryID,
                            'personID' => $gibbonPersonID
                        ]);
                    } else {
                        // Garder l'entrée mais changer le statut
                        $stmt = $pdo->prepare("
                            UPDATE gibbonPlannerEntryStudentHomework 
                            SET homeworkDetails = :details, homeworkComplete = 'N'
                            WHERE gibbonPlannerEntryID = :plannerEntryID 
                            AND gibbonPersonID = :personID
                        ");
                        $stmt->execute([
                            'details' => json_encode($currentData),
                            'plannerEntryID' => $plannerEntryID,
                            'personID' => $gibbonPersonID
                        ]);
                    }
                }
            }
            
            echo json_encode(['success' => true]);
        }
        elseif ($_POST['action'] === 'save_todos') {
            $todos = json_decode($_POST['todos'] ?? '[]', true);
            
            // Si pas d'entrée existante, la créer (mais sans marquer comme fait)
            if (!$existing) {
                $maxId = $pdo->query("SELECT MAX(gibbonPlannerEntryStudentHomeworkID) as maxId FROM gibbonPlannerEntryStudentHomework")->fetch();
                $nextId = ($maxId['maxId'] ?? 0) + 1;
                
                $todoData = json_encode(['completed' => false, 'todos' => $todos]);
                
                $stmt = $pdo->prepare("
                    INSERT INTO gibbonPlannerEntryStudentHomework 
                    (gibbonPlannerEntryStudentHomeworkID, gibbonPlannerEntryID, gibbonPersonID, homeworkDueDateTime, homeworkDetails, homeworkComplete)
                    VALUES (:id, :plannerEntryID, :personID, NOW(), :details, 'N')
                ");
                $stmt->execute([
                    'id' => $nextId,
                    'plannerEntryID' => $plannerEntryID,
                    'personID' => $gibbonPersonID,
                    'details' => $todoData
                ]);
            } else {
                // Mettre à jour seulement les todos, garder le statut completed
                $currentData = json_decode($existing['homeworkDetails'], true) ?: ['completed' => false, 'todos' => []];
                $currentData['todos'] = $todos;
                
                $stmt = $pdo->prepare("
                    UPDATE gibbonPlannerEntryStudentHomework 
                    SET homeworkDetails = :details
                    WHERE gibbonPlannerEntryID = :plannerEntryID 
                    AND gibbonPersonID = :personID
                ");
                $stmt->execute([
                    'details' => json_encode($currentData),
                    'plannerEntryID' => $plannerEntryID,
                    'personID' => $gibbonPersonID
                ]);
            }
            
            echo json_encode(['success' => true]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// 3️⃣ Récupération de tous les devoirs avec leur statut et todos
$assignments = $pdo->prepare("
    SELECT 
        p.gibbonPlannerEntryID,
        p.date, 
        p.homeworkDueDateTime, 
        p.name, 
        p.description, 
        u.name AS subject,
        sh.homeworkDetails
    FROM gibbonPlannerEntry p
    LEFT JOIN gibbonUnit u ON p.gibbonUnitID = u.gibbonUnitID
    LEFT JOIN gibbonPlannerEntryStudentHomework sh 
        ON p.gibbonPlannerEntryID = sh.gibbonPlannerEntryID 
        AND sh.gibbonPersonID = :personID
    WHERE p.homework='Y'
    ORDER BY p.homeworkDueDateTime ASC
");
$assignments->execute(['personID' => $gibbonPersonID]);
$assignments = $assignments->fetchAll(PDO::FETCH_ASSOC);

// Parser les todos pour chaque devoir
foreach ($assignments as &$assignment) {
    $details = json_decode($assignment['homeworkDetails'] ?? '{}', true);
    $assignment['todos'] = $details['todos'] ?? [];
    $assignment['isCompleted'] = $details['completed'] ?? false;
}
unset($assignment); // On casse la référence

// Calculer l'intervalle de jours pour chaque devoir pour une logique de date cohérente
$today = (new DateTime())->setTime(0, 0, 0);
foreach ($assignments as &$assignment) {
    $dueDate = (new DateTime($assignment['homeworkDueDateTime']))->setTime(0, 0, 0);
    $assignment['intervalDays'] = (int)$today->diff($dueDate)->format('%r%a');
}
unset($assignment);

// 4️⃣ Fonction pour la salutation
function getSalutation() {
    $hour = date('H');
    if ($hour >= 5 && $hour < 12) return 'Bonjour';
    if ($hour >= 12 && $hour < 18) return 'Bon après-midi';
    return 'Bonsoir';
}

// 5️⃣ Fonction pour calculer le texte "dans X jours"
function timeUntil($dateTime, $days) {
    $target = new DateTime($dateTime);
    
    $joursFrancais = [
        'Monday' => 'lundi',
        'Tuesday' => 'mardi',
        'Wednesday' => 'mercredi',
        'Thursday' => 'jeudi',
        'Friday' => 'vendredi',
        'Saturday' => 'samedi',
        'Sunday' => 'dimanche'
    ];
    
    if ($days < 0) return 'en retard';
    if ($days == 0) return 'aujourd\'hui';
    if ($days == 1) return 'demain';
    if ($days == 2) return 'après-demain';
    if ($days > 2 && $days <= 7) return $joursFrancais[$target->format('l')];
    if ($days > 7) return 'dans '.$days.' jours';
    return $days.' jours';
}

// 6️⃣ Fonction pour obtenir l'emoji de la matière
function getSubjectEmoji($subject) {
    $subject = strtolower($subject ?? '');
    if (strpos($subject, 'math') !== false) return '🔢';
    if (strpos($subject, 'français') !== false || strpos($subject, 'french') !== false) return '📚';
    if (strpos($subject, 'anglais') !== false || strpos($subject, 'english') !== false) return '🗣️';
    if (strpos($subject, 'Physique') !== false) return '🔬';
    if (strpos($subject, 'histoire') !== false || strpos($subject, 'history') !== false) return '📜';
    if (strpos($subject, 'géo') !== false || strpos($subject, 'geography') !== false) return '🌍';
    if (strpos($subject, 'sport') !== false || strpos($subject, 'eps') !== false) return '⚽';
    if (strpos($subject, 'art') !== false) return '🎨';
    if (strpos($subject, 'musique') !== false || strpos($subject, 'music') !== false) return '🎵';
    if (strpos($subject, 'FSEN') !== false || strpos($subject, 'computer') !== false) return '💻';
    return '📖';
}

// 🔹 Enregistrement du log de connexion
try {
    $stmt = $pdo->prepare("
        INSERT INTO Logs (username, login_datetime)
        VALUES (:username, NOW())
    ");
    $stmt->execute([
        'username' => $person['preferredName'] ?? $person['surname']
    ]);
} catch (Exception $e) {
    error_log("Erreur lors de l'ajout au journal : " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Mes devoirs - Gibbon</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background-color: #f8f9fa; /* Fond plus clair et neutre */
            min-height: 100vh;
            padding: 0;
            overflow-x: hidden;
        }

        .container {
            width: 100%;
            padding: 0;
            margin: 0;
        }

        .header {
            background: linear-gradient(135deg, rgba(154, 101, 229, 1) 0%, rgba(120, 80, 200, 1) 100%);
            padding: 24px 16px 32px 16px;
            box-shadow: 0 4px 20px rgba(154, 101, 229, 0.2);
            /* position: sticky; -- Supprimé pour une meilleure UX mobile */
            top: 0;
            z-index: 100;
            border-radius: 0 0 28px 28px; /* Bords arrondis plus doux */
        }

        .greeting {
            font-size: 24px;
            font-weight: 800;
            color: white;
            margin-bottom: 6px;
            line-height: 1.2;
        }

        .greeting-emoji {
            display: inline-block;
            animation: wave 1.5s ease-in-out infinite;
        }

        @keyframes wave {
            0%, 100% { transform: rotate(0deg); }
            25% { transform: rotate(20deg); }
            75% { transform: rotate(-20deg); }
        }

        .header-subtitle {
            color: rgba(255, 255, 255, 0.9);
            font-size: 14px;
            font-weight: 500;
        }

        .stats-bar {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px; /* Espacement augmenté */
            padding: 20px 16px; /* Padding vertical augmenté */
            background: transparent;
        }

        .stat-card {
            background: white;
            border-radius: 18px; /* Bords arrondis plus doux */
            padding: 16px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06); /* Ombre plus douce */
            display: flex;
            flex-direction: column;
            gap: 8px;
            transition: all 0.2s ease-out;
        }

        .stat-card:active {
            transform: scale(0.97);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .stat-card-header {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .stat-icon.urgent {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
        }

        .stat-icon.done {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
        }

        .stat-icon.upcoming {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
        }

        .stat-icon.total {
            background: linear-gradient(135deg, rgba(244, 239, 252, 1) 0%, rgba(221, 204, 245, 1) 100%);
        }

        .stat-content h3 {
            font-size: 28px;
            font-weight: 800;
            color: #1f2937;
            line-height: 1;
        }

        .stat-content p {
            font-size: 12px;
            color: #6b7280;
            font-weight: 600;
            line-height: 1.3;
        }

        .assignments-grid {
            padding: 0 16px 24px 16px;
            display: flex;
            flex-direction: column;
            gap: 20px; /* Espacement augmenté */
        }

        .assignment-card {
            background: white;
            border-radius: 24px; /* Bords arrondis plus doux */
            padding: 20px;
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.07); /* Ombre plus douce */
            transition: all 0.2s ease-out;
            border-left: 5px solid #e5e7eb;
            position: relative;
            overflow: hidden;
        }
        
        .assignment-card:active {
            transform: scale(0.98);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .assignment-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px; /* Ligne de couleur plus visible */
            background: linear-gradient(90deg, rgba(154, 101, 229, 0.3) 0%, rgba(154, 101, 229, 0.05) 100%);
        }

        .assignment-card.completed {
            opacity: 0.8; /* Moins transparent */
            background: #f8f9fa;
            border-left-color: #10b981;
            animation: completePulse 0.6s ease-out;
        }

        @keyframes completePulse {
            0% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.02);
                box-shadow: 0 8px 30px rgba(16, 185, 129, 0.2);
            }
            100% {
                transform: scale(1);
            }
        }

        .assignment-card.completed::before {
            background: linear-gradient(90deg, #10b981 0%, #6ee7b7 100%) !important;
        }

        .assignment-card.urgent {
            border-left-color: #ef4444;
            background: linear-gradient(135deg, #ffffff 0%, #fef2f2 100%);
        }

        .assignment-card.urgent::before {
            background: linear-gradient(90deg, #ef4444 0%, #fca5a5 100%);
        }

        .assignment-card.soon {
            border-left-color: #f59e0b;
            background: linear-gradient(135deg, #ffffff 0%, #fffbeb 100%);
        }

        .assignment-card.soon::before {
            background: linear-gradient(90deg, #f59e0b 0%, #fcd34d 100%);
        }

        .assignment-card.late {
            border-left-color: #dc2626;
            background: linear-gradient(135deg, #ffffff 0%, #fef2f2 100%);
        }

        .assignment-card.late::before {
            background: linear-gradient(90deg, #dc2626 0%, #ef4444 100%);
        }

        .assignment-header {
            display: flex;
            gap: 14px;
            margin-bottom: 16px;
        }

        .subject-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(154, 101, 229, 0.1) 0%, rgba(244, 239, 252, 0.5) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            flex-shrink: 0;
        }

        .assignment-info {
            flex: 1;
            min-width: 0;
        }

        .assignment-subject {
            font-size: 11px;
            font-weight: 700;
            color: rgba(154, 101, 229, 1);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 6px;
        }

        .assignment-title {
            font-size: 18px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 10px;
            line-height: 1.3;
            word-wrap: break-word;
        }

        .card-meta {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 16px;
        }

        .due-date-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            width: fit-content;
        }

        .due-date-badge.urgent {
            background: #fee2e2;
            color: #991b1b;
        }

        .due-date-badge.soon {
            background: #fef3c7;
            color: #92400e;
        }

        .due-date-badge.later {
            background: #e0e7ff;
            color: #3730a3;
        }

        .due-date-badge.late {
            background: #fee2e2;
            color: #7f1d1d;
        }

        .date-display {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #6b7280;
            font-size: 13px;
            font-weight: 600;
        }

        .assignment-description {
            padding-top: 16px;
            border-top: 2px solid #f3f4f6;
            color: #4b5563;
            line-height: 1.6;
            font-size: 14px;
            word-wrap: break-word;
            white-space: pre-wrap;
        }

        .add-to-calendar-btn {
            display: inline-block;
            margin-top: 16px;
            padding: 10px 16px;
            background: linear-gradient(135deg, #4285F4 0%, #3367D6 100%);
            color: white !important;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 2px 8px rgba(66, 133, 244, 0.2);
        }

        .add-to-calendar-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(66, 133, 244, 0.3);
        }

        .add-to-calendar-btn:active {
            transform: translateY(0);
        }

        /* Todo List Section */
        .todo-section {
            margin-top: 16px;
            padding: 16px;
            background: rgba(154, 101, 229, 0.03);
            border-radius: 12px;
            border: 2px dashed rgba(154, 101, 229, 0.2);
        }

        .todo-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .todo-title {
            font-size: 13px;
            font-weight: 700;
            color: rgba(154, 101, 229, 1);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .todo-count {
            background: rgba(154, 101, 229, 0.15);
            color: rgba(154, 101, 229, 1);
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
        }

        .todo-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .todo-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            background: white;
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .todo-item:hover {
            background: #f9fafb;
        }

        .todo-checkbox {
            width: 20px;
            height: 20px;
            border: 2px solid #d1d5db;
            border-radius: 6px;
            cursor: pointer;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .todo-checkbox.checked {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-color: #10b981;
        }

        .todo-checkbox.checked::after {
            content: '✓';
            color: white;
            font-size: 14px;
            font-weight: bold;
        }

        .todo-text {
            flex: 1;
            font-size: 14px;
            color: #374151;
            transition: all 0.2s ease;
        }

        .todo-item.completed .todo-text {
            text-decoration: line-through;
            color: #9ca3af;
        }

        .todo-delete {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            background: transparent;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #9ca3af;
            transition: all 0.2s ease;
            opacity: 0;
        }

        .todo-item:hover .todo-delete {
            opacity: 1;
        }

        .todo-delete:hover {
            background: #fee2e2;
            color: #dc2626;
        }

        .todo-input-container {
            display: flex;
            gap: 8px;
            margin-top: 10px;
        }

        .todo-input {
            flex: 1;
            padding: 10px 14px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.2s ease;
        }

        .todo-input:focus {
            outline: none;
            border-color: rgba(154, 101, 229, 0.5);
            background: white;
        }

        .todo-input::placeholder {
            color: #9ca3af;
        }

        .todo-add-btn {
            padding: 10px 16px;
            background: linear-gradient(135deg, rgba(154, 101, 229, 1) 0%, rgba(120, 80, 200, 1) 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .todo-add-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(154, 101, 229, 0.3);
        }

        .todo-add-btn:active {
            transform: translateY(0);
        }

        .toggle-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            background: rgba(154, 101, 229, 0.05);
            border-radius: 12px;
            margin-top: 16px;
        }

        .toggle-label {
            font-size: 14px;
            font-weight: 700;
            color: #374151;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .toggle-switch {
            position: relative;
            width: 56px;
            height: 32px;
            cursor: pointer;
            flex-shrink: 0;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #d1d5db;
            transition: .3s;
            border-radius: 32px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 24px;
            width: 24px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .toggle-switch input:checked + .toggle-slider {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .toggle-switch input:checked + .toggle-slider:before {
            transform: translateX(24px);
        }

        /* Animation confettis */
        @keyframes confetti-fall {
            0% {
                transform: translateY(-100vh) rotate(0deg);
                opacity: 1;
            }
            100% {
                transform: translateY(100vh) rotate(720deg);
                opacity: 0;
            }
        }

        .confetti {
            position: fixed;
            width: 10px;
            height: 10px;
            background: #10b981;
            pointer-events: none;
            z-index: 9999;
            animation: confetti-fall 3s linear forwards;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 2px 12px rgba(154, 101, 229, 0.08);
            margin: 16px;
        }

        .empty-state-icon {
            font-size: 56px;
            margin-bottom: 16px;
        }

        .empty-state h3 {
            font-size: 20px;
            color: #1f2937;
            margin-bottom: 8px;
            font-weight: 700;
        }

        .empty-state p {
            color: #6b7280;
            font-size: 15px;
        }

        @media (min-width: 600px) {
            .container {
                max-width: 720px;
                margin: 0 auto;
            }

            .header {
                padding: 32px 24px 40px 24px;
                border-radius: 0 0 24px 24px;
            }

            .greeting {
                font-size: 32px;
            }

            .header-subtitle {
                font-size: 16px;
            }

            .stats-bar {
                grid-template-columns: repeat(4, 1fr);
                gap: 16px;
                padding: 20px 24px;
            }

            .assignments-grid {
                padding: 0 24px 32px 24px;
                gap: 20px;
            }

            .assignment-card {
                padding: 24px;
            }

            .assignment-title {
                font-size: 20px;
            }
        }

        @media (min-width: 1024px) {
            .container {
                max-width: 1200px;
                padding: 0 40px;
            }

            .header {
                border-radius: 24px;
                margin: 24px 0;
                padding: 40px 48px;
            }

            .greeting {
                font-size: 40px;
            }

            .header-subtitle {
                font-size: 18px;
            }

            .stats-bar {
                padding: 32px 0;
                gap: 20px;
            }

            .stat-card {
                flex-direction: row;
                padding: 24px;
            }

            .stat-card:hover {
                transform: translateY(-4px);
                box-shadow: 0 8px 24px rgba(154, 101, 229, 0.15);
            }

            .stat-icon {
                width: 56px;
                height: 56px;
                font-size: 28px;
            }

            .stat-content h3 {
                font-size: 32px;
            }

            .stat-content p {
                font-size: 14px;
            }

            .assignments-grid {
                padding: 0 0 48px 0;
                gap: 24px;
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(480px, 1fr));
            }

            .assignment-card {
                padding: 28px;
            }

            .assignment-card:hover {
                transform: translateY(-4px);
                box-shadow: 0 12px 32px rgba(154, 101, 229, 0.15);
            }

            .subject-icon {
                width: 60px;
                height: 60px;
                font-size: 30px;
            }

            .assignment-title {
                font-size: 22px;
            }

            .assignment-description {
                font-size: 15px;
            }

            .toggle-container {
                margin-top: 20px;
                padding: 16px 20px;
            }

            .toggle-label {
                font-size: 15px;
            }
        }

        @media (min-width: 1440px) {
            .container {
                max-width: 1400px;
            }

            .assignments-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 class="greeting">
                <?= getSalutation() ?>, <?= htmlspecialchars($person['preferredName'] ?? $person['surname']) ?> <span class="greeting-emoji">👋</span>
            </h1>
            <p class="header-subtitle">Organisez votre travail et ne manquez aucune échéance</p>
        </div>

        <?php if (count($assignments) > 0): 
            // Calcul des statistiques
            $urgentCount = 0;
            $upcomingCount = 0;
            $completedCount = 0;
            $lateCount = 0;
            $now = new DateTime();
            
            foreach ($assignments as $a) {
                if ($a['isCompleted']) {
                    $completedCount++;
                } else {
                    $intervalDays = $a['intervalDays'];
                    
                    if ($intervalDays < 0) {
                        $lateCount++;
                    } elseif ($intervalDays <= 2) {
                        $urgentCount++;
                    } elseif ($intervalDays <= 7) {
                        $upcomingCount++;
                    }
                }
            }
        ?>

        <div class="stats-bar">
            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-icon urgent">⚡</div>
                    <div class="stat-content">
                        <h3><?= $urgentCount ?></h3>
                        <p>Urgent<br>(≤2 jours)</p>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-icon upcoming">📅</div>
                    <div class="stat-content">
                        <h3><?= $upcomingCount ?></h3>
                        <p>Cette<br>semaine</p>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-icon done">✅</div>
                    <div class="stat-content">
                        <h3><?= $completedCount ?></h3>
                        <p>Devoirs<br>terminés</p>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-icon total">📖</div>
                    <div class="stat-content">
                        <h3><?= count($assignments) ?></h3>
                        <p>Total des<br>devoirs</p>
                    </div>
                </div>
            </div>
        </div>

<?php
$displayAssignments = array_filter($assignments, function($a) {
    // On affiche si:
    // - Le devoir n'est pas complété
    // - OU le devoir est complété mais l'échéance est aujourd'hui ou dans le futur
    return !$a['isCompleted'] || $a['intervalDays'] >= 0;
});
?>
        <div class="assignments-grid">
            <?php foreach ($displayAssignments as $a): 
                $dueDate = new DateTime($a['homeworkDueDateTime']);
                $intervalDays = $a['intervalDays'];
                
                $urgencyClass = '';
                $badgeClass = 'later';
                
                if (!$a['isCompleted']) {
                    if ($intervalDays < 0) {
                        $urgencyClass = 'late';
                        $badgeClass = 'late';
                    } elseif ($intervalDays <= 2) {
                        $urgencyClass = 'urgent';
                        $badgeClass = 'urgent';
                    } elseif ($intervalDays <= 7) {
                        $urgencyClass = 'soon';
                        $badgeClass = 'soon';
                    }
                } else {
                    $urgencyClass = 'completed';
                }
                
                $emoji = getSubjectEmoji($a['subject']);
                $todos = $a['todos'];
                $completedTodos = count(array_filter($todos, fn($t) => $t['checked']));
            ?>
                <div class="assignment-card <?= $urgencyClass ?>" data-id="<?= $a['gibbonPlannerEntryID'] ?>">
                    <div class="assignment-header">
                        <div class="subject-icon"><?= $emoji ?></div>
                        <div class="assignment-info">
                            <div class="assignment-subject"><?= htmlspecialchars($a['subject'] ?? 'Matière') ?></div>
                            <div class="assignment-title"><?= htmlspecialchars($a['name']) ?></div>
                        </div>
                    </div>
                    
                    <div class="card-meta">
                        <span class="due-date-badge <?= $badgeClass ?>">
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <?= ucfirst(timeUntil($a['homeworkDueDateTime'], $a['intervalDays'])) ?>
                        </span>
                        <div class="date-display">
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                            Échéance : <?= $dueDate->format('d/m/Y à H:i') ?>
                        </div>
                    </div>
                    
                    <?php
                        $gcal_dueDate = new DateTime($a['homeworkDueDateTime']);
                        $gcal_start = $gcal_dueDate->format('Ymd\THis');
                        $gcal_end = (clone $gcal_dueDate)->modify('+1 hour')->format('Ymd\THis');

                        $gcal_title = urlencode(($a['subject'] ? $a['subject'] . ' - ' : '') . $a['name']);
                        
                        $gcal_desc_text = "Devoir à rendre : " . $a['name'] . "\n" .
                            "Matière : " . ($a['subject'] ?? 'Non spécifiée') . "\n";
                        if (!empty($a['description'])) {
                            $gcal_desc_text .= "Description : " . $a['description'] . "\n\n";
                        }
                        $gcal_desc_text .= "🔔 N'oubliez pas d'ajouter des rappels dans Google Calendar (par ex. 2 jours et 1 jour avant l'échéance).";
                        $gcal_desc = urlencode($gcal_desc_text);

                        $googleCalendarUrl = "https://calendar.google.com/calendar/event?action=TEMPLATE"
                            . "&text={$gcal_title}"
                            . "&details={$gcal_desc}"
                            . "&dates={$gcal_start}/{$gcal_end}";
                    ?>
                    <a href="<?= $googleCalendarUrl ?>" target="_blank" class="add-to-calendar-btn">
                        🗓️ Ajouter à Google Calendar
                    </a>
                    
                    <?php if (!empty($a['description'])): ?>
                    <div class="assignment-description">
                        <?= nl2br(htmlspecialchars($a['description'])) ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Todo List Section -->
                    <div class="todo-section">
                        <div class="todo-header">
                            <span class="todo-title">
                                📝 Ma liste de tâches
                                <?php if (count($todos) > 0): ?>
                                    <span class="todo-count"><?= $completedTodos ?>/<?= count($todos) ?></span>
                                <?php endif; ?>
                            </span>
                        </div>
                        
                        <div class="todo-list" data-planner-id="<?= $a['gibbonPlannerEntryID'] ?>">
                            <?php foreach ($todos as $index => $todo): ?>
                                <div class="todo-item <?= $todo['checked'] ? 'completed' : '' ?>" data-index="<?= $index ?>">
                                    <div class="todo-checkbox <?= $todo['checked'] ? 'checked' : '' ?>"></div>
                                    <span class="todo-text"><?= htmlspecialchars($todo['text']) ?></span>
                                    <button class="todo-delete" aria-label="Supprimer">
                                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="todo-input-container">
                            <input type="text" class="todo-input" placeholder="Ajouter une tâche..." data-planner-id="<?= $a['gibbonPlannerEntryID'] ?>">
                            <button class="todo-add-btn" data-planner-id="<?= $a['gibbonPlannerEntryID'] ?>">Ajouter</button>
                        </div>
                    </div>
                    
                    <div class="toggle-container">
                        <span class="toggle-label">
                            <?= $a['isCompleted'] ? '✅ Devoir terminé' : '📝 Marquer comme fait' ?>
                        </span>
                        <label class="toggle-switch">
                            <input type="checkbox" 
                                   class="homework-toggle" 
                                   data-planner-id="<?= $a['gibbonPlannerEntryID'] ?>"
                                   <?= $a['isCompleted'] ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon">🎉</div>
                <h3>Aucun devoir pour le moment</h3>
                <p>Profitez de ce moment de répit !</p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Gestion des todos
        function saveTodos(plannerId) {
            const todoList = document.querySelector(`.todo-list[data-planner-id="${plannerId}"]`);
            const todos = [];
            
            todoList.querySelectorAll('.todo-item').forEach(item => {
                const text = item.querySelector('.todo-text').textContent;
                const checked = item.classList.contains('completed');
                todos.push({ text, checked });
            });
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=save_todos&plannerEntryID=${plannerId}&todos=${encodeURIComponent(JSON.stringify(todos))}`
            })
            .then(response => response.json())
            .then(result => {
                if (!result.success) {
                    console.error('Erreur lors de la sauvegarde:', result.message);
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
            });
        }
        
        // Ajouter une tâche
        document.querySelectorAll('.todo-add-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const plannerId = this.dataset.plannerId;
                const input = document.querySelector(`.todo-input[data-planner-id="${plannerId}"]`);
                const text = input.value.trim();
                
                if (text) {
                    const todoList = document.querySelector(`.todo-list[data-planner-id="${plannerId}"]`);
                    const newItem = document.createElement('div');
                    newItem.className = 'todo-item';
                    newItem.innerHTML = `
                        <div class="todo-checkbox"></div>
                        <span class="todo-text">${text}</span>
                        <button class="todo-delete" aria-label="Supprimer">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    `;
                    
                    todoList.appendChild(newItem);
                    input.value = '';
                    
                    saveTodos(plannerId);
                    updateTodoCount(plannerId);
                }
            });
        });
        
        // Entrée pour ajouter une tâche
        document.querySelectorAll('.todo-input').forEach(input => {
            input.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    const plannerId = this.dataset.plannerId;
                    const btn = document.querySelector(`.todo-add-btn[data-planner-id="${plannerId}"]`);
                    btn.click();
                }
            });
        });
        
        // Toggle checkbox et suppression
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('todo-checkbox') || e.target.closest('.todo-checkbox')) {
                const checkbox = e.target.classList.contains('todo-checkbox') ? e.target : e.target.closest('.todo-checkbox');
                const item = checkbox.closest('.todo-item');
                const todoList = item.closest('.todo-list');
                const plannerId = todoList.dataset.plannerId;
                
                checkbox.classList.toggle('checked');
                item.classList.toggle('completed');
                
                saveTodos(plannerId);
                updateTodoCount(plannerId);
            }
            
            if (e.target.closest('.todo-delete')) {
                const item = e.target.closest('.todo-item');
                const todoList = item.closest('.todo-list');
                const plannerId = todoList.dataset.plannerId;
                
                item.style.transform = 'translateX(-100%)';
                item.style.opacity = '0';
                
                setTimeout(() => {
                    item.remove();
                    saveTodos(plannerId);
                    updateTodoCount(plannerId);
                }, 300);
            }
        });
        
        // Mettre à jour le compteur
        function updateTodoCount(plannerId) {
            const card = document.querySelector(`.assignment-card[data-id="${plannerId}"]`);
            const todoList = card.querySelector('.todo-list');
            const items = todoList.querySelectorAll('.todo-item');
            const completed = todoList.querySelectorAll('.todo-item.completed').length;
            const total = items.length;
            
            let countSpan = card.querySelector('.todo-count');
            if (total > 0) {
                if (!countSpan) {
                    countSpan = document.createElement('span');
                    countSpan.className = 'todo-count';
                    card.querySelector('.todo-title').appendChild(countSpan);
                }
                countSpan.textContent = `${completed}/${total}`;
            } else if (countSpan) {
                countSpan.remove();
            }
        }
        
        // Gestion des toggles de devoirs
        document.querySelectorAll('.homework-toggle').forEach(toggle => {
            toggle.addEventListener('change', async function() {
                const plannerId = this.dataset.plannerId;
                const isCompleted = this.checked;
                const card = this.closest('.assignment-card');
                const label = card.querySelector('.toggle-label');
                
                if (isCompleted) {
                    label.textContent = '✅ Devoir terminé';
                    // Animation confettis
                    createConfetti(card);
                } else {
                    label.textContent = '📝 Marquer comme fait';
                }
                
                try {
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=toggle_homework&plannerEntryID=${plannerId}&isCompleted=${isCompleted}`
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        if (isCompleted) {
                            card.classList.add('completed');
                            card.classList.remove('urgent', 'soon', 'late');
                        } else {
                            card.classList.remove('completed');
                        }
                        
                        setTimeout(() => location.reload(), 1200);
                    } else {
                        alert('Erreur lors de la mise à jour : ' + result.message);
                        this.checked = !isCompleted;
                        label.textContent = isCompleted ? '📝 Marquer comme fait' : '✅ Devoir terminé';
                    }
                } catch (error) {
                    console.error('Erreur:', error);
                    alert('Erreur de connexion');
                    this.checked = !isCompleted;
                    label.textContent = isCompleted ? '📝 Marquer comme fait' : '✅ Devoir terminé';
                }
            });
        });
        
        // Fonction pour créer des confettis
        function createConfetti(card) {
            const colors = ['#10b981', '#059669', '#34d399', '#6ee7b7', '#a7f3d0'];
            const cardRect = card.getBoundingClientRect();
            const centerX = cardRect.left + cardRect.width / 2;
            const centerY = cardRect.top + cardRect.height / 2;
            
            for (let i = 0; i < 30; i++) {
                setTimeout(() => {
                    const confetti = document.createElement('div');
                    confetti.className = 'confetti';
                    confetti.style.left = centerX + 'px';
                    confetti.style.top = centerY + 'px';
                    confetti.style.background = colors[Math.floor(Math.random() * colors.length)];
                    confetti.style.transform = `translateX(${(Math.random() - 0.5) * 200}px)`;
                    confetti.style.animationDelay = Math.random() * 0.3 + 's';
                    confetti.style.animationDuration = (Math.random() * 2 + 2) + 's';
                    
                    document.body.appendChild(confetti);
                    
                    setTimeout(() => confetti.remove(), 3000);
                }, i * 20);
            }
        }
        
        // Animations au chargement
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.assignment-card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    card.style.transition = 'all 0.5s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
    </script>
</body>
</html>
