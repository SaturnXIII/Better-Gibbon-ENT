<?php
// ========================================
// CONFIGURATION
// ========================================
$ICAL_URL = 'YOUR_ICAL_URL_HERE';

// ========================================
// FONCTIONS
// ========================================
function parseICalDate($date) {
    if (strlen($date) == 8) {
        // Cas : date seule (sans heure)
        return new DateTime($date, new DateTimeZone('Europe/Paris'));
    } elseif (preg_match('/Z$/', $date)) {
        // Cas : UTC avec un Z à la fin
        $dt = new DateTime($date, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Europe/Paris'));
        return $dt;
    } elseif (strlen($date) >= 15) {
        // Cas : date + heure locale sans timezone explicite
        $dt = DateTime::createFromFormat('Ymd\THis', substr($date, 0, 15), new DateTimeZone('Europe/Paris'));
        return $dt;
    }
    return false;
}


function parseICalendar($content) {
    $events = [];
    $lines = explode("\n", str_replace("\r\n", "\n", $content));
    $current_event = null;
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        if ($line == 'BEGIN:VEVENT') {
            $current_event = [];
        } elseif ($line == 'END:VEVENT' && $current_event !== null) {
            $events[] = $current_event;
            $current_event = null;
        } elseif ($current_event !== null) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $key = explode(';', $key)[0];
                
                if ($key == 'DTSTART') {
                    $current_event['start'] = parseICalDate($value);
                } elseif ($key == 'DTEND') {
                    $current_event['end'] = parseICalDate($value);
                } elseif ($key == 'SUMMARY') {
                    $current_event['title'] = $value;
                } elseif ($key == 'DESCRIPTION') {
                    $current_event['description'] = $value;
                } elseif ($key == 'LOCATION') {
                    $current_event['location'] = $value;
                }
            }
        }
    }
    
    // Filtrer et trier
    $now = new DateTime();
    $events = array_filter($events, function($event) use ($now) {
        if (!isset($event['start']) || !isset($event['end']) || $event['end'] < $now) {
            return false;
        }
        
        // Filtrer les événements de plus de 100 heures
        $duration = $event['end']->getTimestamp() - $event['start']->getTimestamp();
        $durationHours = $duration / 3600;
        
        return $durationHours <= 100;
    });
    
    usort($events, function($a, $b) {
        return $a['start'] <=> $b['start'];
    });
    
    return $events;
}

function getEventStatus($start, $end) {
    $now = new DateTime();
    
    if ($now >= $start && $now <= $end) {
        return 'ongoing'; // En cours
    } elseif ($now < $start) {
        $diff = $now->diff($start);
        $totalMinutes = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
        
        if ($totalMinutes <= 30) {
            return 'imminent'; // Dans moins de 30min
        } elseif ($totalMinutes <= 120) {
            return 'soon'; // Dans moins de 2h
        } else {
            return 'upcoming'; // À venir
        }
    }
    return 'upcoming';
}

function getSubjectEmoji($title) {
    $title = strtolower($title);
    if (strpos($title, 'math') !== false) return '🔢';
    if (strpos($title, 'français') !== false || strpos($title, 'french') !== false) return '📚';
    if (strpos($title, 'anglais') !== false || strpos($title, 'english') !== false) return '🗣️';
    if (strpos($title, 'espagnol') !== false || strpos($title, 'spanish') !== false) return '🇪🇸';
    if (strpos($title, 'allemand') !== false || strpos($title, 'german') !== false) return '🇩🇪';
    if (strpos($title, 'science') !== false || strpos($title, 'physique') !== false || strpos($title, 'chimie') !== false) return '🔬';
    if (strpos($title, 'histoire') !== false || strpos($title, 'history') !== false) return '📜';
    if (strpos($title, 'géo') !== false || strpos($title, 'geography') !== false) return '🌍';
    if (strpos($title, 'sport') !== false || strpos($title, 'eps') !== false || strpos($title, 'pe') !== false) return '⚽';
    if (strpos($title, 'art') !== false) return '🎨';
    if (strpos($title, 'musique') !== false || strpos($title, 'music') !== false) return '🎵';
    if (strpos($title, 'info') !== false || strpos($title, 'computer') !== false || strpos($title, 'code') !== false) return '💻';
    if (strpos($title, 'bio') !== false) return '🧬';
    if (strpos($title, 'philo') !== false) return '🤔';
    if (strpos($title, 'pause') !== false || strpos($title, 'récré') !== false || strpos($title, 'break') !== false) return '☕';
    if (strpos($title, 'déjeuner') !== false || strpos($title, 'lunch') !== false || strpos($title, 'repas') !== false) return '🍽️';
    return '📅';
}

