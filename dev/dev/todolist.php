<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 🔹 Connexion à la base Gibbon
try {
    // Create a config.php file with your database credentials.
$config = include __DIR__ . '/config.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Exception $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

// 🔹 Récupération de la session utilisateur
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

$stmt = $pdo->prepare("SELECT gibbonPersonID FROM gibbonSession WHERE gibbonSessionID = :s LIMIT 1");
$stmt->execute(['s' => $sessionValue]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    die("Session invalide.");
}

$userID = $session['gibbonPersonID'];

// Récupération du nom d'utilisateur
$stmt = $pdo->prepare("SELECT username, preferredName FROM gibbonPerson WHERE gibbonPersonID = :id");
$stmt->execute(['id' => $userID]);
$userInfo = $stmt->fetch(PDO::FETCH_ASSOC);
$userName = $userInfo['preferredName'] ?? $userInfo['username'] ?? 'Utilisateur';

// 🔹 Modification de la table pour ajouter les nouvelles colonnes (si nécessaire)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS gibbonTodo (
            id INT AUTO_INCREMENT PRIMARY KEY,
            gibbonPersonID INT NOT NULL,
            task VARCHAR(255) NOT NULL,
            isDone BOOLEAN NOT NULL DEFAULT FALSE,
            createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // Ajout des colonnes si elles n'existent pas
    $columns = ['priority', 'dueDate', 'category', 'notes'];
    foreach ($columns as $col) {
        try {
            if ($col === 'priority') {
                $pdo->exec("ALTER TABLE gibbonTodo ADD COLUMN $col VARCHAR(20) DEFAULT 'medium'");
            } elseif ($col === 'dueDate') {
                $pdo->exec("ALTER TABLE gibbonTodo ADD COLUMN $col DATE NULL");
            } elseif ($col === 'category') {
                $pdo->exec("ALTER TABLE gibbonTodo ADD COLUMN $col VARCHAR(50) DEFAULT 'general'");
            } elseif ($col === 'notes') {
                $pdo->exec("ALTER TABLE gibbonTodo ADD COLUMN $col TEXT NULL");
            }
        } catch (Exception $e) {
            // Colonne existe déjà, on continue
        }
    }
} catch (Exception $e) {
    // Table existe déjà
}

$feedback = null;

// 🔹 Gestion des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'add':
                    if (!empty($_POST['task'])) {
                        $priority = $_POST['priority'] ?? 'medium';
                        $dueDate = !empty($_POST['dueDate']) ? $_POST['dueDate'] : null;
                        $category = $_POST['category'] ?? 'Autre';
                        
                        $stmt = $pdo->prepare("INSERT INTO gibbonTodo (gibbonPersonID, task, priority, dueDate, category) VALUES (:userID, :task, :priority, :dueDate, :category)");
                        $stmt->execute([
                            'userID' => $userID, 
                            'task' => $_POST['task'],
                            'priority' => $priority,
                            'dueDate' => $dueDate,
                            'category' => $category
                        ]);
                    }
                    break;
                case 'toggle':
                    if (isset($_POST['id'])) {
                        $stmt = $pdo->prepare("UPDATE gibbonTodo SET isDone = !isDone WHERE id = :id AND gibbonPersonID = :userID");
                        $stmt->execute(['id' => $_POST['id'], 'userID' => $userID]);
                    }
                    break;
                case 'delete':
                    if (isset($_POST['id'])) {
                        $stmt = $pdo->prepare("DELETE FROM gibbonTodo WHERE id = :id AND gibbonPersonID = :userID");
                        $stmt->execute(['id' => $_POST['id'], 'userID' => $userID]);
                    }
                    break;
                case 'update':
                    if (isset($_POST['id'])) {
                        $stmt = $pdo->prepare("UPDATE gibbonTodo SET task = :task, priority = :priority, dueDate = :dueDate, category = :category, notes = :notes WHERE id = :id AND gibbonPersonID = :userID");
                        $stmt->execute([
                            'id' => $_POST['id'],
                            'userID' => $userID,
                            'task' => $_POST['task'],
                            'priority' => $_POST['priority'],
                            'dueDate' => !empty($_POST['dueDate']) ? $_POST['dueDate'] : null,
                            'category' => $_POST['category'],
                            'notes' => $_POST['notes'] ?? ''
                        ]);
                    }
                    break;
            }
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } catch (Exception $e) {
        $feedback = ['type' => 'error', 'message' => "Une erreur est survenue : " . $e->getMessage()];
    }
}

