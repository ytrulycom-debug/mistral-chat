<?php
// ============================================================
// CONFIGURATION — mets ta clé dans une variable d'environnement
// ou dans un fichier .env séparé (jamais dans le code source)
// ============================================================
$api_key = getenv('MISTRAL_API_KEY') ?: "VOTRE_CLÉ_ICI";
$model   = "mistral-small-latest"; // plus rapide et gratuit

// ============================================================
// TRAITEMENT API (requêtes POST depuis le JavaScript)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);

    // Récupère le message actuel ET l'historique envoyé depuis le front
    $user_message = trim($input['message'] ?? '');
    $history      = $input['history'] ?? [];

    if (empty($user_message)) {
        echo json_encode(['error' => 'Le message est vide.']);
        exit;
    }

    // Construit la liste complète des messages avec l'historique
    $messages = [];

    // Prompt système — donne un rôle à Mistral
    $messages[] = [
        'role'    => 'system',
        'content' => 'Tu es un assistant IA intelligent, précis et bienveillant. Tu réponds toujours en français sauf si on te demande autrement.'
    ];

    // Ajoute l'historique de la conversation
    foreach ($history as $msg) {
        if (isset($msg['role'], $msg['content'])) {
            $messages[] = [
                'role'    => $msg['role'],
                'content' => $msg['content']
            ];
        }
    }

    // Ajoute le nouveau message de l'utilisateur
    $messages[] = [
        'role'    => 'user',
        'content' => $user_message
    ];

    $payload = [
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => 0.7,
        'max_tokens'  => 1024
    ];

    $ch = curl_init('https://api.mistral.ai/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    // Décommente si erreur SSL sur Windows local :
    // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);

    // Gestion des erreurs cURL (réseau, timeout...)
    if (curl_errno($ch)) {
        echo json_encode([
            'error' => 'Erreur réseau : ' . curl_error($ch)
        ]);
        curl_close($ch);
        exit;
    }

    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Gestion des erreurs HTTP (401, 429, 500...)
    if ($http_code !== 200) {
        $decoded = json_decode($response, true);
        $msg = $decoded['message'] ?? $decoded['error']['message'] ?? 'Erreur HTTP ' . $http_code;
        echo json_encode(['error' => "Mistral a retourné une erreur ($http_code) : $msg"]);
        exit;
    }

    // Succès — on renvoie la réponse brute au front
    echo $response;
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat Mistral AI</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
            padding: 30px 20px;
        }
        .chat-container {
            width: 100%;
            max-width: 750px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .chat-header {
            background: #0047ab;
            color: white;
            padding: 18px 24px;
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .chat-header .dot {
            width: 10px; height: 10px;
            background: #4cff91;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }
        .messages {
            height: 480px;
            overflow-y: auto;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            background: #f8f9fb;
        }
        .message {
            padding: 12px 16px;
            border-radius: 12px;
            max-width: 80%;
            line-height: 1.6;
            font-size: 15px;
            word-wrap: break-word;
        }
        .user {
            background: #0047ab;
            color: white;
            margin-left: auto;
            border-bottom-right-radius: 3px;
        }
        .bot {
            background: white;
            color: #212529;
            border: 1px solid #e8eaed;
            margin-right: auto;
            border-bottom-left-radius: 3px;
            white-space: pre-wrap;
        }
        .bot.loading {
            color: #888;
            font-style: italic;
        }
        .error-msg {
            background: #fff0f0;
            color: #c0392b;
            border: 1px solid #f5c6cb;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 14px;
            margin-right: auto;
            max-width: 80%;
        }
        .input-area {
            display: flex;
            gap: 10px;
            padding: 16px 20px;
            border-top: 1px solid #eaeaea;
            background: white;
        }
        input {
            flex: 1;
            padding: 12px 16px;
            border: 1px solid #dde1e7;
            border-radius: 8px;
            font-size: 15px;
            transition: border-color 0.2s;
        }
        input:focus { border-color: #0047ab; outline: none; }
        button {
            padding: 12px 22px;
            background: #0047ab;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 600;
            transition: background 0.2s;
            white-space: nowrap;
        }
        button:hover { background: #003080; }
        button:disabled { background: #aaa; cursor: not-allowed; }
        .counter {
            text-align: center;
            font-size: 12px;
            color: #aaa;
            padding: 6px;
        }
    </style>
</head>
<body>
<div class="chat-container">
    <div class="chat-header">
        <div class="dot"></div>
        Chat Mistral AI
    </div>
    <div class="messages" id="chat-box">
        <!-- Message d'accueil -->
        <div class="message bot">Bonjour ! Je suis Mistral, votre assistant IA. Comment puis-je vous aider aujourd'hui ?</div>
    </div>
    <div class="counter" id="msg-counter">0 message(s) dans la conversation</div>
    <div class="input-area">
        <input
            type="text"
            id="user-input"
            placeholder="Écrivez votre message... (Entrée pour envoyer)"
            onkeypress="if(event.key==='Enter' && !event.shiftKey) { event.preventDefault(); sendMessage(); }"
        >
        <button id="send-btn" onclick="sendMessage()">Envoyer</button>
    </div>
</div>

<script>
    // Historique complet de la conversation (envoyé à chaque appel)
    let conversationHistory = [];

    async function sendMessage() {
        const input  = document.getElementById('user-input');
        const btn    = document.getElementById('send-btn');
        const message = input.value.trim();
        if (!message) return;

        // Affiche le message utilisateur
        appendMessage(message, 'user');
        input.value = '';
        btn.disabled = true;

        // Ajoute à l'historique local
        conversationHistory.push({ role: 'user', content: message });

        // Indicateur de chargement
        const loadingId = appendMessage("Mistral réfléchit...", 'bot loading');

        try {
            const res = await fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    message: message,
                    history: conversationHistory.slice(0, -1) // historique sans le message actuel
                })
            });

            const data = await res.json();
            removeMessage(loadingId);

            if (data.error) {
                // Affiche l'erreur exacte retournée par le serveur
                appendError(data.error);
                // Retire le dernier message de l'historique si erreur
                conversationHistory.pop();
            } else if (data.choices && data.choices[0]) {
                const reply = data.choices[0].message.content;
                appendMessage(reply, 'bot');
                // Ajoute la réponse à l'historique
                conversationHistory.push({ role: 'assistant', content: reply });
                updateCounter();
            } else {
                appendError("Réponse inattendue de l'API. Vérifie la console du navigateur.");
                console.error("Réponse API complète :", data);
                conversationHistory.pop();
            }
        } catch (err) {
            removeMessage(loadingId);
            appendError("Impossible de contacter le serveur. Laragon est-il démarré ?");
            conversationHistory.pop();
        }

        btn.disabled = false;
        input.focus();
    }

    function appendMessage(text, className) {
        const box = document.getElementById('chat-box');
        const div = document.createElement('div');
        const id  = 'msg-' + Date.now() + Math.random();
        div.id        = id;
        div.className = 'message ' + className;
        div.textContent = text;
        box.appendChild(div);
        box.scrollTop = box.scrollHeight;
        return id;
    }

    function appendError(text) {
        const box = document.getElementById('chat-box');
        const div = document.createElement('div');
        div.className   = 'error-msg';
        div.textContent = '⚠ ' + text;
        box.appendChild(div);
        box.scrollTop = box.scrollHeight;
    }

    function removeMessage(id) {
        const el = document.getElementById(id);
        if (el) el.remove();
    }

    function updateCounter() {
        const n = conversationHistory.filter(m => m.role === 'user').length;
        document.getElementById('msg-counter').textContent =
            n + ' message' + (n > 1 ? 's' : '') + ' dans la conversation';
    }
</script>
</body>
</html>
