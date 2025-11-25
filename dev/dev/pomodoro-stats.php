<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 🔹 Connexion à la base de données
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

// 🔹 Récupération des statistiques

// Total de temps aujourd'hui
$stmt = $pdo->prepare("
    SELECT SUM(duration) as total 
    FROM pomodoroSessions 
    WHERE gibbonPersonID = :userID 
    AND type = 'work'
    AND DATE(completedAt) = CURDATE()
");
$stmt->execute(['userID' => $userID]);
$todayTotal = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Total de temps cette semaine
$stmt = $pdo->prepare("
    SELECT SUM(duration) as total 
    FROM pomodoroSessions 
    WHERE gibbonPersonID = :userID 
    AND type = 'work'
    AND YEARWEEK(completedAt, 1) = YEARWEEK(CURDATE(), 1)
");
$stmt->execute(['userID' => $userID]);
$weekTotal = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Total de temps ce mois
$stmt = $pdo->prepare("
    SELECT SUM(duration) as total 
    FROM pomodoroSessions 
    WHERE gibbonPersonID = :userID 
    AND type = 'work'
    AND MONTH(completedAt) = MONTH(CURDATE())
    AND YEAR(completedAt) = YEAR(CURDATE())
");
$stmt->execute(['userID' => $userID]);
$monthTotal = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Total de sessions
$stmt = $pdo->prepare("
    SELECT COUNT(*) as total 
    FROM pomodoroSessions 
    WHERE gibbonPersonID = :userID 
    AND type = 'work'
");
$stmt->execute(['userID' => $userID]);
$totalSessions = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Temps par matière (total)
$stmt = $pdo->prepare("
    SELECT s.name, s.color, SUM(p.duration) as total
    FROM pomodoroSessions p
    JOIN pomodoroSubjects s ON p.subjectID = s.id
    WHERE p.gibbonPersonID = :userID
    AND p.type = 'work'
    GROUP BY p.subjectID
    ORDER BY total DESC
");
$stmt->execute(['userID' => $userID]);
$subjectStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Temps par matière ce mois
$stmt = $pdo->prepare("
    SELECT s.name, s.color, SUM(p.duration) as total
    FROM pomodoroSessions p
    JOIN pomodoroSubjects s ON p.subjectID = s.id
    WHERE p.gibbonPersonID = :userID
    AND p.type = 'work'
    AND MONTH(p.completedAt) = MONTH(CURDATE())
    AND YEAR(p.completedAt) = YEAR(CURDATE())
    GROUP BY p.subjectID
    ORDER BY total DESC
");
$stmt->execute(['userID' => $userID]);
$monthSubjectStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Sessions par jour (7 derniers jours)
$stmt = $pdo->prepare("
    SELECT DATE(completedAt) as day, SUM(duration) as total
    FROM pomodoroSessions
    WHERE gibbonPersonID = :userID
    AND type = 'work'
    AND completedAt >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(completedAt)
    ORDER BY day ASC
");
$stmt->execute(['userID' => $userID]);
$weeklyData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Sessions par semaine (4 dernières semaines)
$stmt = $pdo->prepare("
    SELECT YEARWEEK(completedAt, 1) as week, SUM(duration) as total
    FROM pomodoroSessions
    WHERE gibbonPersonID = :userID
    AND type = 'work'
    AND completedAt >= DATE_SUB(CURDATE(), INTERVAL 4 WEEK)
    GROUP BY YEARWEEK(completedAt, 1)
    ORDER BY week ASC
");
$stmt->execute(['userID' => $userID]);
$monthlyWeekData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcul des pourcentages
$totalMinutes = array_sum(array_column($subjectStats, 'total'));
foreach ($subjectStats as &$stat) {
    $stat['percentage'] = $totalMinutes > 0 ? round(($stat['total'] / $totalMinutes) * 100, 1) : 0;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistiques</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'SF Pro Display', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f7f6f3;
            min-height: 100vh;
            padding: 40px 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            background: #fffef9;
            border-radius: 12px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        
        .header h1 {
            font-size: 32px;
            color: #2d2d2d;
            font-weight: 700;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }
        
        .header .subtitle {
            color: #666;
            font-size: 16px;
            font-weight: 500;
        }
        
        .nav-link {
            display: inline-block;
            margin-top: 16px;
            color: #666;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            padding: 10px 20px;
            border-radius: 8px;
            background: #f7f6f3;
            transition: all 0.2s;
        }
        
        .nav-link:hover {
            background: #e5e3dc;
            color: #2d2d2d;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }
        
        .stat-card {
            background: #fffef9;
            border-radius: 12px;
            padding: 28px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        
        .stat-card .label {
            color: #666;
            font-size: 13px;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            font-weight: 600;
        }
        
        .stat-card .value {
            font-size: 42px;
            font-weight: 700;
            color: #2d2d2d;
            letter-spacing: -1px;
        }
        
        .stat-card .unit {
            font-size: 20px;
            color: #999;
            font-weight: 600;
        }
        
        .chart-container {
            background: #fffef9;
            border-radius: 12px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        
        .chart-container h2 {
            font-size: 18px;
            color: #2d2d2d;
            margin-bottom: 24px;
            font-weight: 700;
            letter-spacing: -0.3px;
        }
        
        .chart-wrapper {
            position: relative;
            height: 300px;
        }
        
        .subjects-breakdown {
            background: #fffef9;
            border-radius: 12px;
            padding: 32px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        
        .subjects-breakdown h2 {
            font-size: 18px;
            color: #2d2d2d;
            margin-bottom: 24px;
            font-weight: 700;
            letter-spacing: -0.3px;
        }
        
        .subject-row {
            display: flex;
            align-items: center;
            padding: 16px;
            margin-bottom: 12px;
            background: #f7f6f3;
            border-radius: 10px;
            transition: all 0.2s;
            border: 1px solid transparent;
        }
        
        .subject-row:hover {
            background: #fffef9;
            border-color: #e5e3dc;
            transform: translateX(4px);
        }
        
        .subject-color-dot {
            width: 18px;
            height: 18px;
            border-radius: 4px;
            margin-right: 16px;
            flex-shrink: 0;
        }
        
        .subject-info {
            flex: 1;
        }
        
        .subject-name {
            font-weight: 700;
            color: #2d2d2d;
            margin-bottom: 6px;
            font-size: 15px;
        }
        
        .subject-time {
            font-size: 13px;
            color: #666;
            font-weight: 600;
        }
        
        .subject-bar {
            height: 6px;
            background: #e5e3dc;
            border-radius: 10px;
            overflow: hidden;
            margin: 10px 0;
        }
        
        .subject-bar-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.6s ease;
        }
        
        .subject-percentage {
            font-size: 20px;
            font-weight: 700;
            color: #2d2d2d;
            margin-left: 20px;
        }
        
        .donut-container {
            display: flex;
            align-items: center;
            gap: 48px;
        }
        
        .donut-chart {
            max-width: 280px;
        }
        
        .donut-legend {
            flex: 1;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            margin-bottom: 14px;
            padding: 8px;
            border-radius: 6px;
            transition: background 0.2s;
        }
        
        .legend-item:hover {
            background: #f7f6f3;
        }
        
        .legend-color {
            width: 24px;
            height: 24px;
            border-radius: 4px;
            margin-right: 12px;
            flex-shrink: 0;
        }
        
        .legend-label {
            flex: 1;
            font-size: 14px;
            color: #2d2d2d;
            font-weight: 600;
        }
        
        .legend-value {
            font-weight: 700;
            color: #2d2d2d;
            font-size: 15px;
        }
        
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: #999;
        }
        
        .empty-state .emoji {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.4;
        }
        
        .empty-state h3 {
            font-size: 20px;
            margin-bottom: 12px;
            color: #666;
            font-weight: 700;
        }
        
        .empty-state p {
            font-size: 14px;
            font-weight: 500;
        }
        
        @media (max-width: 768px) {
            .donut-container {
                flex-direction: column;
            }
            
            .stats-grid {
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
            <h1>Statistiques</h1>
            <p class="subtitle">Salut <?php echo htmlspecialchars($userName); ?>, voici ton suivi</p>
            <a href="index.php" class="nav-link">← Retour au timer</a>
        </div>
        
        <?php if ($totalSessions > 0): ?>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="label">Aujourd'hui</div>
                <div class="value"><?php echo floor($todayTotal / 60); ?><span class="unit">h</span> <?php echo $todayTotal % 60; ?><span class="unit">m</span></div>
            </div>
            
            <div class="stat-card">
                <div class="label">Cette semaine</div>
                <div class="value"><?php echo floor($weekTotal / 60); ?><span class="unit">h</span> <?php echo $weekTotal % 60; ?><span class="unit">m</span></div>
            </div>
            
            <div class="stat-card">
                <div class="label">Ce mois</div>
                <div class="value"><?php echo floor($monthTotal / 60); ?><span class="unit">h</span> <?php echo $monthTotal % 60; ?><span class="unit">m</span></div>
            </div>
            
            <div class="stat-card">
                <div class="label">Sessions totales</div>
                <div class="value"><?php echo $totalSessions; ?></div>
            </div>
        </div>
        
        <div class="chart-container">
            <h2>Activité des 7 derniers jours</h2>
            <div class="chart-wrapper">
                <canvas id="weeklyChart"></canvas>
            </div>
        </div>
        
        <div class="chart-container">
            <h2>Évolution sur 4 semaines</h2>
            <div class="chart-wrapper">
                <canvas id="monthlyChart"></canvas>
            </div>
        </div>
        
        <?php if (!empty($monthSubjectStats)): ?>
        <div class="chart-container">
            <h2>Répartition ce mois</h2>
            <div class="donut-container">
                <div class="donut-chart">
                    <canvas id="donutChart"></canvas>
                </div>
                <div class="donut-legend">
                    <?php 
                    $monthTotalMinutes = array_sum(array_column($monthSubjectStats, 'total'));
                    foreach ($monthSubjectStats as $stat): 
                        $percentage = $monthTotalMinutes > 0 ? round(($stat['total'] / $monthTotalMinutes) * 100, 1) : 0;
                    ?>
                        <div class="legend-item">
                            <div class="legend-color" style="background-color: <?php echo htmlspecialchars($stat['color']); ?>"></div>
                            <span class="legend-label"><?php echo htmlspecialchars($stat['name']); ?></span>
                            <span class="legend-value"><?php echo $percentage; ?>%</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="subjects-breakdown">
            <h2>Détail par matière</h2>
            <?php foreach ($subjectStats as $stat): ?>
                <div class="subject-row">
                    <div class="subject-color-dot" style="background-color: <?php echo htmlspecialchars($stat['color']); ?>"></div>
                    <div class="subject-info">
                        <div class="subject-name"><?php echo htmlspecialchars($stat['name']); ?></div>
                        <div class="subject-time"><?php echo floor($stat['total'] / 60); ?>h <?php echo $stat['total'] % 60; ?>min</div>
                        <div class="subject-bar">
                            <div class="subject-bar-fill" style="width: <?php echo $stat['percentage']; ?>%; background-color: <?php echo htmlspecialchars($stat['color']); ?>"></div>
                        </div>
                    </div>
                    <div class="subject-percentage"><?php echo $stat['percentage']; ?>%</div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php else: ?>
        
        <div class="chart-container">
            <div class="empty-state">
                <div class="emoji">📊</div>
                <h3>Aucune donnée pour le moment</h3>
                <p>Commence ta première session pour voir tes stats apparaître ici</p>
                <a href="index.php" class="nav-link" style="margin-top: 24px;">Commencer maintenant</a>
            </div>
        </div>
        
        <?php endif; ?>
    </div>
    
    <?php if ($totalSessions > 0): ?>
    <script>
        Chart.defaults.font.family = "'SF Pro Display', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif";
        Chart.defaults.color = '#666';
        Chart.defaults.font.weight = '600';
        
        // Graphique hebdomadaire
        const weeklyData = <?php echo json_encode($weeklyData); ?>;
        const last7Days = [];
        const weeklyValues = [];
        
        for (let i = 6; i >= 0; i--) {
            const date = new Date();
            date.setDate(date.getDate() - i);
            const dateStr = date.toISOString().split('T')[0];
            const dayName = date.toLocaleDateString('fr-FR', { weekday: 'short' });
            last7Days.push(dayName);
            
            const dayData = weeklyData.find(d => d.day === dateStr);
            weeklyValues.push(dayData ? dayData.total : 0);
        }
        
        new Chart(document.getElementById('weeklyChart'), {
            type: 'bar',
            data: {
                labels: last7Days,
                datasets: [{
                    label: 'Minutes travaillées',
                    data: weeklyValues,
                    backgroundColor: '#2d2d2d',
                    borderRadius: 8,
                    borderSkipped: false,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#e5e3dc',
                            drawBorder: false
                        },
                        ticks: {
                            callback: function(value) {
                                return value + ' min';
                            },
                            font: {
                                weight: '600'
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                weight: '600'
                            }
                        }
                    }
                }
            }
        });
        
        // Graphique mensuel
        const monthlyData = <?php echo json_encode($monthlyWeekData); ?>;
        const weekLabels = monthlyData.map((d, i) => 'S' + (i + 1));
        const weekValues = monthlyData.map(d => d.total);
        
        new Chart(document.getElementById('monthlyChart'), {
            type: 'line',
            data: {
                labels: weekLabels,
                datasets: [{
                    label: 'Minutes par semaine',
                    data: weekValues,
                    borderColor: '#2d2d2d',
                    backgroundColor: 'rgba(45, 45, 45, 0.08)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 6,
                    pointBackgroundColor: '#2d2d2d',
                    pointBorderColor: '#fffef9',
                    pointBorderWidth: 3,
                    pointHoverRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#e5e3dc',
                            drawBorder: false
                        },
                        ticks: {
                            callback: function(value) {
                                return value + ' min';
                            },
                            font: {
                                weight: '600'
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                weight: '600'
                            }
                        }
                    }
                }
            }
        });
        
        <?php if (!empty($monthSubjectStats)): ?>
        // Graphique en donut
        const subjectData = <?php echo json_encode($monthSubjectStats); ?>;
        const donutLabels = subjectData.map(s => s.name);
        const donutValues = subjectData.map(s => s.total);
        const donutColors = subjectData.map(s => s.color);
        
        new Chart(document.getElementById('donutChart'), {
            type: 'doughnut',
            data: {
                labels: donutLabels,
                datasets: [{
                    data: donutValues,
                    backgroundColor: donutColors,
                    borderWidth: 4,
                    borderColor: '#fffef9',
                    hoverBorderWidth: 4,
                    hoverBorderColor: '#fffef9'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const hours = Math.floor(context.parsed / 60);
                                const mins = context.parsed % 60;
                                return context.label + ': ' + hours + 'h ' + mins + 'min';
                            }
                        },
                        backgroundColor: '#2d2d2d',
                        padding: 12,
                        titleFont: {
                            size: 14,
                            weight: '700'
                        },
                        bodyFont: {
                            size: 13,
                            weight: '600'
                        }
                    }
                },
                cutout: '65%'
            }
        });
        <?php endif; ?>
    </script>
    <?php endif; ?>
</body>
</html>