// 🔹 Récupération des tâches avec filtres
$filter = $_GET['filter'] ?? 'all';
$sortBy = $_GET['sort'] ?? 'date';

$query = "SELECT * FROM gibbonTodo WHERE gibbonPersonID = :userID";

if ($filter === 'active') {
    $query .= " AND isDone = 0";
} elseif ($filter === 'completed') {
    $query .= " AND isDone = 1";
} elseif ($filter === 'today') {
    $query .= " AND dueDate = CURDATE()";
} elseif ($filter === 'overdue') {
    $query .= " AND dueDate < CURDATE() AND isDone = 0";
}

if ($sortBy === 'priority') {
    $query .= " ORDER BY FIELD(priority, 'high', 'medium', 'low'), createdAt DESC";
} elseif ($sortBy === 'dueDate') {
    $query .= " ORDER BY dueDate ASC, createdAt DESC";
} else {
    $query .= " ORDER BY createdAt DESC";
}

$todos = [];
try {
    $stmt = $pdo->prepare($query);
    $stmt->execute(['userID' => $userID]);
    $todos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $feedback = ['type' => 'error', 'message' => "Erreur : " . $e->getMessage()];
}

// Statistiques
$stats = [
    'total' => 0,
    'active' => 0,
    'completed' => 0,
    'overdue' => 0
];