// Chargement du calendrier
$error = null;
$events = [];

$context = stream_context_create([
    'http' => [
        'timeout' => 10,
        'user_agent' => 'iCal-Viewer/1.0'
    ]
]);

$ical_content = @file_get_contents($ICAL_URL, false, $context);

if ($ical_content === false) {
    $error = 'Erreur lors du chargement du calendrier.';
} else {
    $events = parseICalendar($ical_content);
}

// Statistiques
$stats = [
    'today' => 0,
    'tomorrow' => 0,
    'week' => 0,
    'ongoing' => 0
];

$today = new DateTime();
$today->setTime(0, 0, 0);
$tomorrow = clone $today;
$tomorrow->modify('+1 day');
$weekEnd = clone $today;
$weekEnd->modify('+7 days');
$now = new DateTime();

foreach ($events as $event) {
    $eventDate = clone $event['start'];
    $eventDate->setTime(0, 0, 0);
    
    // En cours
    if ($now >= $event['start'] && $now <= $event['end']) {
        $stats['ongoing']++;
    }
    
    // Aujourd'hui
    if ($eventDate == $today) {
        $stats['today']++;
    }
    
    // Demain
    if ($eventDate == $tomorrow) {
        $stats['tomorrow']++;
    }
    
    // Cette semaine
    if ($eventDate >= $today && $eventDate <= $weekEnd) {
        $stats['week']++;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>

    <link rel="manifest" href="/manifest.json">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Mon Calendrier</title>
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
            background: linear-gradient(135deg, rgba(244, 239, 252, 0.4) 0%, rgba(154, 101, 229, 0.1) 100%);
            min-height: 100vh;
            padding: 0;
            overflow-x: hidden;
        }

        .container {
            width: 100%;
            padding: 0;
            margin: 0;
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, rgba(154, 101, 229, 1) 0%, rgba(120, 80, 200, 1) 100%);
            padding: 24px 16px 32px 16px;
            box-shadow: 0 4px 20px rgba(154, 101, 229, 0.3);
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .header-actions {
            display: flex;
            gap: 12px;
        }

        .header-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-icon {
            font-size: 32px;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .header h1 {
            font-size: 26px;
            font-weight: 800;
            color: white;
            line-height: 1.2;
        }

        .refresh-btn {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 44px;
            height: 44px;
            border-radius: 12px;
            font-size: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .refresh-btn:active {
            transform: scale(0.95);
            background: rgba(255, 255, 255, 0.3);
        }

        .refresh-btn.spinning {
            animation: spin 1s linear infinite;
        }

        .open-new-tab-btn {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            padding: 10px 16px;
            border-radius: 12px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            white-space: nowrap;
        }

        .open-new-tab-btn:active {
            transform: scale(0.95);
            background: rgba(255, 255, 255, 0.3);
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .current-time {
            color: rgba(255, 255, 255, 0.95);
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Stats Bar */
        .stats-bar {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            padding: 16px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 16px;
            box-shadow: 0 2px 12px rgba(154, 101, 229, 0.08);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
        }

        .stat-icon.today { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); }
        .stat-icon.tomorrow { background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); }
        .stat-icon.week { background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%); }
        .stat-icon.ongoing { background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); }

        .stat-content h3 {
            font-size: 26px;
            font-weight: 800;
            color: #1f2937;
            line-height: 1;
        }

        .stat-content p {
            font-size: 11px;
            color: #6b7280;
            font-weight: 600;
            margin-top: 4px;
        }

        /* Events Grid */
        .events-grid {
            padding: 0 16px 24px 16px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .event-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
            border-left: 5px solid #e5e7eb;
            position: relative;
            overflow: hidden;
        }

        .event-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, rgba(154, 101, 229, 0.3) 0%, rgba(154, 101, 229, 0.05) 100%);
        }

        /* États des événements */
        .event-card.ongoing {
            border-left-color: #10b981;
            background: linear-gradient(135deg, #ffffff 0%, #f0fdf4 100%);
            animation: glow 2s ease-in-out infinite;
        }

        .event-card.ongoing::before {
            background: linear-gradient(90deg, #10b981 0%, #6ee7b7 100%);
        }

        @keyframes glow {
            0%, 100% { box-shadow: 0 2px 12px rgba(16, 185, 129, 0.2); }
            50% { box-shadow: 0 4px 24px rgba(16, 185, 129, 0.4); }
        }

        .event-card.imminent {
            border-left-color: #f59e0b;
            background: linear-gradient(135deg, #ffffff 0%, #fffbeb 100%);
        }

        .event-card.imminent::before {
            background: linear-gradient(90deg, #f59e0b 0%, #fcd34d 100%);
        }

        .event-card.soon {
            border-left-color: #3b82f6;
            background: linear-gradient(135deg, #ffffff 0%, #eff6ff 100%);
        }

        .event-card.soon::before {
            background: linear-gradient(90deg, #3b82f6 0%, #93c5fd 100%);
        }

        /* Header de l'événement */
        .event-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 16px;
        }

        .event-emoji {
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

        .event-card.ongoing .event-emoji {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(167, 243, 208, 0.3) 100%);
        }

        .event-info-header {
            flex: 1;
            min-width: 0;
        }

        .event-title {
            font-size: 18px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 8px;
            line-height: 1.3;
            word-wrap: break-word;
        }

        /* Badges */
        .event-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
        }

        .badge.date {
            background: #e0e7ff;
            color: #3730a3;
        }

        .badge.time {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge.countdown {
            background: #fef3c7;
            color: #92400e;
        }

        .badge.ongoing-badge {
            background: #d1fae5;
            color: #065f46;
            animation: pulse-badge 2s ease-in-out infinite;
        }

        @keyframes pulse-badge {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        .badge.imminent-badge {
            background: #fed7aa;
            color: #7c2d12;
        }

        /* Infos supplémentaires */
        .event-details {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .event-detail-item {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #6b7280;
            font-size: 13px;
            font-weight: 600;
        }

        .event-detail-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: rgba(154, 101, 229, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        .event-description {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 2px solid #f3f4f6;
            color: #4b5563;
            line-height: 1.6;
            font-size: 14px;
        }

        /* Progress bar pour événement en cours */
        .progress-container {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 2px solid #f3f4f6;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: #6b7280;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e5e7eb;
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #10b981 0%, #6ee7b7 100%);
            border-radius: 10px;
            transition: width 0.3s ease;
        }

        /* Empty State */
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

        /* Error */
        .error {
            background: linear-gradient(135deg, #ffffff 0%, #fef2f2 100%);
            border-left: 5px solid #ef4444;
            color: #991b1b;
            padding: 20px;
            border-radius: 20px;
            margin: 16px;
            box-shadow: 0 2px 12px rgba(239, 68, 68, 0.1);
            font-weight: 600;
        }

        /* Tablet (600px+) */
        @media (min-width: 600px) {
            .container {
                max-width: 720px;
                margin: 0 auto;
            }

            .header {
                padding: 32px 24px 40px 24px;
                border-radius: 0 0 24px 24px;
            }

            .header h1 {
                font-size: 32px;
            }

            .stats-bar {
                grid-template-columns: repeat(4, 1fr);
                padding: 20px 24px;
            }

            .events-grid {
                padding: 0 24px 32px 24px;
                gap: 20px;
            }
        }

        /* Desktop (1024px+) */
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

            .header h1 {
                font-size: 36px;
            }

            .stats-bar {
                padding: 32px 0;
                gap: 20px;
            }

            .stat-card:hover {
                transform: translateY(-4px);
                box-shadow: 0 8px 24px rgba(154, 101, 229, 0.15);
            }

            .events-grid {
                padding: 0 0 48px 0;
                gap: 24px;
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(480px, 1fr));
            }

            .event-card:hover {
                transform: translateY(-4px);
                box-shadow: 0 12px 32px rgba(154, 101, 229, 0.15);
            }
        }
    </style>
</head>
<body>

<script>
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js')
      .then(() => console.log('Service Worker enregistré!'));
  }
</script>




    <div class="container">
        <div class="header">
            <div class="header-content">
                <div class="header-title">
                    <span class="header-icon">📅</span>
                    <h1>Mon Calendrier</h1>
                </div>
                <div class="header-actions">
                    <button class="open-new-tab-btn" title="Ouvrir dans un nouvel onglet" onclick="window.open(window.location.href, '_blank')">↗️ Ouvrir</button>
                    <button class="refresh-btn" title="Rafraîchir" onclick="location.reload()">🔄</button>
                </div>
            </div>
            <div class="current-time">
                <span>🕐</span>
                <span id="currentTime"></span>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="error">
                ❌ <?= htmlspecialchars($error) ?>
            </div>
        <?php elseif (empty($events)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">🎉</div>
                <h3>Aucun événement à venir</h3>
                <p>Profitez de ce moment libre !</p>
            </div>
        <?php else: ?>
            <div class="stats-bar">
                <div class="stat-card">
                    <div class="stat-icon today">☀️</div>
                    <div class="stat-content">
                        <h3><?= $stats['today'] ?></h3>
                        <p>Aujourd'hui</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon tomorrow">🌙</div>
                    <div class="stat-content">
                        <h3><?= $stats['tomorrow'] ?></h3>
                        <p>Demain</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon week">📆</div>
                    <div class="stat-content">
                        <h3><?= $stats['week'] ?></h3>
                        <p>Cette semaine</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon ongoing">✨</div>
                    <div class="stat-content">
                        <h3><?= $stats['ongoing'] ?></h3>
                        <p>En cours</p>
                    </div>
                </div>
            </div>

            <div class="events-grid">
                <?php foreach ($events as $event): 
                    $status = getEventStatus($event['start'], $event['end']);
                    $emoji = getSubjectEmoji($event['title']);
                    
                    // Date formatting
                    $months = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
                    $today = new DateTime();
                    $today->setTime(0, 0, 0);
                    $tomorrow = clone $today;
                    $tomorrow->modify('+1 day');
                    $eventDate = clone $event['start'];
                    $eventDate->setTime(0, 0, 0);
                    
                    if ($eventDate == $today) {
                        $dateStr = "Aujourd'hui";
                    } elseif ($eventDate == $tomorrow) {
                        $dateStr = "Demain";
                    } else {
                        $dateStr = $event['start']->format('d') . ' ' . $months[(int)$event['start']->format('n')];
                    }
                    
                    $timeStr = $event['start']->format('H:i') . ' - ' . $event['end']->format('H:i');
                    
                    // Countdown
                    $now = new DateTime();
                    $interval = $now->diff($event['start']);
                    $countdown = '';
                    
                    if ($status == 'ongoing') {
                        $countdown = 'En cours';
                    } else {
                        if ($interval->days == 0) {
                            $hours = $interval->h;
                            $minutes = $interval->i;
                            if ($hours == 0) {
                                $countdown = 'Dans ' . $minutes . 'min';
                            } else {
                                $countdown = 'Dans ' . $hours . 'h' . ($minutes > 0 ? $minutes : '');
                            }
                        } else {
                            $countdown = 'Dans ' . $interval->days . 'j';
                        }
                    }
                    
                    // Progress for ongoing events
                    $progress = 0;
                    if ($status == 'ongoing') {
                        $total = $event['end']->getTimestamp() - $event['start']->getTimestamp();
                        $elapsed = $now->getTimestamp() - $event['start']->getTimestamp();
                        $progress = ($elapsed / $total) * 100;
                        
                        $remaining = $event['end']->getTimestamp() - $now->getTimestamp();
                        $remainingMinutes = floor($remaining / 60);
                    }
                ?>
                    <div class="event-card <?= $status ?>">
                        <div class="event-header">
                            <div class="event-emoji"><?= $emoji ?></div>
                            <div class="event-info-header">
                                <div class="event-title"><?= htmlspecialchars($event['title']) ?></div>
                            </div>
                        </div>
                        
                        <div class="event-badges">
                            <span class="badge date">📅 <?= $dateStr ?></span>
                            <span class="badge time">🕐 <?= $timeStr ?></span>
                            <?php if ($status == 'ongoing'): ?>
                                <span class="badge ongoing-badge">⚡ <?= $countdown ?></span>
                            <?php elseif ($status == 'imminent'): ?>
                                <span class="badge imminent-badge">🔔 <?= $countdown ?></span>
                            <?php else: ?>
                                <span class="badge countdown">⏰ <?= $countdown ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="event-details">
                            <?php if (!empty($event['location'])): ?>
                                <div class="event-detail-item">
                                    <div class="event-detail-icon">📍</div>
                                    <span><?= htmlspecialchars($event['location']) ?></span>
                                </div>
                            <?php endif; ?>
                            
                            <?php 
                            $duration = $event['end']->getTimestamp() - $event['start']->getTimestamp();
                            $durationMinutes = floor($duration / 60);
                            $durationHours = floor($durationMinutes / 60);
                            $durationMin = $durationMinutes % 60;
                            $durationStr = $durationHours > 0 ? $durationHours . 'h' . ($durationMin > 0 ? $durationMin : '') : $durationMinutes . 'min';
                            ?>
                            <div class="event-detail-item">
                                <div class="event-detail-icon">⏱️</div>
                                <span>Durée : <?= $durationStr ?></span>
                            </div>
                        </div>
                        
                        <?php if (!empty($event['description'])): ?>
                            <div class="event-description">
                                <?= nl2br(htmlspecialchars($event['description'])) ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($status == 'ongoing'): ?>
                            <div class="progress-container">
                                <div class="progress-label">
                                    <span>⚡ Cours en cours</span>
                                    <span>Il reste <?= $remainingMinutes ?>min</span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?= $progress ?>%"></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Mise à jour de l'heure en temps réel
        function updateTime() {
            const now = new Date();
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            
            const days = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
            const months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
            
            const dayName = days[now.getDay()];
            const day = now.getDate();
            const month = months[now.getMonth()];
            
            document.getElementById('currentTime').textContent = 
                `${dayName} ${day} ${month} • ${hours}:${minutes}:${seconds}`;
        }
        
        updateTime();
        setInterval(updateTime, 1000);
        
        // Animation au chargement
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.event-card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    card.style.transition = 'all 0.5s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
            
            // Auto-refresh toutes les 5 minutes
            setTimeout(() => location.reload(), 5 * 60 * 1000);
        });
        
        // Animation du bouton refresh
        document.querySelector('.refresh-btn').addEventListener('click', function() {
            this.classList.add('spinning');
        });
        
        // Notification pour événements imminents (si permissions accordées)
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }
        
        // Vérifier les événements imminents toutes les minutes
        function checkUpcomingEvents() {
            const imminentCards = document.querySelectorAll('.event-card.imminent');
            imminentCards.forEach(card => {
                const title = card.querySelector('.event-title').textContent;
                const badge = card.querySelector('.badge.imminent-badge').textContent;
                
                if (Notification.permission === 'granted') {
                    // Logique de notification (à adapter selon vos besoins)
                }
            });
        }
        
        setInterval(checkUpcomingEvents, 60000);
    </script>
</body>
</html>
