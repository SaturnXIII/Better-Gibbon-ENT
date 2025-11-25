<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajouter - Gibbon</title>
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
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .container {
            max-width: 900px;
            width: 100%;
            padding: 40px 0;
        }

        .header {
            text-align: center;
            margin-bottom: 48px;
        }

        .header h1 {
            color: rgba(154, 101, 229, 1);
            font-size: 42px;
            font-weight: 700;
            margin-bottom: 12px;
        }

        .header p {
            color: #6b7280;
            font-size: 18px;
        }

        .buttons-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
            margin-bottom: 48px;
        }

        .action-card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 4px 12px rgba(154, 101, 229, 0.1);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            text-decoration: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            cursor: pointer;
        }

        .action-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
        }

        .action-card.note::before {
            background: linear-gradient(90deg, rgba(154, 101, 229, 1) 0%, rgba(134, 81, 209, 1) 100%);
        }

        .action-card.homework::before {
            background: linear-gradient(90deg, #3b82f6 0%, #2563eb 100%);
        }

        .action-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 32px rgba(154, 101, 229, 0.2);
        }

        .action-icon {
            width: 80px;
            height: 80px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            margin-bottom: 24px;
            transition: all 0.3s ease;
        }

        .action-card.note .action-icon {
            background: linear-gradient(135deg, rgba(244, 239, 252, 1) 0%, rgba(221, 204, 245, 1) 100%);
        }

        .action-card.homework .action-icon {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
        }

        .action-card:hover .action-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .action-title {
            font-size: 24px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 12px;
        }

        .action-description {
            font-size: 15px;
            color: #6b7280;
            line-height: 1.6;
        }

        .info-box {
            background: white;
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 4px 12px rgba(154, 101, 229, 0.1);
            border-left: 4px solid rgba(154, 101, 229, 1);
        }

        .info-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }

        .info-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: linear-gradient(135deg, rgba(244, 239, 252, 1) 0%, rgba(221, 204, 245, 1) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .info-title {
            font-size: 18px;
            font-weight: 700;
            color: #1f2937;
        }

        .info-content {
            font-size: 15px;
            color: #4b5563;
            line-height: 1.7;
        }

        .contact-link {
            color: rgba(154, 101, 229, 1);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .contact-link:hover {
            color: rgba(134, 81, 209, 1);
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .header h1 {
                font-size: 32px;
            }

            .buttons-container {
                grid-template-columns: 1fr;
            }

            .action-card {
                padding: 32px;
            }

            .info-box {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>➕ Ajouter</h1>
            <p>Choisissez l'action que vous souhaitez effectuer</p>
        </div>

        <div class="buttons-container">
            <a href="note-ui.php" class="action-card note">
                <div class="action-icon">📝</div>
                <div class="action-title">Ajouter une note</div>
                <div class="action-description">
                    Enregistrer ou mettre à jour la note d'un élève pour une évaluation
                </div>
            </a>

            <a href="homeworks-ui.php" class="action-card homework">
                <div class="action-icon">📚</div>
                <div class="action-title">Ajouter un devoir</div>
                <div class="action-description">
                    Créer un nouveau devoir et l'assigner à une classe
                </div>
            </a>
        </div>

        <div class="info-box">
            <div class="info-header">
                <div class="info-icon">💬</div>
                <div class="info-title">Besoin d'aide ?</div>
            </div>
            <div class="info-content">
                Pour tout ajout de nouveaux contrôles ou pour rectification d'informations non disponibles dans les options ci-dessus, contactez-moi sur WhatsApp ou à l'adresse e-mail suivante : 
                <a href="mailto:contact@example.com" class="contact-link">contact@example.com</a>
            </div>
        </div>
    </div>
</body>
</html>