try {
    $stmt = $pdo->prepare("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN isDone = 0 THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN isDone = 1 THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN dueDate < CURDATE() AND isDone = 0 THEN 1 ELSE 0 END) as overdue
        FROM gibbonTodo WHERE gibbonPersonID = :userID");
    $stmt->execute(['userID' => $userID]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mes Devoirs</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: 'Plus Jakarta Sans', sans-serif;
        background: linear-gradient(to bottom right, #fef3e2, #fde8d0);
        min-height: 100vh;
        padding: 20px;
        color: #2d3748;
    }
    .container {
        max-width: 1000px;
        margin: 0 auto;
    }
    .header {
        margin-bottom: 40px;
    }
    .header h1 {
        font-size: 32px;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 6px;
    }
    .header p {
        font-size: 16px;
        color: #8b5a3c;
        font-weight: 500;
    }
    
    /* Stats */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 16px;
        margin-bottom: 28px;
    }
    .stat-card {
        background: white;
        border: 1px solid #f5e6d3;
        border-radius: 12px;
        padding: 20px;
        transition: all 0.2s ease;
        box-shadow: 0 2px 4px rgba(218, 165, 32, 0.08);
    }
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(218, 165, 32, 0.12);
    }
    .stat-card h3 {
        font-size: 28px;
        font-weight: 700;
        color: #d97706;
        margin-bottom: 4px;
    }
    .stat-card p {
        font-size: 13px;
        color: #8b5a3c;
        font-weight: 500;
    }
    
    /* Main Content */
    .main-content {
        display: grid;
        grid-template-columns: 1fr;
        gap: 20px;
    }
    @media (min-width: 768px) {
        .main-content {
            grid-template-columns: 2fr 1fr;
        }
    }
    
    .card {
        background: white;
        border: 1px solid #f5e6d3;
        border-radius: 12px;
        padding: 24px;
        box-shadow: 0 2px 4px rgba(218, 165, 32, 0.08);
    }
    
    .card-title {
        font-size: 20px;
        font-weight: 700;
        color: #2d3748;
        margin-bottom: 20px;
    }
    
    /* Add Task Form */
    .add-form {
        display: grid;
        gap: 12px;
    }
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }
    .form-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .form-group.full {
        grid-column: 1 / -1;
    }
    .form-group label {
        font-size: 13px;
        font-weight: 500;
        color: #5a4a3a;
    }
    .form-group input,
    .form-group select {
        padding: 11px 14px;
        border: 2px solid #f5e6d3;
        border-radius: 8px;
        font-size: 14px;
        font-family: 'Plus Jakarta Sans', sans-serif;
        transition: all 0.2s;
        background: white;
    }
    .form-group input:focus,
    .form-group select:focus {
        outline: none;
        border-color: #d97706;
        box-shadow: 0 0 0 3px rgba(217, 119, 6, 0.1);
    }
    .btn-primary {
        padding: 12px 24px;
        background: #d97706;
        color: white;
        border: none;
        border-radius: 8px;
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        box-shadow: 0 2px 4px rgba(217, 119, 6, 0.2);
    }
    .btn-primary:hover {
        background: #b45309;
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(217, 119, 6, 0.3);
    }
    .btn-primary:active {
        transform: translateY(0);
    }
    
    /* Filters */
    .filters {
        display: flex;
        gap: 8px;
        margin-bottom: 20px;
        flex-wrap: wrap;
        border-bottom: 2px solid #fde8d0;
        padding-bottom: 12px;
    }
    .filter-btn {
        padding: 8px 16px;
        background: transparent;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
        color: #8b5a3c;
    }
    .filter-btn:hover {
        background: #fef3e2;
        color: #d97706;
    }
    .filter-btn.active {
        background: #d97706;
        color: white;
        box-shadow: 0 2px 4px rgba(217, 119, 6, 0.25);
    }
    
    /* Task List */
    .task-list {
        list-style: none;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .task-item {
        background: #fffbf5;
        border: 2px solid #f5e6d3;
        border-radius: 10px;
        padding: 16px;
        transition: all 0.2s ease;
    }
    .task-item:hover {
        border-color: #e6d3be;
        background: white;
        box-shadow: 0 2px 6px rgba(217, 119, 6, 0.08);
    }
    .task-item.priority-high {
        border-left: 4px solid #dc2626;
        background: linear-gradient(to right, rgba(220, 38, 38, 0.04), #fffbf5);
    }
    .task-item.priority-medium {
        border-left: 4px solid #ea580c;
        background: linear-gradient(to right, rgba(234, 88, 12, 0.04), #fffbf5);
    }
    .task-item.priority-low {
        border-left: 4px solid #16a34a;
        background: linear-gradient(to right, rgba(22, 163, 74, 0.04), #fffbf5);
    }
    .task-item.done {
        opacity: 0.5;
    }
    .task-item.done .task-text {
        text-decoration: line-through;
    }
    .task-item.overdue:not(.done) {
        background: linear-gradient(to right, rgba(220, 38, 38, 0.06), #fff5f5);
        border-color: #fecaca;
    }
    @keyframes pulse-border {
        0%, 100% { border-color: #fecaca; }
        50% { border-color: #fca5a5; }
    }
    .task-header {
        display: flex;
        align-items: flex-start;
        gap: 12px;
    }
    .task-checkbox {
        width: 20px;
        height: 20px;
        cursor: pointer;
        flex-shrink: 0;
        margin-top: 2px;
        accent-color: #d97706;
    }
    .task-content {
        flex-grow: 1;
    }
    .task-text {
        font-size: 15px;
        font-weight: 500;
        color: #1a1a1a;
        margin-bottom: 6px;
    }
    .task-meta {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        font-size: 12px;
    }
    .meta-badge {
        padding: 4px 10px;
        border-radius: 6px;
        font-weight: 600;
        background: #fef3e2;
        color: #8b5a3c;
        font-size: 12px;
    }
    .meta-badge.priority-high {
        background: #fee2e2;
        color: #991b1b;
    }
    .meta-badge.priority-medium {
        background: #fed7aa;
        color: #9a3412;
    }
    .meta-badge.priority-low {
        background: #d1fae5;
        color: #065f46;
    }
    .meta-badge.overdue {
        background: #fee2e2;
        color: #991b1b;
    }
    @keyframes pulse-badge {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.7; }
    }
    .task-actions {
        display: flex;
        gap: 4px;
        flex-shrink: 0;
    }
    .btn-icon {
        background: none;
        border: none;
        width: 32px;
        height: 32px;
        border-radius: 6px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        transition: all 0.2s;
        color: #8b5a3c;
    }
    .btn-icon:hover {
        background: #fef3e2;
        color: #d97706;
    }
    .btn-icon.delete:hover {
        background: #fee2e2;
        color: #dc2626;
    }
    
    /* Modal */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.4);
        z-index: 1000;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    .modal.show {
        display: flex;
    }
    .modal-content {
        background: white;
        border-radius: 12px;
        padding: 28px;
        max-width: 500px;
        width: 100%;
        max-height: 90vh;
        overflow-y: auto;
        border: 1px solid #f5e6d3;
        box-shadow: 0 10px 25px rgba(217, 119, 6, 0.15);
    }
    @keyframes slideUp {
        from {
            transform: translateY(20px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    .modal-header h3 {
        font-size: 18px;
        font-weight: 600;
        color: #2d3748;
    }
    .modal-close {
        background: none;
        border: none;
        font-size: 24px;
        cursor: pointer;
        color: #8b5a3c;
        line-height: 1;
        padding: 0;
        width: 28px;
        height: 28px;
    }
    .modal-close:hover {
        color: #2d3748;
    }
    
    .no-tasks {
        text-align: center;
        padding: 60px 20px;
        color: #999;
    }
    .no-tasks-icon {
        font-size: 48px;
        margin-bottom: 12px;
    }
    
    .feedback {
        padding: 12px 16px;
        border-radius: 6px;
        margin-bottom: 16px;
        text-align: center;
        font-size: 14px;
    }
    .feedback.error {
        background-color: #fee2e2;
        color: #991b1b;
        border: 1px solid #fecaca;
    }
    
    textarea {
        width: 100%;
        padding: 11px 14px;
        border: 2px solid #f5e6d3;
        border-radius: 8px;
        font-family: 'Plus Jakarta Sans', sans-serif;
        resize: vertical;
        font-size: 14px;
        transition: all 0.2s;
    }
    textarea:focus {
        outline: none;
        border-color: #d97706;
        box-shadow: 0 0 0 3px rgba(217, 119, 6, 0.1);
    }
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Mes Devoirs</h1>
        <p>Bonjour, <?php echo htmlspecialchars($userName); ?></p>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <h3><?php echo $stats['total']; ?></h3>
            <p>Total</p>
        </div>
        <div class="stat-card">
            <h3><?php echo $stats['active']; ?></h3>
            <p>En cours</p>
        </div>
        <div class="stat-card">
            <h3><?php echo $stats['completed']; ?></h3>
            <p>Terminés</p>
        </div>
        <div class="stat-card">
            <h3><?php echo $stats['overdue']; ?></h3>
            <p>En retard</p>
        </div>
    </div>

    <div class="main-content">
        <!-- Liste des tâches -->
        <div class="card">
            <h2 class="card-title">Devoirs</h2>
            
            <?php if ($feedback): ?>
                <div class="feedback <?php echo $feedback['type']; ?>">
                    <?php echo htmlspecialchars($feedback['message']); ?>
                </div>
            <?php endif; ?>
            
            <div class="filters">
                <a href="?filter=all&sort=<?php echo $sortBy; ?>" class="filter-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">Tous</a>
                <a href="?filter=active&sort=<?php echo $sortBy; ?>" class="filter-btn <?php echo $filter === 'active' ? 'active' : ''; ?>">À faire</a>
                <a href="?filter=completed&sort=<?php echo $sortBy; ?>" class="filter-btn <?php echo $filter === 'completed' ? 'active' : ''; ?>">Terminés</a>
                <a href="?filter=today&sort=<?php echo $sortBy; ?>" class="filter-btn <?php echo $filter === 'today' ? 'active' : ''; ?>">Aujourd'hui</a>
                <a href="?filter=overdue&sort=<?php echo $sortBy; ?>" class="filter-btn <?php echo $filter === 'overdue' ? 'active' : ''; ?>">En retard</a>
            </div>
            
            <ul class="task-list">
                <?php if (empty($todos)): ?>
                    <li class="no-tasks">
                        <div class="no-tasks-icon">📚</div>
                        <p>Aucun devoir</p>
                    </li>
                <?php else: ?>
                    <?php foreach ($todos as $todo): 
                        $isOverdue = !$todo['isDone'] && $todo['dueDate'] && $todo['dueDate'] < date('Y-m-d');
                        $priorityClass = 'priority-' . ($todo['priority'] ?? 'medium');
                    ?>
                        <li class="task-item <?php echo $priorityClass; ?> <?php echo $todo['isDone'] ? 'done' : ''; ?> <?php echo $isOverdue ? 'overdue' : ''; ?>">
                            <div class="task-header">
                                <form method="POST" style="display: contents;">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?php echo $todo['id']; ?>">
                                    <input class="task-checkbox" type="checkbox" <?php echo $todo['isDone'] ? 'checked' : ''; ?> onchange="this.form.submit()">
                                </form>
                                
                                <div class="task-content">
                                    <div class="task-text"><?php echo htmlspecialchars($todo['task']); ?></div>
                                    <div class="task-meta">
                                        <?php if ($todo['category']): ?>
                                            <span class="meta-badge"><?php echo htmlspecialchars($todo['category']); ?></span>
                                        <?php endif; ?>
                                        <span class="meta-badge <?php echo $priorityClass; ?>">
                                            <?php echo ucfirst($todo['priority'] ?? 'medium'); ?>
                                        </span>
                                        <?php if ($todo['dueDate']): ?>
                                            <span class="meta-badge <?php echo $isOverdue ? 'overdue' : ''; ?>">
                                                <?php 
                                                $date = new DateTime($todo['dueDate']);
                                                echo $date->format('d/m/Y');
                                                ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="task-actions">
                                    <button type="button" class="btn-icon edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($todo)); ?>)">✏️</button>
                                    <form method="POST" style="display: contents;">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $todo['id']; ?>">
                                        <button type="submit" class="btn-icon delete" onclick="return confirm('Supprimer ce devoir ?')">🗑️</button>
                                    </form>
                                </div>
                            </div>
                            <?php if (!empty($todo['notes'])): ?>
                                <div style="margin-top: 8px; padding-left: 30px; font-size: 13px; color: #666;">
                                    <?php echo nl2br(htmlspecialchars($todo['notes'])); ?>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
        
        <!-- Formulaire d'ajout -->
        <div class="card">
            <h2 class="card-title">Nouveau Devoir</h2>
            <form class="add-form" method="POST">
                <input type="hidden" name="action" value="add">
                
                <div class="form-group full">
                    <label>Devoir</label>
                    <input type="text" name="task" placeholder="Ex: Exercices page 42" required>
                </div>
                
                <div class="form-group">
                    <label>Matière</label>
                    <select name="category">
                        <option value="FSEN">FSEN</option>
                        <option value="Anglais">Anglais</option>
                        <option value="Physique">Physique</option>
                        <option value="C&C">C&C</option>
                        <option value="Autom">Autom</option>
                        <option value="Math">Math</option>
                        <option value="SYEL">SYEL</option>
                        <option value="Autre">Autre</option>
                    </select>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Priorité</label>
                        <select name="priority">
                            <option value="low">Basse</option>
                            <option value="medium" selected>Moyenne</option>
                            <option value="high">Haute</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>À rendre le</label>
                        <input type="date" name="dueDate" min="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
                
                <button type="submit" class="btn-primary">Ajouter</button>
            </form>
        </div>
    </div>
</div>

<!-- Modal d'édition -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Modifier le devoir</h3>
            <button type="button" class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <form method="POST" class="add-form">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit-id">
            
            <div class="form-group full">
                <label>Devoir</label>
                <input type="text" name="task" id="edit-task" required>
            </div>
            
            <div class="form-group">
                <label>Matière</label>
                <select name="category" id="edit-category">
                    <option value="FSEN">FSEN</option>
                    <option value="Anglais">Anglais</option>
                    <option value="Physique">Physique</option>
                    <option value="C&C">C&C</option>
                    <option value="Autom">Autom</option>
                    <option value="Math">Math</option>
                    <option value="SYEL">SYEL</option>
                    <option value="Autre">Autre</option>
                </select>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Priorité</label>
                    <select name="priority" id="edit-priority">
                        <option value="low">Basse</option>
                        <option value="medium">Moyenne</option>
                        <option value="high">Haute</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>À rendre le</label>
                    <input type="date" name="dueDate" id="edit-dueDate" min="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>
            
            <div class="form-group full">
                <label>Notes</label>
                <textarea name="notes" id="edit-notes" rows="3"></textarea>
            </div>
            
            <button type="submit" class="btn-primary">Enregistrer</button>
        </form>
    </div>
</div>

<script>
// Vérification de la session toutes les 2 minutes
setInterval(function() {
    fetch(window.location.href, {
        method: 'HEAD',
        cache: 'no-cache'
    }).catch(function() {
        // En cas d'erreur, rediriger vers la page de connexion Gibbon
        window.location.href = 'YOUR_GIBBON_URL/index.php';
    });
}, 120000); // 120000 ms = 2 minutes

function openEditModal(todo) {
    document.getElementById('edit-id').value = todo.id;
    document.getElementById('edit-task').value = todo.task;
    document.getElementById('edit-priority').value = todo.priority || 'medium';
    document.getElementById('edit-dueDate').value = todo.dueDate || '';
    document.getElementById('edit-category').value = todo.category || 'Autre';
    document.getElementById('edit-notes').value = todo.notes || '';
    document.getElementById('editModal').classList.add('show');
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('show');
}

// Fermer la modal en cliquant en dehors
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeEditModal();
    }
});

// Raccourci clavier ESC pour fermer
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeEditModal();
    }
});
</script>

</body>
</html>
