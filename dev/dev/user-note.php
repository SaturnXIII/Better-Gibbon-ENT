<?php

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
$userName = $userInfo['preferredName'] ?? $userInfo['username'] ?? 'Étudiant';

// Déterminer le moment de la journée
$hour = date('H');
if ($hour < 12) {
    $greeting = "Bonjour";
} elseif ($hour < 18) {
    $greeting = "Bon après-midi";
} else {
    $greeting = "Bonsoir";
}

// 🔹 Table des coefficients par couleur
$coefs = [
    '#86efac' => 1,
    '#cdb4db' => 2,
    '#ffc8dd' => 3,
    '#a2d2ff' => 4,
    '#99582a' => 5,
];

// 🔹 Récupération des contrôles avec matière
$columns = $pdo->query("
    SELECT 
        c.gibbonMarkbookColumnID, 
        c.name, 
        c.date, 
        c.columnColor,
        c.gibbonUnitID,
        u.name as matiere
    FROM gibbonMarkbookColumn c
    LEFT JOIN gibbonUnit u ON c.gibbonUnitID = u.gibbonUnitID
    WHERE c.date IS NOT NULL
    ORDER BY c.date ASC
")->fetchAll(PDO::FETCH_ASSOC);

$data = [];
$userNotes = [];
$dataParMatiere = [];

// 🔹 Pour chaque contrôle
foreach ($columns as $col) {
    $coef = $coefs[strtolower($col['columnColor'])] ?? 1;
    $matiere = $col['matiere'] ?? 'Non classé';

    // Note élève
    $stmt = $pdo->prepare("
        SELECT attainmentValueRaw 
        FROM gibbonMarkbookEntry 
        WHERE gibbonPersonIDStudent = :id AND gibbonMarkbookColumnID = :colID
        LIMIT 1
    ");
    $stmt->execute(['id' => $userID, 'colID' => $col['gibbonMarkbookColumnID']]);
    $noteEleve = $stmt->fetchColumn();

    // Moyenne classe
    $stmt = $pdo->prepare("
        SELECT attainmentValueRaw 
        FROM gibbonMarkbookEntry 
        WHERE gibbonMarkbookColumnID = :colID AND attainmentValueRaw IS NOT NULL
    ");
    $stmt->execute(['colID' => $col['gibbonMarkbookColumnID']]);
    $notesClasseBrutes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $notesClasse = array_filter(array_map(function($note) {
        $noteSanitized = str_replace(',', '.', (string)$note);
        return is_numeric($noteSanitized) ? floatval($noteSanitized) : null;
    }, $notesClasseBrutes));

    $moyClasse = count($notesClasse) > 0 ? array_sum($notesClasse) / count($notesClasse) : null;

    $dataEntry = [
        'name' => $col['name'],
        'date' => $col['date'],
        'coef' => $coef,
        'noteEleve' => $noteEleve !== null ? round($noteEleve, 2) : null,
        'moyClasse' => $moyClasse !== null ? round($moyClasse, 2) : null,
        'matiere' => $matiere
    ];

    $data[] = $dataEntry;

    // Organiser par matière
    if (!isset($dataParMatiere[$matiere])) {
        $dataParMatiere[$matiere] = [];
    }
    $dataParMatiere[$matiere][] = $dataEntry;

    if ($noteEleve !== null) {
        $userNotes[] = ['note' => floatval($noteEleve), 'coef' => $coef];
    }
}

// 🔹 Calcul moyenne pondérée élève
$userTotal = $userCoef = 0;
foreach ($userNotes as $n) {
    $userTotal += $n['note'] * $n['coef'];
    $userCoef += $n['coef'];
}
$moyEleve = $userCoef > 0 ? round($userTotal / $userCoef, 2) : null;

// 🔹 Calcul moyenne pondérée classe
$classTotal = $classCoef = 0;
foreach ($data as $d) {
    if ($d['moyClasse'] !== null) {
        $classTotal += $d['moyClasse'] * $d['coef'];
        $classCoef += $d['coef'];
    }
}
$moyClasse = $classCoef > 0 ? round($classTotal / $classCoef, 2) : null;

// 🔹 Calcul de la tendance globale
$notesChronological = array_filter(array_map(fn($d) => $d['noteEleve'], $data));
$notesChronological = array_values($notesChronological);
$nbNotes = count($notesChronological);

$tendance = null;
$tendancePourcent = 0;
if ($nbNotes >= 3) {
    $premieresNotes = array_slice($notesChronological, 0, min(3, $nbNotes));
    $dernieresNotes = array_slice($notesChronological, -min(3, $nbNotes));
    
    $moyPremieres = array_sum($premieresNotes) / count($premieresNotes);
    $moyDernieres = array_sum($dernieresNotes) / count($dernieresNotes);
    
    $diff = $moyDernieres - $moyPremieres;
    $tendancePourcent = $moyPremieres > 0 ? round(($diff / $moyPremieres) * 100, 1) : 0;
    
    if ($tendancePourcent > 5) {
        $tendance = 'hausse';
    } elseif ($tendancePourcent < -5) {
        $tendance = 'baisse';
    } else {
        $tendance = 'stable';
    }
}

// Stats supplémentaires
$noteMax = !empty($notesChronological) ? max($notesChronological) : null;
$noteMin = !empty($notesChronological) ? min($notesChronological) : null;
$nbNotesSupMoyenne = 0;
foreach ($data as $d) {
    if ($d['noteEleve'] !== null && $d['moyClasse'] !== null && $d['noteEleve'] > $d['moyClasse']) {
        $nbNotesSupMoyenne++;
    }
}

// 🔹 Calcul progression globale
$prevNote = null;
foreach ($data as $i => $row) {
    if ($row['noteEleve'] !== null && $prevNote !== null) {
        $diff = $row['noteEleve'] - $prevNote;
        $pourcentage = ($prevNote > 0) ? round(($diff / $prevNote) * 100, 1) : null;
        $data[$i]['progression'] = $pourcentage;
    } else {
        $data[$i]['progression'] = null;
    }
    if ($row['noteEleve'] !== null) {
        $prevNote = $row['noteEleve'];
    }
}

// 🔹 Calcul stats par matière
$statsParMatiere = [];
foreach ($dataParMatiere as $matiere => $notes) {
    $notesEleve = array_filter(array_map(fn($d) => $d['noteEleve'], $notes));
    $notesEleve = array_values($notesEleve);
    
    $total = 0;
    $coefTotal = 0;
    foreach ($notes as $n) {
        if ($n['noteEleve'] !== null) {
            $total += $n['noteEleve'] * $n['coef'];
            $coefTotal += $n['coef'];
        }
    }
    
    $moyenne = $coefTotal > 0 ? round($total / $coefTotal, 2) : null;
    $max = !empty($notesEleve) ? max($notesEleve) : null;
    $min = !empty($notesEleve) ? min($notesEleve) : null;
    $nbNotes = count($notesEleve);
    
    // Tendance par matière
    $tendanceMatiere = null;
    if ($nbNotes >= 2) {
        $premiere = $notesEleve[0];
        $derniere = $notesEleve[$nbNotes - 1];
        $diffMatiere = $derniere - $premiere;
        if ($diffMatiere > 1) $tendanceMatiere = 'hausse';
        elseif ($diffMatiere < -1) $tendanceMatiere = 'baisse';
        else $tendanceMatiere = 'stable';
    }
    
    $statsParMatiere[$matiere] = [
        'moyenne' => $moyenne,
        'max' => $max,
        'min' => $min,
        'nbNotes' => $nbNotes,
        'tendance' => $tendanceMatiere,
        'notes' => $notes
    ];
}

// 🔹 Préparer données pour le graphique global
$labels = array_map(function($d) { return $d['name']; }, $data);
$userGraph = array_map(function($d) { return $d['noteEleve']; }, $data);
$classGraph = array_map(function($d) { return $d['moyClasse']; }, $data);

// Couleurs pour les matières
$matiereColors = [
    '#9a65e5', '#16a34a', '#f59e0b', '#3b82f6', '#ef4444', 
    '#8b5cf6', '#10b981', '#f97316', '#06b6d4', '#ec4899'
];
?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>📚 Dashboard Analytique - Mes Notes</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: 'Inter', sans-serif;
    background: linear-gradient(135deg, rgba(244, 239, 252, 0.4) 0%, rgba(154, 101, 229, 0.1) 100%);
    min-height: 100vh;
    padding: 20px;
}

.container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px 0;
}

.greeting {
    text-align: center;
    margin-bottom: 40px;
}

.greeting h1 {
    color: rgba(154, 101, 229, 1);
    font-size: 42px;
    font-weight: 700;
    margin-bottom: 8px;
}

.greeting p {
    color: #6b7280;
    font-size: 18px;
}

.section-title {
    font-size: 28px;
    font-weight: 700;
    color: #1f2937;
    margin: 40px 0 24px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}

.stat-card {
    background: white;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 4px 12px rgba(154, 101, 229, 0.1);
    position: relative;
    overflow: hidden;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 20px rgba(154, 101, 229, 0.15);
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
}

.stat-card.primary::before { background: linear-gradient(90deg, rgba(154, 101, 229, 1) 0%, rgba(134, 81, 209, 1) 100%); }
.stat-card.success::before { background: linear-gradient(90deg, #10b981 0%, #059669 100%); }
.stat-card.warning::before { background: linear-gradient(90deg, #f59e0b 0%, #d97706 100%); }
.stat-card.danger::before { background: linear-gradient(90deg, #ef4444 0%, #dc2626 100%); }
.stat-card.info::before { background: linear-gradient(90deg, #3b82f6 0%, #2563eb 100%); }

.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    margin-bottom: 12px;
}

.stat-card.primary .stat-icon { background: linear-gradient(135deg, rgba(244, 239, 252, 1) 0%, rgba(221, 204, 245, 1) 100%); }
.stat-card.success .stat-icon { background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); }
.stat-card.warning .stat-icon { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); }
.stat-card.danger .stat-icon { background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); }
.stat-card.info .stat-icon { background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); }

.stat-label {
    font-size: 13px;
    color: #6b7280;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}

.stat-value {
    font-size: 32px;
    font-weight: 700;
    color: #1f2937;
}

.stat-subtext {
    font-size: 14px;
    color: #9ca3af;
    margin-top: 6px;
}

.trend-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    margin-top: 8px;
}

.trend-badge.up { background: #d1fae5; color: #065f46; }
.trend-badge.down { background: #fee2e2; color: #991b1b; }
.trend-badge.stable { background: #e0e7ff; color: #3730a3; }

.chart-container {
    background: white;
    border-radius: 16px;
    padding: 32px;
    box-shadow: 0 4px 12px rgba(154, 101, 229, 0.1);
    margin-bottom: 32px;
}

.chart-title {
    font-size: 22px;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.matiere-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
    gap: 24px;
    margin-bottom: 32px;
}

.matiere-card {
    background: white;
    border-radius: 16px;
    padding: 28px;
    box-shadow: 0 4px 12px rgba(154, 101, 229, 0.1);
}

.matiere-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 2px solid #f3f4f6;
}

.matiere-name {
    font-size: 20px;
    font-weight: 700;
    color: #1f2937;
}

.matiere-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}

.mini-stat {
    text-align: center;
    padding: 12px;
    background: #f9fafb;
    border-radius: 10px;
}

.mini-stat-label {
    font-size: 11px;
    color: #6b7280;
    font-weight: 600;
    text-transform: uppercase;
    margin-bottom: 4px;
}

.mini-stat-value {
    font-size: 20px;
    font-weight: 700;
    color: #1f2937;
}

.table-container {
    background: white;
    border-radius: 16px;
    padding: 32px;
    box-shadow: 0 4px 12px rgba(154, 101, 229, 0.1);
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

thead {
    background: linear-gradient(135deg, rgba(244, 239, 252, 1) 0%, rgba(221, 204, 245, 0.5) 100%);
}

th {
    padding: 16px;
    text-align: center;
    font-weight: 600;
    color: rgba(154, 101, 229, 1);
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

th:first-child {
    border-top-left-radius: 12px;
    text-align: left;
}

th:last-child {
    border-top-right-radius: 12px;
}

td {
    padding: 14px 16px;
    color: #374151;
    font-size: 14px;
    border-bottom: 1px solid #f3f4f6;
    text-align: center;
}

td:first-child {
    text-align: left;
    font-weight: 600;
}

tbody tr {
    transition: background-color 0.2s ease;
}

tbody tr:hover {
    background-color: rgba(244, 239, 252, 0.3);
}

tbody tr:last-child td {
    border-bottom: none;
}

.badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
}

.badge.up { background: #d1fae5; color: #065f46; }
.badge.down { background: #fee2e2; color: #991b1b; }

.note-highlight {
    font-weight: 700;
    font-size: 15px;
}

.tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}

.tab {
    padding: 10px 20px;
    border-radius: 10px;
    border: 2px solid #e5e7eb;
    background: white;
    color: #6b7280;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.tab:hover {
    border-color: rgba(154, 101, 229, 0.5);
}

.tab.active {
    background: rgba(154, 101, 229, 1);
    color: white;
    border-color: rgba(154, 101, 229, 1);
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

@media (max-width: 768px) {
    .greeting h1 { font-size: 32px; }
    .stats-grid { grid-template-columns: 1fr; }
    .matiere-grid { grid-template-columns: 1fr; }
    .chart-container, .table-container { padding: 20px; }
}
</style>
</head>
<body>
<div class="container">
    <div class="greeting">
        <h1><?php echo $greeting; ?>, <?php echo htmlspecialchars($userName); ?> ! 👋</h1>
        <p>Dashboard complet de tes performances académiques</p>
    </div>

    <!-- Stats globales -->
    <h2 class="section-title">📊 Vue d'ensemble</h2>
    <div class="stats-grid">
        <div class="stat-card primary">
            <div class="stat-icon">📊</div>
            <div class="stat-label">Ta moyenne générale</div>
            <div class="stat-value"><?php echo $moyEleve !== null ? number_format($moyEleve, 2) : '—'; ?></div>
            <div class="stat-subtext">/20</div>
        </div>

        <div class="stat-card info">
            <div class="stat-icon">👥</div>
            <div class="stat-label">Moyenne de classe</div>
            <div class="stat-value"><?php echo $moyClasse !== null ? number_format($moyClasse, 2) : '—'; ?></div>
            <div class="stat-subtext">/20</div>
        </div>

        <div class="stat-card <?php echo $tendance === 'hausse' ? 'success' : ($tendance === 'baisse' ? 'danger' : 'warning'); ?>">
            <div class="stat-icon">
                <?php 
                    if ($tendance === 'hausse') echo '📈';
                    elseif ($tendance === 'baisse') echo '📉';
                    else echo '➡️';
                ?>
            </div>
            <div class="stat-label">Tendance globale</div>
            <div class="stat-value">
                <?php 
                    if ($tendance === 'hausse') echo 'En hausse';
                    elseif ($tendance === 'baisse') echo 'En baisse';
                    else echo 'Stable';
                ?>
            </div>
            <?php if ($tendance !== null && $tendancePourcent != 0): ?>
                <div class="trend-badge <?php echo $tendance === 'hausse' ? 'up' : ($tendance === 'baisse' ? 'down' : 'stable'); ?>">
                    <?php echo ($tendancePourcent > 0 ? '+' : '') . $tendancePourcent; ?>%
                </div>
            <?php endif; ?>
        </div>

        <div class="stat-card success">
            <div class="stat-icon">🏆</div>
            <div class="stat-label">Meilleure note</div>
            <div class="stat-value"><?php echo $noteMax !== null ? number_format($noteMax, 2) : '—'; ?></div>
            <div class="stat-subtext">/20</div>
        </div>

        <div class="stat-card danger">
            <div class="stat-icon">📌</div>
            <div class="stat-label">Note à améliorer</div>
            <div class="stat-value"><?php echo $noteMin !== null ? number_format($noteMin, 2) : '—'; ?></div>
            <div class="stat-subtext">/20</div>
        </div>

        <div class="stat-card warning">
            <div class="stat-icon">⭐</div>
            <div class="stat-label">Au-dessus de la moyenne</div>
            <div class="stat-value"><?php echo $nbNotesSupMoyenne; ?></div>
            <div class="stat-subtext">sur <?php echo count($data); ?> contrôles</div>
        </div>

        <div class="stat-card info">
            <div class="stat-icon">📚</div>
            <div class="stat-label">Matières suivies</div>
            <div class="stat-value"><?php echo count($statsParMatiere); ?></div>
            <div class="stat-subtext">matières actives</div>
        </div>

        <div class="stat-card primary">
            <div class="stat-icon">📝</div>
            <div class="stat-label">Total contrôles</div>
            <div class="stat-value"><?php echo $nbNotes; ?></div>
            <div class="stat-subtext">évaluations</div>
        </div>
    </div>

    <!-- Graphique global -->
    <div class="chart-container">
        <div class="chart-title">📈 Évolution globale de tes notes</div>
        <div id="chart-global"></div>
    </div>

    <!-- Analyse par matière -->
    <h2 class="section-title">🎯 Analyse par matière</h2>
    <div class="matiere-grid">
        <?php 
        $colorIndex = 0;
        foreach ($statsParMatiere as $matiere => $stats): 
            $color = $matiereColors[$colorIndex % count($matiereColors)];
            $colorIndex++;
        ?>
            <div class="matiere-card">
                <div class="matiere-header">
                    <div class="matiere-name" style="color: <?php echo $color; ?>;">
                        <?php echo htmlspecialchars($matiere); ?>
                    </div>
                    <?php if ($stats['tendance']): ?>
                        <div class="trend-badge <?php echo $stats['tendance']; ?>">
                            <?php 
                                if ($stats['tendance'] === 'hausse') echo '📈 En hausse';
                                elseif ($stats['tendance'] === 'baisse') echo '📉 En baisse';
                                else echo '➡️ Stable';
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="matiere-stats">
                    <div class="mini-stat">
                        <div class="mini-stat-label">Moyenne</div>
                        <div class="mini-stat-value" style="color: <?php echo $color; ?>;">
                            <?php echo $stats['moyenne'] !== null ? number_format($stats['moyenne'], 2) : '—'; ?>
                        </div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-label">Meilleure</div>
                        <div class="mini-stat-value" style="color: #10b981;">
                            <?php echo $stats['max'] !== null ? number_format($stats['max'], 2) : '—'; ?>
                        </div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-label">Plus basse</div>
                        <div class="mini-stat-value" style="color: #ef4444;">
                            <?php echo $stats['min'] !== null ? number_format($stats['min'], 2) : '—'; ?>
                        </div>
                    </div>
                </div>

                <div id="chart-<?php echo preg_replace('/[^a-z0-9]/i', '', $matiere); ?>" style="margin-top: 16px;"></div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Tableau détaillé -->
    <div class="table-container">
        <div class="chart-title" style="margin-bottom: 20px;">📋 Détail complet des contrôles</div>
        
        <div class="tabs">
            <button class="tab active" onclick="switchTab('all')">Toutes les matières</button>
            <?php foreach ($statsParMatiere as $matiere => $stats): ?>
                <button class="tab" onclick="switchTab('<?php echo preg_replace('/[^a-z0-9]/i', '', $matiere); ?>')">
                    <?php echo htmlspecialchars($matiere); ?>
                </button>
            <?php endforeach; ?>
        </div>

        <div id="tab-all" class="tab-content active">
            <table>
                <thead>
                    <tr>
                        <th>Contrôle</th>
                        <th>Matière</th>
                        <th>Date</th>
                        <th>Ta note</th>
                        <th>Moyenne classe</th>
                        <th>Coef</th>
                        <th>Progression</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $d): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($d['name']); ?></td>
                            <td><span style="font-weight: 600; color: #6b7280;"><?php echo htmlspecialchars($d['matiere']); ?></span></td>
                            <td><?php echo $d['date'] ? date('d/m/Y', strtotime($d['date'])) : '—'; ?></td>
                            <td>
                                <span class="note-highlight" style="color: <?php 
                                    if ($d['noteEleve'] === null) echo '#6b7280';
                                    elseif ($d['moyClasse'] !== null && $d['noteEleve'] > $d['moyClasse']) echo '#16a34a';
                                    elseif ($d['moyClasse'] !== null && $d['noteEleve'] < $d['moyClasse']) echo '#dc2626';
                                    else echo '#1f2937';
                                ?>">
                                    <?php echo $d['noteEleve'] !== null ? number_format($d['noteEleve'], 2) : '—'; ?>
                                </span>
                            </td>
                            <td><?php echo $d['moyClasse'] !== null ? number_format($d['moyClasse'], 2) : '—'; ?></td>
                            <td><strong><?php echo $d['coef']; ?></strong></td>
                            <td>
                                <?php if ($d['progression'] !== null): ?>
                                    <span class="badge <?php echo $d['progression'] >= 0 ? 'up' : 'down'; ?>">
                                        <?php echo ($d['progression'] >= 0 ? '↗ +' : '↘ ') . abs($d['progression']); ?>%
                                    </span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php foreach ($statsParMatiere as $matiere => $stats): ?>
            <div id="tab-<?php echo preg_replace('/[^a-z0-9]/i', '', $matiere); ?>" class="tab-content">
                <table>
                    <thead>
                        <tr>
                            <th>Contrôle</th>
                            <th>Date</th>
                            <th>Ta note</th>
                            <th>Moyenne classe</th>
                            <th>Coef</th>
                            <th>Écart/Moyenne</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['notes'] as $n): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($n['name']); ?></td>
                                <td><?php echo $n['date'] ? date('d/m/Y', strtotime($n['date'])) : '—'; ?></td>
                                <td>
                                    <span class="note-highlight" style="color: <?php 
                                        if ($n['noteEleve'] === null) echo '#6b7280';
                                        elseif ($n['moyClasse'] !== null && $n['noteEleve'] > $n['moyClasse']) echo '#16a34a';
                                        elseif ($n['moyClasse'] !== null && $n['noteEleve'] < $n['moyClasse']) echo '#dc2626';
                                        else echo '#1f2937';
                                    ?>">
                                        <?php echo $n['noteEleve'] !== null ? number_format($n['noteEleve'], 2) : '—'; ?>
                                    </span>
                                </td>
                                <td><?php echo $n['moyClasse'] !== null ? number_format($n['moyClasse'], 2) : '—'; ?></td>
                                <td><strong><?php echo $n['coef']; ?></strong></td>
                                <td>
                                    <?php if ($n['noteEleve'] !== null && $n['moyClasse'] !== null): 
                                        $ecart = $n['noteEleve'] - $n['moyClasse'];
                                    ?>
                                        <span class="badge <?php echo $ecart >= 0 ? 'up' : 'down'; ?>">
                                            <?php echo ($ecart >= 0 ? '+' : '') . number_format($ecart, 2); ?>
                                        </span>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
// Graphique global
const optionsGlobal = {
    series: [
        { 
            name: "Tes notes", 
            data: <?php echo json_encode($userGraph); ?>, 
            color: '#16a34a' 
        },
        { 
            name: "Moyenne classe", 
            data: <?php echo json_encode($classGraph); ?>, 
            color: '#9a65e5' 
        }
    ],
    chart: { 
        type: 'line', 
        height: 400, 
        fontFamily: 'Inter, sans-serif',
        toolbar: {
            show: true,
            tools: {
                download: true,
                selection: false,
                zoom: false,
                zoomin: false,
                zoomout: false,
                pan: false,
                reset: false
            }
        }
    },
    stroke: { curve: 'smooth', width: 3 },
    markers: {
        size: 5,
        strokeWidth: 2,
        strokeColors: '#fff',
        hover: { size: 7 }
    },
    xaxis: { 
        categories: <?php echo json_encode($labels); ?>, 
        labels: { 
            rotate: -45,
            style: { colors: '#6b7280', fontSize: '12px' }
        } 
    },
    yaxis: { 
        min: 0, 
        max: 20,
        labels: {
            style: { colors: '#6b7280', fontSize: '12px' }
        }
    },
    tooltip: { 
        shared: true,
        y: {
            formatter: function(val) {
                return val !== null ? val.toFixed(2) : 'N/A';
            }
        }
    },
    legend: { 
        position: 'top',
        horizontalAlign: 'right',
        fontSize: '14px',
        fontWeight: 600
    },
    grid: {
        borderColor: '#f3f4f6',
        strokeDashArray: 4
    }
};
new ApexCharts(document.querySelector("#chart-global"), optionsGlobal).render();

// Graphiques par matière
<?php 
$colorIndex = 0;
foreach ($statsParMatiere as $matiere => $stats): 
    $color = $matiereColors[$colorIndex % count($matiereColors)];
    $colorIndex++;
    
    $labelsMatiere = array_map(function($n) { return $n['name']; }, $stats['notes']);
    $notesMatiere = array_map(function($n) { return $n['noteEleve']; }, $stats['notes']);
    $moyennesMatiere = array_map(function($n) { return $n['moyClasse']; }, $stats['notes']);
    $chartId = preg_replace('/[^a-z0-9]/i', '', $matiere);
?>
const options<?php echo $chartId; ?> = {
    series: [
        { 
            name: "Tes notes", 
            data: <?php echo json_encode($notesMatiere); ?>, 
            color: '<?php echo $color; ?>' 
        },
        { 
            name: "Moyenne classe", 
            data: <?php echo json_encode($moyennesMatiere); ?>, 
            color: '#9ca3af' 
        }
    ],
    chart: { 
        type: 'line', 
        height: 250, 
        fontFamily: 'Inter, sans-serif',
        toolbar: { show: false }
    },
    stroke: { curve: 'smooth', width: 2 },
    markers: {
        size: 4,
        strokeWidth: 2,
        strokeColors: '#fff',
        hover: { size: 6 }
    },
    xaxis: { 
        categories: <?php echo json_encode($labelsMatiere); ?>, 
        labels: { 
            rotate: -45,
            style: { colors: '#6b7280', fontSize: '11px' }
        } 
    },
    yaxis: { 
        min: 0, 
        max: 20,
        labels: {
            style: { colors: '#6b7280', fontSize: '11px' }
        }
    },
    tooltip: { 
        shared: true,
        y: {
            formatter: function(val) {
                return val !== null ? val.toFixed(2) : 'N/A';
            }
        }
    },
    legend: { 
        show: false
    },
    grid: {
        borderColor: '#f3f4f6',
        strokeDashArray: 4
    }
};
new ApexCharts(document.querySelector("#chart-<?php echo $chartId; ?>"), options<?php echo $chartId; ?>).render();
<?php endforeach; ?>

// Gestion des onglets
function switchTab(tabName) {
    // Masquer tous les contenus
    const contents = document.querySelectorAll('.tab-content');
    contents.forEach(content => content.classList.remove('active'));
    
    // Désactiver tous les onglets
    const tabs = document.querySelectorAll('.tab');
    tabs.forEach(tab => tab.classList.remove('active'));
    
    // Activer l'onglet et le contenu sélectionnés
    document.getElementById('tab-' + tabName).classList.add('active');
    event.target.classList.add('active');
}
</script>
</body>
</html>
