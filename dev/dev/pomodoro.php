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

// 🔹 Création des tables si elles n'existent pas
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pomodoroSubjects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            gibbonPersonID INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            color VARCHAR(7) DEFAULT '#FF6B6B',
            createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_subject (gibbonPersonID, name)
        )
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pomodoroSessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            gibbonPersonID INT NOT NULL,
            subjectID INT NOT NULL,
            duration INT NOT NULL,
            type ENUM('work', 'break') DEFAULT 'work',
            completedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (subjectID) REFERENCES pomodoroSubjects(id) ON DELETE CASCADE
        )
    ");
} catch (Exception $e) {
    die("Erreur lors de la création des tables : " . $e->getMessage());
}

// 🔹 Récupération des matières de l'utilisateur
$stmt = $pdo->prepare("SELECT * FROM pomodoroSubjects WHERE gibbonPersonID = :userID ORDER BY name");
$stmt->execute(['userID' => $userID]);
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 🔹 Gestion des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (isset($_POST['action'])) {
        try {
            switch ($_POST['action']) {
                case 'add_subject':
                    $stmt = $pdo->prepare("INSERT INTO pomodoroSubjects (gibbonPersonID, name, color) VALUES (:userID, :name, :color)");
                    $stmt->execute([
                        'userID' => $userID,
                        'name' => $_POST['name'],
                        'color' => $_POST['color'] ?? '#FF6B6B'
                    ]);
                    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
                    break;
                    
                case 'save_session':
                    $stmt = $pdo->prepare("INSERT INTO pomodoroSessions (gibbonPersonID, subjectID, duration, type) VALUES (:userID, :subjectID, :duration, :type)");
                    $stmt->execute([
                        'userID' => $userID,
                        'subjectID' => $_POST['subjectID'],
                        'duration' => $_POST['duration'],
                        'type' => $_POST['type'] ?? 'work'
                    ]);
                    echo json_encode(['success' => true]);
                    break;
                    
                case 'delete_subject':
                    $stmt = $pdo->prepare("DELETE FROM pomodoroSubjects WHERE id = :id AND gibbonPersonID = :userID");
                    $stmt->execute(['id' => $_POST['id'], 'userID' => $userID]);
                    echo json_encode(['success' => true]);
                    break;
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link id="favicon" rel="icon" type="image/png" href="" /> 
    <title>Pomodoro</title>
    <style>
        /* Styles CSS (Inchangé, ajout d'un style pour le select du temps) */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'SF Pro Display', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f7f6f3;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.6s ease;
            overflow-x: hidden;
        }
        
        body.working {
            background: #ff6b6b;
        }
        
        body.on-break {
            background: #51cf66;
        }
        
        .app-container {
            width: 100%;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 40px 60px;
            position: relative;
        }
        
        .left-panel {
            flex: 1;
            max-width: 300px;
        }
        
        .right-panel {
            flex: 1;
            max-width: 300px;
            text-align: right;
        }
        
        .center-panel {
            flex: 2;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .header {
            margin-bottom: 40px;
        }
        
        .header h1 {
            font-size: 18px;
            color: #2d2d2d;
            font-weight: 700;
            margin-bottom: 4px;
            letter-spacing: -0.02em;
        }
        
        .header .user {
            color: #999;
            font-size: 14px;
            font-weight: 400;
        }
        
        .nav-link {
            display: inline-block;
            margin-bottom: 32px;
            color: #666;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: color 0.2s;
        }
        
        .nav-link:hover {
            color: #2d2d2d;
        }
        
        .mode-selector {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .mode-btn, .time-select {
            padding: 14px 20px;
            border: 1.5px solid #e5e3dc;
            background: #fffef9;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            color: #666;
            transition: all 0.2s;
            text-align: left;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
        }
        
        .mode-btn.active, .time-select.active {
            background: #2d2d2d;
            color: #fffef9;
            border-color: #2d2d2d;
        }
        
        .mode-btn:hover:not(.active), .time-select:hover:not(.active) {
            border-color: #999;
            background: #f7f6f3;
        }

        .time-selector-container {
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .time-selector-container label {
            font-size: 12px;
            color: #999;
            margin-left: 5px;
        }
        
        .timer-display {
            text-align: center;
            margin-bottom: 60px;
        }
        
        .timer-display .time {
            font-size: 140px;
            font-weight: 600;
            color: #2d2d2d;
            font-variant-numeric: tabular-nums;
            letter-spacing: -6px;
            line-height: 1;
        }
        
        .controls {
            display: flex;
            gap: 16px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 18px 48px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: #2d2d2d;
            color: #fffef9;
        }
        
        .btn-primary:hover {
            background: #444;
            transform: translateY(-2px);
        }
        
        .btn-primary:active {
            transform: translateY(0);
        }
        
        .btn-secondary {
            background: #fffef9;
            color: #666;
            border: 1.5px solid #e5e3dc;
        }
        
        .btn-secondary:hover {
            background: #f7f6f3;
            border-color: #999;
        }
        
        .btn-save {
            background: #4c9f70;
            color: #fffef9;
        }
        
        .btn-save:hover {
            background: #3d8a5d;
            transform: translateY(-2px);
        }
        
        .btn-save:active {
            transform: translateY(0);
        }
        
        .btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            transform: none !important;
        }
        
        .subject-selector {
            margin-bottom: 40px;
        }
        
        .subject-selector label {
            display: block;
            margin-bottom: 10px;
            color: #666;
            font-size: 13px;
            font-weight: 500;
        }
        
        .subject-select {
            width: 100%;
            padding: 12px 14px;
            border: 1.5px solid #e5e3dc;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
            background: #fffef9;
            color: #2d2d2d;
            transition: all 0.2s;
            font-weight: 600;
        }
        
        .subject-select:hover {
            border-color: #999;
        }
        
        .subject-select:focus {
            outline: none;
            border-color: #2d2d2d;
        }
        
        .subject-management {
            background: #fffef9;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        
        .subject-management h3 {
            font-size: 13px;
            color: #666;
            margin-bottom: 16px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .add-subject-form {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 16px;
        }
        
        .add-subject-form input[type="text"] {
            padding: 10px 12px;
            border: 1.5px solid #e5e3dc;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s;
            background: #fffef9;
            font-weight: 600;
        }
        
        .add-subject-form input[type="text"]:focus {
            outline: none;
            border-color: #2d2d2d;
        }
        
        .color-row {
            display: flex;
            gap: 10px;
        }
        
        .color-picker {
            width: 44px;
            height: 44px;
            border: 1.5px solid #e5e3dc;
            border-radius: 8px;
            cursor: pointer;
            padding: 2px;
        }
        
        .btn-small {
            padding: 10px 20px;
            font-size: 13px;
            flex: 1;
            font-weight: 700;
        }
        
        .subjects-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .subject-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: #fafafa;
            border-radius: 8px;
            border: 1px solid transparent;
            transition: all 0.2s;
        }
        
        .subject-item:hover {
            background: #f5f5f5;
            border-color: #e8e8e8;
        }
        
        .subject-color {
            width: 20px;
            height: 20px;
            border-radius: 4px;
            flex-shrink: 0;
        }
        
        .subject-name {
            flex: 1;
            font-size: 14px;
            color: #2d2d2d;
            font-weight: 600;
        }
        
        .btn-delete {
            background: none;
            border: none;
            color: #ccc;
            cursor: pointer;
            font-size: 20px;
            padding: 4px 8px;
            transition: color 0.2s;
        }
        
        .btn-delete:hover {
            color: #666;
        }
        
        .status-message {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 13px;
            display: none;
            font-weight: 500;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .status-message.info {
            background: #e7f5ff;
            color: #1971c2;
        }
        
        .status-message.warning {
            background: #fff3bf;
            color: #e67700;
        }

        .status-message.success {
            background: #d3f9d8;
            color: #2b8a3e;
        }
        
        @media (max-width: 1024px) {
            .app-container {
                flex-direction: column;
                padding: 30px 20px;
            }
            
            .left-panel, .right-panel {
                max-width: 100%;
                width: 100%;
                text-align: center;
            }
            
            .right-panel {
                text-align: center;
            }
            
            .timer-display .time {
                font-size: 96px;
            }
            
            .mode-selector {
                flex-direction: row;
                margin-bottom: 20px;
            }
            
            .mode-btn, .time-select {
                flex: 1;
            }
        }
    </style>
</head>
<body>
    <div id="statusMessage" class="status-message"></div>
    
    <div class="app-container">
        <div class="left-panel">
            <div class="header">
                <h1>Pomodoro Timer</h1>
                <p class="user"><?php echo htmlspecialchars($userName); ?></p>
            </div>
            
            <a href="pomodoro-stats.php" class="nav-link">→ Voir les statistiques</a>
            
            <div class="mode-selector">
                <div class="time-selector-container">
                    <label for="workTimeSelect">Durée de travail (min)</label>
                    <select id="workTimeSelect" class="time-select active" data-mode="work">
                        <option value="15">15 min</option>
                        <option value="25" selected>25 min</option>
                        <option value="30">30 min</option>
                        <option value="45">45 min</option>
                        <option value="60">60 min</option>
                    </select>
                </div>
                <button class="mode-btn" data-mode="break" data-duration="5">Pause courte · 5min</button>
                <button class="mode-btn" data-mode="break" data-duration="15">Pause longue · 15min</button>
            </div>
        </div>
        
        <div class="center-panel">
            <div class="timer-display">
                <div class="time" id="timerDisplay">25:00</div>
            </div>
            
            <div class="controls">
                <button id="startBtn" class="btn btn-primary">Démarrer</button>
                <button id="saveBtn" class="btn btn-save" style="display: none;">💾 Sauvegarder</button>
                <button id="resetBtn" class="btn btn-secondary">Reset</button>
            </div>
        </div>
        
        <div class="right-panel">
            <div class="subject-selector">
                <label for="subjectSelect">Matière</label>
                <select id="subjectSelect" class="subject-select">
                    <option value="">Sélectionner une matière</option>
                    <?php foreach ($subjects as $subject): ?>
                        <option value="<?php echo $subject['id']; ?>">
                            <?php echo htmlspecialchars($subject['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="subject-management">
                <h3>Matières</h3>
                <div class="add-subject-form">
                    <input type="text" id="newSubjectName" placeholder="Nouvelle matière" maxlength="50">
                    <div class="color-row">
                        <input type="color" id="newSubjectColor" class="color-picker" value="#FF6B6B">
                        <button id="addSubjectBtn" class="btn btn-primary btn-small">Ajouter</button>
                    </div>
                </div>
                <div class="subjects-list" id="subjectsList">
                    <?php foreach ($subjects as $subject): ?>
                        <div class="subject-item" data-id="<?php echo $subject['id']; ?>">
                            <div class="subject-color" style="background-color: <?php echo htmlspecialchars($subject['color']); ?>"></div>
                            <span class="subject-name"><?php echo htmlspecialchars($subject['name']); ?></span>
                            <button class="btn-delete" onclick="deleteSubject(<?php echo $subject['id']; ?>)">×</button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        let timer = null;
        let timeLeft = 25 * 60;
        let initialTime = 25 * 60;
        let isRunning = false;
        let currentMode = 'work';
        let currentDuration = 25;
        let sessionStartTime = 0;
        
        const timerDisplay = document.getElementById('timerDisplay');
        const startBtn = document.getElementById('startBtn');
        const saveBtn = document.getElementById('saveBtn');
        const resetBtn = document.getElementById('resetBtn');
        const subjectSelect = document.getElementById('subjectSelect');
        const modeBtns = document.querySelectorAll('.mode-btn');
        const workTimeSelect = document.getElementById('workTimeSelect'); // Nouveau sélecteur
        const timeSelectors = document.querySelectorAll('.mode-btn, .time-select'); // Tous les sélecteurs de temps
        const statusMessage = document.getElementById('statusMessage');
        const favicon = document.getElementById('favicon');
        const pageTitle = document.querySelector('title');
        const defaultTitle = pageTitle.textContent;
        
        // URLs des emojis pour le favicon
        const EMOJI_WORK = 'https://em-content.zobj.net/source/apple/419/thinking-face_1f914.png';
        const EMOJI_BREAK = 'https://em-content.zobj.net/source/google/439/distorted-face_1faea.png';
        const EMOJI_STOPPED = 'https://em-content.zobj.net/source/apple/419/dotted-line-face_1fae5.png';

        // 🔊 Créer les sons
        const audioContext = new (window.AudioContext || window.webkitAudioContext)();
        
        function playClickSound() {
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            
            oscillator.frequency.value = 800;
            oscillator.type = 'sine';
            
            gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.1);
            
            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 0.1);
        }
        
        function playCompleteSound() {
            const notes = [523.25, 659.25, 783.99]; // C5, E5, G5
            
            notes.forEach((freq, i) => {
                const oscillator = audioContext.createOscillator();
                const gainNode = audioContext.createGain();
                
                oscillator.connect(gainNode);
                gainNode.connect(audioContext.destination);
                
                oscillator.frequency.value = freq;
                oscillator.type = 'sine';
                
                const startTime = audioContext.currentTime + (i * 0.15);
                gainNode.gain.setValueAtTime(0.2, startTime);
                gainNode.gain.exponentialRampToValueAtTime(0.01, startTime + 0.5);
                
                oscillator.start(startTime);
                oscillator.stop(startTime + 0.5);
            });
        }
        
        /**
         * Met à jour le favicon en utilisant une URL d'image spécifique (emoji).
         * @param {string} url - URL de l'image (emoji) à utiliser.
         */
        function updateFavicon(url) {
            favicon.type = 'image/png';
            favicon.href = url;
        }

        function updatePageTitle() {
            if (isRunning) {
                const timeString = timerDisplay.textContent;
                pageTitle.textContent = `(${timeString}) - ${defaultTitle}`;
            } else {
                pageTitle.textContent = defaultTitle; 
            }
        }
        
        // Demander la permission pour les notifications au chargement
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }
        
        // Initialiser le favicon au démarrage
        updateFavicon(EMOJI_STOPPED);

        // GESTION DES SÉLECTEURS DE TEMPS
        timeSelectors.forEach(selector => {
            selector.addEventListener('click', () => {
                // Pour les boutons (Pause/Longue Pause)
                if (selector.tagName === 'BUTTON' && isRunning) return;
                
                // Pour le sélecteur de travail (on change la valeur, pas besoin de bloquer)
                if (selector.tagName === 'SELECT' && selector.classList.contains('active') && isRunning) {
                    // Si on change la durée de travail en cours, il faut reset
                    resetTimer(false); 
                }

                playClickSound();
                
                // Retirer la classe active de tous et l'ajouter au sélectionné
                timeSelectors.forEach(s => s.classList.remove('active'));
                selector.classList.add('active');
                
                // Définir le mode et la durée
                currentMode = selector.dataset.mode;
                
                if (selector.tagName === 'BUTTON') {
                    // Pour les boutons (Pause/Longue Pause)
                    currentDuration = parseInt(selector.dataset.duration);
                } else if (selector.tagName === 'SELECT') {
                    // Pour le sélecteur de travail
                    currentDuration = parseInt(selector.value);
                }

                // Réinitialiser le timer à la nouvelle durée choisie
                timeLeft = currentDuration * 60;
                initialTime = currentDuration * 60;
                updateDisplay();
                
                document.body.className = '';
                updateFavicon(EMOJI_STOPPED);
            });
        });

        // GESTION DU CHANGEMENT DE SÉLECTION DANS LE SELECT DE TEMPS DE TRAVAIL
        workTimeSelect.addEventListener('change', (e) => {
            // Le "click" ci-dessus gère la plupart des cas, mais pour un 'change' sans click
            // on doit s'assurer que si on est en mode travail, le temps est mis à jour.
            if (currentMode === 'work') {
                workTimeSelect.dispatchEvent(new Event('click'));
            }
        });
        
        startBtn.addEventListener('click', () => {
            if (currentMode === 'work' && !subjectSelect.value) {
                showMessage('Sélectionnez une matière', 'warning');
                alert('⚠️ Veuillez sélectionner une matière avant de commencer');
                return;
            }
            
            playClickSound();
            
            if (!isRunning) {
                // S'assurer que la bonne durée est utilisée si le mode est "work"
                if (currentMode === 'work') {
                    currentDuration = parseInt(workTimeSelect.value);
                    initialTime = currentDuration * 60;
                    timeLeft = initialTime; // Pour être sûr que le temps est correct au démarrage
                }

                isRunning = true;
                sessionStartTime = Date.now();
                startBtn.textContent = 'Pause';
                saveBtn.style.display = 'inline-block';
                timer = setInterval(tick, 1000);
                
                // Mettre à jour le style du corps et le favicon
                if (currentMode === 'work') {
                    document.body.className = 'working';
                    updateFavicon(EMOJI_WORK); 
                } else {
                    document.body.className = 'on-break';
                    updateFavicon(EMOJI_BREAK); 
                }
            } else {
                // Pause : NE PAS sauvegarder ici
                isRunning = false;
                startBtn.textContent = 'Reprendre';
                clearInterval(timer);
                updateFavicon(EMOJI_STOPPED); 
                updatePageTitle(); 
            }
        });
        
        saveBtn.addEventListener('click', () => {
            if (currentMode === 'work' && subjectSelect.value) {
                const timeWorked = Math.round((initialTime - timeLeft) / 60);
                
                if (timeWorked > 0) {
                    playClickSound();
                    saveSession(timeWorked);
                    showMessage(`✓ ${timeWorked} min sauvegardée`, 'success');
                    
                    // Réinitialiser après sauvegarde et relancer le pomodoro à 0
                    resetTimer(true);
                } else {
                    showMessage('Aucun temps à sauvegarder', 'warning');
                }
            } else if (!subjectSelect.value) {
                showMessage('Sélectionnez une matière', 'warning');
            }
        });

        function resetTimer(isSaveAction) {
            // Sauvegarder avant reset si en cours (pour le Reset standard)
            if (!isSaveAction && isRunning && currentMode === 'work' && subjectSelect.value && timeLeft < initialTime) {
                const timeWorked = Math.round((initialTime - timeLeft) / 60);
                if (timeWorked > 0) {
                    saveSession(timeWorked);
                    showMessage(`${timeWorked} min sauvegardée (via Reset)`, 'success');
                }
            }
            
            isRunning = false;
            clearInterval(timer);
            
            // Re-lire la durée actuelle (surtout pour le mode travail)
            if (currentMode === 'work') {
                 currentDuration = parseInt(workTimeSelect.value);
            }
            
            timeLeft = currentDuration * 60;
            initialTime = currentDuration * 60;
            
            startBtn.textContent = 'Démarrer';
            saveBtn.style.display = 'none';
            updateDisplay();
            document.body.className = '';
            updateFavicon(EMOJI_STOPPED); 
            updatePageTitle(); 
        }
        
        resetBtn.addEventListener('click', () => {
            playClickSound();
            resetTimer(false);
        });
        
        function tick() {
            timeLeft--;
            updateDisplay();
            updatePageTitle(); 
            
            if (timeLeft === 0) {
                clearInterval(timer);
                isRunning = false;
                startBtn.textContent = 'Démarrer';
                saveBtn.style.display = 'none';
                document.body.className = '';
                updateFavicon(EMOJI_STOPPED); 
                
                playCompleteSound(); // 🔊 Jouer le son d'abord

                if (currentMode === 'work' && subjectSelect.value) {
                    // Sauvegarde automatique en FIN de session de travail
                    saveSession(currentDuration); 
                    showMessage(`Session de ${currentDuration} min sauvegardée`, 'success');
                }
                
                if ('Notification' in window && Notification.permission === 'granted') {
                    const title = currentMode === 'work' ? '✅ Session terminée !' : '⏰ Pause terminée !';
                    const body = currentMode === 'work' ? 'Prenez une pause bien méritée' : 'C\'est reparti pour travailler !';
                    new Notification(title, { body: body });
                }
                
                const alertMsg = currentMode === 'work' 
                    ? '✅ Session de travail terminée ! Prenez une pause.' 
                    : '⏰ Pause terminée ! Retour au travail.';
                alert(alertMsg); // 💬 Afficher le popup après le son
                
                // Reset pour la prochaine session
                resetTimer(true); 
            }
        }
        
        function updateDisplay() {
            const minutes = Math.floor(timeLeft / 60);
            const seconds = timeLeft % 60;
            timerDisplay.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        }
        
        async function saveSession(duration) {
            try {
                const formData = new FormData();
                formData.append('action', 'save_session');
                formData.append('subjectID', subjectSelect.value);
                formData.append('duration', duration);
                formData.append('type', currentMode);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                if (data.success) {
                    console.log('Session sauvegardée:', duration, 'min');
                } else {
                    showMessage('Erreur de sauvegarde: ' + data.error, 'warning');
                }
            } catch (error) {
                console.error('Erreur:', error);
                showMessage('Erreur réseau lors de la sauvegarde', 'warning');
            }
        }
        
        document.getElementById('addSubjectBtn').addEventListener('click', async () => {
            const name = document.getElementById('newSubjectName').value.trim();
            const color = document.getElementById('newSubjectColor').value;
            
            if (!name) {
                showMessage('Entrez un nom', 'warning');
                return;
            }
            
            playClickSound();
            
            try {
                const formData = new FormData();
                formData.append('action', 'add_subject');
                formData.append('name', name);
                formData.append('color', color);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                if (data.success) {
                    location.reload();
                }
            } catch (error) {
                showMessage('Erreur', 'warning');
            }
        });
        
        async function deleteSubject(id) {
            if (!confirm('Supprimer cette matière ?')) {
                return;
            }
            
            playClickSound();
            
            try {
                const formData = new FormData();
                formData.append('action', 'delete_subject');
                formData.append('id', id);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                if (data.success) {
                    location.reload();
                }
            } catch (error) {
                showMessage('Erreur', 'warning');
            }
        }
        
        function showMessage(message, type) {
            statusMessage.textContent = message;
            statusMessage.className = `status-message ${type}`;
            statusMessage.style.display = 'block';
            
            setTimeout(() => {
                statusMessage.style.display = 'none';
            }, 3000);
        }
    </script>
</body>
</html>
