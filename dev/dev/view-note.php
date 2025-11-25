<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// connexion PDO à la base Gibbon
try {
    // Create a config.php file with your database credentials.
$config = include __DIR__ . '/config.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

// récupération des évaluations
$columns = $pdo->query("SELECT gibbonMarkbookColumnID, name, date FROM gibbonMarkbookColumn ORDER BY date")->fetchAll(PDO::FETCH_ASSOC);

// initialisation des stats
$stats = [];
foreach ($columns as $col) {
    $stmt = $pdo->prepare("SELECT attainmentValue FROM gibbonMarkbookEntry WHERE gibbonMarkbookColumnID = :colID");
    $stmt->execute(['colID' => $col['gibbonMarkbookColumnID']]);
    $notes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Filtrer les valeurs nulles et vides
    $notes = array_filter($notes, function($note) {
        return $note !== null && $note !== '';
    });
    
    if (count($notes) > 0) {
        $min = min($notes);
        $max = max($notes);
        $avg = array_sum($notes) / count($notes);
        $count = count($notes);
    } else {
        $min = $max = $avg = null;
        $count = 0;
    }
    
    $stats[] = [
        'name' => $col['name'],
        'date' => $col['date'],
        'min' => $min,
        'max' => $max,
        'avg' => $avg,
        'count' => $count
    ];
}

// préparation des données pour ApexCharts
$labels = [];
$avgData = [];
$minData = [];
$maxData = [];

foreach ($stats as $s) {
    if ($s['avg'] !== null) {
        $labels[] = $s['name'];
        $avgData[] = round($s['avg'], 2);
        $minData[] = round($s['min'], 2);
        $maxData[] = round($s['max'], 2);
    }
}

// Calcul des stats globales
$totalAvg = 0;
$totalCount = 0;
$allMins = [];
$allMaxs = [];

foreach ($stats as $s) {
    if ($s['avg'] !== null) {
        $totalAvg += $s['avg'];
        $totalCount++;
        $allMins[] = $s['min'];
        $allMaxs[] = $s['max'];
    }
}

$globalAvg = $totalCount > 0 ? $totalAvg / $totalCount : 0;
$globalMin = !empty($allMins) ? min($allMins) : 0;
$globalMax = !empty($allMaxs) ? max($allMaxs) : 20;
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistiques des évaluations - Gibbon</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
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
            max-width: 1400px;
            margin: 0 auto;
            padding: 40px 0;
        }

        .header {
            text-align: center;
            margin-bottom: 48px;
        }

        .header h1 {
            color: rgba(154, 101, 229, 1);
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 12px;
        }

        .header p {
            color: #6b7280;
            font-size: 18px;
        }

        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 4px 12px rgba(154, 101, 229, 0.1);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }

        .stat-card.avg::before {
            background: linear-gradient(90deg, rgba(154, 101, 229, 1) 0%, rgba(134, 81, 209, 1) 100%);
        }

        .stat-card.min::before {
            background: linear-gradient(90deg, #ef4444 0%, #dc2626 100%);
        }

        .stat-card.max::before {
            background: linear-gradient(90deg, #10b981 0%, #059669 100%);
        }

        .stat-card.count::before {
            background: linear-gradient(90deg, #3b82f6 0%, #2563eb 100%);
        }

        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin-bottom: 16px;
        }

        .stat-card.avg .stat-icon {
            background: linear-gradient(135deg, rgba(244, 239, 252, 1) 0%, rgba(221, 204, 245, 1) 100%);
        }

        .stat-card.min .stat-icon {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
        }

        .stat-card.max .stat-icon {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
        }

        .stat-card.count .stat-icon {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
        }

        .stat-value {
            font-size: 36px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: 14px;
            color: #6b7280;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

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

        .chart-title-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: linear-gradient(135deg, rgba(244, 239, 252, 1) 0%, rgba(221, 204, 245, 1) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .controls-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .control-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 12px rgba(154, 101, 229, 0.1);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .control-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, rgba(154, 101, 229, 1) 0%, rgba(134, 81, 209, 1) 100%);
        }

        .control-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(154, 101, 229, 0.15);
        }

        .control-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }

        .control-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: linear-gradient(135deg, rgba(244, 239, 252, 1) 0%, rgba(221, 204, 245, 1) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .control-name {
            flex: 1;
            font-size: 16px;
            font-weight: 700;
            color: #1f2937;
            line-height: 1.3;
        }

        .control-date {
            font-size: 12px;
            color: #9ca3af;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .control-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }

        .stat-item {
            text-align: center;
            padding: 12px;
            border-radius: 10px;
            background: #f9fafb;
        }

        .stat-item.avg-stat {
            background: linear-gradient(135deg, rgba(244, 239, 252, 0.5) 0%, rgba(221, 204, 245, 0.3) 100%);
        }

        .stat-item.min-stat {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
        }

        .stat-item.max-stat {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
        }

        .stat-item-label {
            font-size: 11px;
            color: #6b7280;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .stat-item-value {
            font-size: 20px;
            font-weight: 700;
            color: #1f2937;
        }

        .empty-state {
            text-align: center;
            padding: 80px 20px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(154, 101, 229, 0.1);
        }

        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 16px;
        }

        .empty-state h3 {
            font-size: 24px;
            color: #1f2937;
            margin-bottom: 8px;
        }

        .empty-state p {
            color: #6b7280;
            font-size: 16px;
        }

        @media (max-width: 768px) {
            .header h1 {
                font-size: 28px;
            }

            .stats-overview {
                grid-template-columns: 1fr;
            }

            .controls-grid {
                grid-template-columns: 1fr;
            }

            .chart-container {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📊 Statistiques des évaluations</h1>
            <p>Tableau de bord des performances de la classe</p>
        </div>

        <?php if ($totalCount > 0): ?>
        <div class="stats-overview">
            <div class="stat-card avg">
                <div class="stat-icon">📈</div>
                <div class="stat-value"><?php echo number_format($globalAvg, 2); ?></div>
                <div class="stat-label">Moyenne générale</div>
            </div>
            
            <div class="stat-card min">
                <div class="stat-icon">📉</div>
                <div class="stat-value"><?php echo number_format($globalMin, 2); ?></div>
                <div class="stat-label">Note minimale</div>
            </div>
            
            <div class="stat-card max">
                <div class="stat-icon">🏆</div>
                <div class="stat-value"><?php echo number_format($globalMax, 2); ?></div>
                <div class="stat-label">Note maximale</div>
            </div>
            
            <div class="stat-card count">
                <div class="stat-icon">📝</div>
                <div class="stat-value"><?php echo count($stats); ?></div>
                <div class="stat-label">Évaluations</div>
            </div>
        </div>

        <div class="chart-container">
            <div class="chart-title">
                <div class="chart-title-icon">📊</div>
                Évolution des notes par évaluation
            </div>
            <div id="chartNotes"></div>
        </div>

        <div style="background: white; border-radius: 16px; padding: 32px; box-shadow: 0 4px 12px rgba(154, 101, 229, 0.1);">
            <div class="chart-title" style="margin-bottom: 24px;">
                <div class="chart-title-icon">📋</div>
                Détail par contrôle
            </div>
            
            <div class="controls-grid">
                <?php foreach($stats as $s): ?>
                    <?php if ($s['avg'] !== null): ?>
                        <div class="control-card">
                            <div class="control-header">
                                <div class="control-icon">📝</div>
                                <div class="control-name"><?php echo htmlspecialchars($s['name']); ?></div>
                            </div>
                            <div class="control-date">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                                <?php echo $s['date'] ? date('d/m/Y', strtotime($s['date'])) : 'Date non définie'; ?>
                            </div>
                            <div class="control-stats">
                                <div class="stat-item avg-stat">
                                    <div class="stat-item-label">Moyenne</div>
                                    <div class="stat-item-value"><?php echo number_format($s['avg'], 2); ?></div>
                                </div>
                                <div class="stat-item min-stat">
                                    <div class="stat-item-label">Min</div>
                                    <div class="stat-item-value"><?php echo number_format($s['min'], 2); ?></div>
                                </div>
                                <div class="stat-item max-stat">
                                    <div class="stat-item-label">Max</div>
                                    <div class="stat-item-value"><?php echo number_format($s['max'], 2); ?></div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon">📊</div>
                <h3>Aucune évaluation disponible</h3>
                <p>Les statistiques apparaîtront lorsque des notes seront enregistrées</p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        <?php if ($totalCount > 0): ?>
        const options = {
            series: [
                {
                    name: 'Moyenne',
                    data: <?php echo json_encode(array_values($avgData)); ?>,
                    color: '#9a65e5'
                },
                {
                    name: 'Note minimale',
                    data: <?php echo json_encode(array_values($minData)); ?>,
                    color: '#ef4444'
                },
                {
                    name: 'Note maximale',
                    data: <?php echo json_encode(array_values($maxData)); ?>,
                    color: '#10b981'
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
                },
                animations: {
                    enabled: true,
                    easing: 'easeinout',
                    speed: 800
                }
            },
            dataLabels: {
                enabled: false
            },
            stroke: {
                curve: 'smooth',
                width: 3
            },
            xaxis: {
                categories: <?php echo json_encode(array_values($labels)); ?>,
                labels: {
                    style: {
                        colors: '#6b7280',
                        fontSize: '12px'
                    },
                    rotate: -45,
                    rotateAlways: false,
                    trim: true
                }
            },
            yaxis: {
                min: 0,
                max: 20,
                labels: {
                    style: {
                        colors: '#6b7280',
                        fontSize: '12px'
                    },
                    formatter: function(val) {
                        return val !== null ? val.toFixed(1) : '0';
                    }
                }
            },
            legend: {
                position: 'top',
                horizontalAlign: 'right',
                fontSize: '14px',
                fontWeight: 600,
                markers: {
                    width: 12,
                    height: 12,
                    radius: 6
                },
                itemMargin: {
                    horizontal: 16
                }
            },
            grid: {
                borderColor: '#f3f4f6',
                strokeDashArray: 4,
                xaxis: {
                    lines: {
                        show: false
                    }
                }
            },
            tooltip: {
                shared: true,
                intersect: false,
                y: {
                    formatter: function(val) {
                        return val !== null ? val.toFixed(2) : 'N/A';
                    }
                },
                style: {
                    fontSize: '14px'
                }
            },
            markers: {
                size: 5,
                strokeWidth: 2,
                strokeColors: '#fff',
                hover: {
                    size: 7
                }
            }
        };

        const chart = new ApexCharts(document.querySelector("#chartNotes"), options);
        chart.render();
        <?php endif; ?>
    </script>
</body>
</html>
