<?php
require_once 'header.php';

// Check if API key is configured
$stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'ai_api_key' LIMIT 1");
$stmt->execute();
$apiKey = trim($stmt->fetchColumn() ?: '');
$hasApiKey = !empty($apiKey);
$tenant_key_val = $_SESSION['tenant_key'] ?? 'default';
?>

<script>
    var TENANT_KEY = '<?php echo $tenant_key_val; ?>';
</script>

<div class="row justify-content-center ai-chat-wrapper py-4">
    <div class="col-md-11 col-lg-9 h-100">
        <div class="card chat-card border-0 rounded-5 shadow-2xl overflow-hidden glass-ui">
            <!-- Header -->
            <div class="card-header border-0 py-4 px-4 d-flex align-items-center justify-content-between chat-header">
                <div class="d-flex align-items-center">
                    <div class="ai-status-pulse me-3">
                        <div class="avatar-header bg-gradient-primary shadow-lg">
                            <i class="bi bi-robot fs-4"></i>
                        </div>
                        <span class="status-indicator"></span>
                    </div>
                    <div>
                        <h4 class="mb-0 fw-800 text-dark ls-tight">VisitPilot AI</h4>
                        <span class="text-muted small fw-medium"><i class="bi bi-shield-check text-success"></i> Secure
                            Enterprise Assistant</span>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button id="talkbackToggle" class="btn glass-badge text-primary rounded-pill px-3 py-2"
                        onclick="toggleTalkback()" title="Toggle AI Voice">
                        <i id="talkbackIcon" class="bi bi-volume-up-fill me-1"></i> <span id="talkbackStatus"
                            class="d-none d-md-inline">Talkback ON</span>
                    </button>
                    <span class="badge glass-badge text-primary rounded-pill px-3 py-2 d-none d-md-inline-block"><i
                            class="bi bi-database-check me-1"></i> Data-Live</span>
                    <button class="btn btn-glass-icon" onclick="window.location.reload()"><i
                            class="bi bi-arrow-clockwise"></i></button>
                </div>
            </div>

            <!-- Chat Body -->
            <div class="card-body p-4 p-lg-5" id="chat-container">
                <div class="chat-bubble ai-bubble mb-4 animate__animated animate__fadeInUp">
                    <div class="avatar-box bg-white shadow-sm"><i class="bi bi-robot"></i></div>
                    <div class="message-wrapper">
                        <div class="message shadow-soft">
                            Hello! I am your **VisitPilot AI Assistant**. I'm ready to help you with visitor logs,
                            department stats, or security insights.
                            What would you like to know?
                        </div>
                        <button class="speaker-btn-premium mt-2"
                            onclick="speakText(this, 'Hello! I am your VisitPilot AI Assistant. I\'m ready to help you with visitor logs, department stats, or security insights. What would you like to know?')">
                            <i class="bi bi-volume-up"></i> Listen Response
                        </button>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="card-footer border-0 bg-transparent p-4 pb-0">
                <div class="quick-questions-menu d-flex flex-wrap gap-2 mb-3">
                    <button class="btn q-chip" onclick="quickQuery('summary of dashboard')"><i
                            class="bi bi-graph-up text-primary me-1"></i> Dashboard Summary</button>
                    <button class="btn q-chip" onclick="quickQuery('who is in right now?')"><i
                            class="bi bi-people text-info me-1"></i> Who is In?</button>
                    <button class="btn q-chip" onclick="quickQuery('visits today')"><i
                            class="bi bi-calendar-event text-success me-1"></i> Today's Visits</button>
                    <button class="btn q-chip" onclick="quickQuery('month wise visits')"><i
                            class="bi bi-bar-chart text-warning me-1"></i> Monthly Report</button>
                    <button class="btn q-chip" onclick="quickQuery('employee wise visits')"><i
                            class="bi bi-person-badge text-danger me-1"></i> Host Performance</button>
                    <button class="btn q-chip bg-primary text-white" data-bs-toggle="modal"
                        data-bs-target="#knowledgeMenuModal"><i class="bi bi-book-half me-1"></i> Full Knowledge
                        Menu</button>
                </div>
            </div>

            <div class="card-footer border-0 bg-transparent p-4 pt-2">
                <div class="input-wrapper shadow-2xl rounded-pill glass-input-group p-2">
                    <button class="btn btn-icon-round mic-btn-xl" id="micBtn" title="Speak to AI">
                        <i class="bi bi-mic-fill"></i>
                    </button>
                    <input type="text" id="chatInput" class="form-control border-0 bg-transparent px-3 fs-5"
                        placeholder="Ask anything... (e.g. 'Who is in right now?')">
                    <button class="btn btn-primary-premium rounded-pill" id="sendBtn">
                        <i class="bi bi-send-fill me-2"></i> Send
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    :root {
        --primary-gradient: linear-gradient(135deg, #0d6efd 0%, #20c997 100%);
        --glass-bg: rgba(255, 255, 255, 0.75);
        --glass-border: rgba(255, 255, 255, 0.3);
    }

    .ai-chat-wrapper {
        min-height: 85vh;
        background: url('../assets/img/website%20images/ai_chat_bg.png') center/cover no-repeat;
        border-radius: 30px;
        position: relative;
    }

    .glass-ui {
        background: var(--glass-bg);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid var(--glass-border) !important;
    }

    .chat-card {
        height: 80vh;
        display: flex;
        flex-direction: column;
    }

    #chat-container {
        flex: 1;
        overflow-y: auto;
        padding-bottom: 20px;
        scrollbar-width: thin;
        scrollbar-color: #0d6efd transparent;
    }

    /* Bubbles */
    .chat-bubble {
        display: flex;
        gap: 15px;
        margin-bottom: 25px;
        max-width: 85%;
    }

    .user-bubble {
        flex-direction: row-reverse;
        margin-left: auto;
    }

    .avatar-box,
    .avatar-header {
        width: 45px;
        height: 45px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        flex-shrink: 0;
    }

    .bg-gradient-primary {
        background: var(--primary-gradient);
        color: white;
    }

    .ai-bubble .avatar-box {
        background: var(--primary-gradient);
        color: white;
        box-shadow: 0 10px 20px rgba(13, 110, 253, 0.2);
    }

    .user-bubble .avatar-box {
        background: white;
        color: #6c757d;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
    }

    .message-wrapper {
        display: flex;
        flex-direction: column;
    }

    .message {
        padding: 16px 22px;
        border-radius: 20px;
        font-size: 1.05rem;
        line-height: 1.6;
        position: relative;
    }

    .ai-bubble .message {
        background: white;
        color: #2b2b2b;
        border-top-left-radius: 4px;
    }

    .user-bubble .message {
        background: var(--primary-gradient);
        color: white;
        border-top-right-radius: 4px;
        box-shadow: 0 10px 25px rgba(13, 110, 253, 0.2);
    }

    /* Premium Features */
    .mic-btn-xl {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: #f8f9fa;
        color: #6c757d;
        border: none;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .mic-btn-xl:hover {
        background: #e9ecef;
    }

    .mic-active {
        background: #dc3545 !important;
        color: white !important;
        animation: pulse-red-premium 1.5s infinite;
    }

    @keyframes pulse-red-premium {
        0% {
            box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.4);
        }

        70% {
            box-shadow: 0 0 0 15px rgba(220, 53, 69, 0);
        }

        100% {
            box-shadow: 0 0 0 0 rgba(220, 53, 69, 0);
        }
    }

    .speaker-btn-premium {
        background: transparent;
        border: none;
        color: #0d6efd;
        font-size: 0.85rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 5px;
        padding: 0;
        opacity: 0.7;
        transition: opacity 0.2s;
    }

    .speaker-btn-premium:hover {
        opacity: 1;
    }

    .glass-input-group {
        background: white;
        display: flex;
        align-items: center;
        border: 1px solid rgba(0, 0, 0, 0.05);
    }

    .btn-primary-premium {
        background: var(--primary-gradient);
        color: white;
        border: none;
        padding: 12px 28px;
        font-weight: 700;
        box-shadow: 0 8px 15px rgba(13, 110, 253, 0.3);
        transition: transform 0.2s;
    }

    .btn-primary-premium:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 20px rgba(13, 110, 253, 0.4);
    }

    .status-indicator {
        position: absolute;
        bottom: 2px;
        right: 2px;
        width: 12px;
        height: 12px;
        background: #20c997;
        border: 2px solid white;
        border-radius: 50%;
    }

    .ai-status-pulse {
        position: relative;
    }

    .ls-tight {
        letter-spacing: -0.5px;
    }

    .shadow-soft {
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.03);
    }

    .fw-800 {
        font-weight: 800;
    }

    .q-chip {
        background: rgba(255, 255, 255, 0.7);
        border: 1px solid rgba(13, 110, 253, 0.1);
        border-radius: 50px;
        padding: 5px 15px;
        font-size: 0.85rem;
        font-weight: 600;
        color: #495057;
        transition: all 0.2s;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.02);
    }

    .q-chip:hover {
        background: var(--primary-gradient);
        color: white;
        transform: translateY(-2px);
        border-color: transparent;
        box-shadow: 0 6px 15px rgba(13, 110, 253, 0.2);
    }

    .q-chip:hover i {
        color: white !important;
    }

    .q-link-btn {
        cursor: pointer;
        padding: 10px 15px !important;
        font-size: 0.85rem;
        font-weight: 500;
        transition: all 0.2s;
        border: none !important;
        border-radius: 8px !important;
        margin-bottom: 2px;
        background: transparent;
        text-align: left;
    }

    .q-link-btn:hover {
        background: #f1f5f9 !important;
        color: #0d6efd !important;
        transform: translateX(5px);
    }

    /* Massive Modal Fixes */
    .modal-massive {
        max-width: 95% !important;
        width: 1300px !important;
    }

    .q-link-btn {
        white-space: nowrap !important;
        display: block !important;
        width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        padding: 12px 15px !important;
        border-radius: 10px !important;
        border: 1px solid rgba(0, 0, 0, 0.03) !important;
        margin-bottom: 5px;
        text-align: left;
    }
</style>

<!-- Knowledge Menu Modal (Native Bootstrap for Width & Reliability) -->
<div class="modal fade" id="knowledgeMenuModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-massive">
        <div class="modal-content border-0 shadow-2xl rounded-5 overflow-hidden">
            <div class="modal-header bg-primary text-white border-0 p-4">
                <h5 class="modal-title fw-800"><i class="bi bi-book-half me-2"></i> VisitPilot AI Master Knowledge
                    Syllabus</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>
            <div class="modal-body p-5">
                <!-- <p class="text-muted text-center mb-5 fs-5">Select any operational query below for instant AI analysis and reporting.</p> -->
                <div class="row g-4">
                    <!-- Column 1: Command Center -->
                    <div class="col-md-3">
                        <div class="p-4 rounded-4 bg-light shadow-sm h-100 border-top border-primary border-5">
                            <h6 class="fw-bold text-primary mb-4 pb-2 border-bottom d-flex align-items-center">
                                <i class="bi bi-speedometer2 fs-4 me-3"></i> Global & Current
                            </h6>
                            <div class="list-group list-group-flush bg-transparent">
                                <button class="q-link-btn" onclick="modalQuery('dashboard summary')">📊 Dashboard
                                    Summary</button>
                                <button class="q-link-btn" onclick="modalQuery('who is in')">🏢 Total Onsite
                                    Visitors</button>
                                <button class="q-link-btn" onclick="modalQuery('visits today')">📅 Total Visitors
                                    Today</button>
                                <button class="q-link-btn" onclick="modalQuery('all pending visits')">⏳ Pending
                                    Approvals</button>
                                <button class="q-link-btn" onclick="modalQuery('total visit')">📚 Total Visit
                                    History</button>
                                <button class="q-link-btn" onclick="modalQuery('total visitor')">👥 Unique Visitor
                                    Count</button>
                                <button class="q-link-btn" onclick="modalQuery('repeat visitor')">🔄 Repeat
                                    Visitors</button>
                                <button class="q-link-btn" onclick="modalQuery('help')">💡 AI Help</button>
                            </div>
                        </div>
                    </div>

                    <!-- Column 2: Staff & History -->
                    <div class="col-md-3">
                        <div class="p-4 rounded-4 bg-light shadow-sm h-100 border-top border-warning border-5">
                            <h6 class="fw-bold text-warning mb-4 pb-2 border-bottom d-flex align-items-center">
                                <i class="bi bi-clock-history fs-4 me-3"></i> Staff & History
                            </h6>
                            <div class="list-group list-group-flush bg-transparent">
                                <button class="q-link-btn" onclick="modalQuery('yesterday count')">⏪ Yesterday
                                    Summary</button>
                                <button class="q-link-btn" onclick="modalQuery('rejected yesterday')">🚫 Yesterday
                                    Rejections</button>
                                <button class="q-link-btn" onclick="modalQuery('visits tomorrow')">⏩ Tomorrow
                                    Schedule</button>
                                <button class="q-link-btn" onclick="modalQuery('host performance')">🏆 Top Hosting
                                    Staff</button>
                                <button class="q-link-btn" onclick="modalQuery('staff attendance')">📋 Today
                                    Attendance</button>
                                <button class="q-link-btn" onclick="modalQuery('dept breakdown')">🏢 Department-wise
                                    Visitors</button>
                                <button class="q-link-btn" onclick="modalQuery('total staff')">👤 Total Staff
                                    Count</button>
                                <button class="q-link-btn" onclick="modalQuery('inactive employee')">🔒 Inactive
                                    Staff</button>
                            </div>
                        </div>
                    </div>

                    <!-- Column 3: Advanced Analytics -->
                    <div class="col-md-3">
                        <div class="p-4 rounded-4 bg-light shadow-sm h-100 border-top border-success border-5">
                            <h6 class="fw-bold text-success mb-4 pb-2 border-bottom d-flex align-items-center">
                                <i class="bi bi-graph-up-arrow fs-4 me-3"></i> Trends & Analytics
                            </h6>
                            <div class="list-group list-group-flush bg-transparent">
                                <button class="q-link-btn" onclick="modalQuery('month wise visits')">📈 Monthly
                                    Chart</button>
                                <button class="q-link-btn" onclick="modalQuery('busiest hour')">🕐 Peak Hour</button>
                                <button class="q-link-btn" onclick="modalQuery('busiest day')">🗓️ Busiest Day</button>
                                <button class="q-link-btn" onclick="modalQuery('avg duration')">⏱️ Average Stay</button>
                                <button class="q-link-btn" onclick="modalQuery('visitor growth')">📊 Total Visitor
                                    Growth</button>
                                <button class="q-link-btn" onclick="modalQuery('busiest area')">📍 Entry Point
                                    Traffic</button>
                                <button class="q-link-btn" onclick="modalQuery('overstay alerts')">⚠️ Overstay
                                    Alerts</button>
                                <button class="q-link-btn" onclick="modalQuery('security summary')">🚨 Security
                                    Summary</button>
                            </div>
                        </div>
                    </div>

                    <!-- Column 4: Operational Reports -->
                    <div class="col-md-3">
                        <div class="p-4 rounded-4 bg-light shadow-sm h-100 border-top border-danger border-5">
                            <h6 class="fw-bold text-danger mb-4 pb-2 border-bottom d-flex align-items-center">
                                <i class="bi bi-file-earmark-bar-graph fs-4 me-3"></i> Pro Reports
                            </h6>
                            <div class="list-group list-group-flush bg-transparent">
                                <button class="q-link-btn" onclick="modalQuery('department density')">🏢 Department
                                    Density</button>
                                <button class="q-link-btn" onclick="modalQuery('area density')">📍 Area Density</button>
                                <button class="q-link-btn" onclick="modalQuery('overstay clients')">🚩 Overstay Visitor
                                    List</button>
                                <button class="q-link-btn" onclick="modalQuery('ai insights')">✨ AI Predictions</button>
                                <button class="q-link-btn" onclick="modalQuery('rejected today')">🚫 Rejection
                                    Details</button>
                                <button class="q-link-btn" onclick="modalQuery('who visited recently')">🕒 Recent
                                    Check-ins</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const chatContainer = document.getElementById('chat-container');
    const chatInput = document.getElementById('chatInput');
    const sendBtn = document.getElementById('sendBtn');

    // Talkback State management
    let talkbackEnabled = true;
    function toggleTalkback() {
        talkbackEnabled = !talkbackEnabled;
        const icon = document.getElementById('talkbackIcon');
        const status = document.getElementById('talkbackStatus');
        const btn = document.getElementById('talkbackToggle');

        if (talkbackEnabled) {
            icon.className = 'bi bi-volume-up-fill me-1';
            if (status) status.innerText = 'Talkback ON';
            btn.classList.add('text-primary');
            btn.classList.remove('text-muted');
        } else {
            icon.className = 'bi bi-volume-mute-fill me-1';
            if (status) status.innerText = 'Talkback OFF';
            btn.classList.remove('text-primary');
            btn.classList.add('text-muted');
            // IMMEDIATELY stop currently playing speech
            if ('speechSynthesis' in window) {
                window.speechSynthesis.cancel();
            }
        }
    }

    window.quickQuery = function (text) {
        if ('speechSynthesis' in window) window.speechSynthesis.cancel();
        chatInput.value = text;
        sendMessage();
    };

    window.modalQuery = function (text) {
        const modalEl = document.getElementById('knowledgeMenuModal');
        const modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();
        window.quickQuery(text);
    };

    function appendMessage(role, text) {
        const bubble = document.createElement('div');
        bubble.className = `chat-bubble ${role}-bubble mb-4 animate__animated animate__fadeInUp`;

        const avatar = role === 'ai' ? '<i class="bi bi-robot"></i>' : '<i class="bi bi-person-fill"></i>';
        const speakerBtn = role === 'ai' ? `<button class="speaker-btn-premium mt-2" onclick="speakText(this, '${text.replace(/'/g, "\\'").replace(/\n/g, " ")}')"><i class="bi bi-volume-up"></i> Listen Response</button>` : '';

        bubble.innerHTML = `
            <div class="avatar-box shadow-sm">${avatar}</div>
            <div class="message-wrapper">
                <div class="message shadow-soft">${text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>').replace(/\n/g, '<br>')}</div>
                ${speakerBtn}
            </div>
        `;

        chatContainer.appendChild(bubble);
        chatContainer.scrollTop = chatContainer.scrollHeight;

        // Auto-speak AI responses
        if (role === 'ai') {
            const tempBtn = bubble.querySelector('.speaker-btn-premium');
            if (tempBtn) speakText(tempBtn, text, true);
        }
    }

    // Pass hasApiKey from PHP to JS safely
    const hasApiKey = <?php echo json_encode($hasApiKey); ?>;
    const isAdmin = <?php echo json_encode($_SESSION['role'] === 'admin'); ?>;

    async function sendMessage() {
        const query = chatInput.value.trim();
        if (!query) return;

        // Stop current speech before sending new request
        if ('speechSynthesis' in window) window.speechSynthesis.cancel();

        if (!hasApiKey) {
            chatInput.value = '';
            Swal.fire({
                icon: 'warning',
                title: 'AI API Key not configured.',
                html: 'You need to link your API key in **AI Integration Settings** to use the assistant.<br><br>' +
                    'Don\'t have an API key? <a href="https://aistudio.google.com/app/apikey" target="_blank" class="fw-bold text-primary">Get your Gemini API Key here (Free)</a>' +
                    (isAdmin ? '<br><br><a href="../admin/settings.php?tab=ai" class="btn btn-primary btn-sm rounded-pill px-3">Link your API here</a>' : ''),
                confirmButtonText: 'Got it'
            });
            return;
        }

        appendMessage('user', query);
        chatInput.value = '';

        // Add Loading bubble
        const loadingId = 'loading-' + Date.now();
        const loadingBubble = document.createElement('div');
        loadingBubble.id = loadingId;
        loadingBubble.className = 'chat-bubble ai-bubble mb-4 animate__animated animate__fadeInUp';
        loadingBubble.innerHTML = `
            <div class="avatar-box shadow-sm"><i class="bi bi-robot"></i></div>
            <div class="message-wrapper">
                <div class="message shadow-soft italic text-muted">VisitPilot is thinking...</div>
            </div>
        `;
        chatContainer.appendChild(loadingBubble);
        chatContainer.scrollTop = chatContainer.scrollHeight;

        try {
            const response = await fetch(`../api/ai/process.php?tenant=${TENANT_KEY}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ query: query, mode: 'chat' })
            });

            // Check if response is OK
            if (!response.ok) {
                const errText = await response.text();
                loadingBubble.remove();
                appendMessage('ai', "Server Error (" + response.status + "): " + errText.substring(0, 100));
                return;
            }

            const result = await response.json();
            loadingBubble.remove();

            if (result.status === 'success') {
                appendMessage('ai', result.message);
            } else {
                appendMessage('ai', "Brain says: " + result.message);
            }
        } catch (error) {
            loadingBubble.remove();
            console.error("DEBUG:", error);
            appendMessage('ai', "Connection Error: Please refresh and try again. (" + error.message + ")");
        }
    }

    sendBtn.addEventListener('click', sendMessage);
    chatInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') sendMessage();
    });

    // Voice Input (STT)
    const micBtn = document.getElementById('micBtn');
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

    if (SpeechRecognition) {
        const recognition = new SpeechRecognition();
        recognition.continuous = true;
        recognition.interimResults = true;
        recognition.lang = 'en-IN';

        let silenceTimer;
        let isStartedManually = false;

        micBtn.onclick = () => {
            if (micBtn.classList.contains('mic-active')) {
                isStartedManually = false;
                recognition.stop();
            } else {
                chatInput.value = '';
                isStartedManually = true;
                try { recognition.start(); } catch (e) { }
            }
        };

        recognition.onstart = () => {
            micBtn.classList.add('mic-active');
            chatInput.placeholder = "Listening... (Talk freely)";
        };

        recognition.onend = () => {
            if (isStartedManually) {
                try { recognition.start(); return; } catch (e) { }
            }
            micBtn.classList.remove('mic-active');
            chatInput.placeholder = "Ask anything... (e.g. 'Who is in right now?') text here...";
            clearTimeout(silenceTimer);
        };

        recognition.onresult = (event) => {
            clearTimeout(silenceTimer);
            let transcript = '';
            for (let i = 0; i < event.results.length; i++) {
                let segment = event.results[i][0].transcript;
                if (i > 0 && !transcript.endsWith(' ') && !segment.startsWith(' ')) {
                    transcript += ' ';
                }
                transcript += segment;
            }
            chatInput.value = transcript;

            // Wait 3 seconds of silence before submitting
            silenceTimer = setTimeout(() => {
                if (chatInput.value.trim()) {
                    isStartedManually = false;
                    chatInput.placeholder = "Processing...";
                    sendMessage();
                    recognition.stop();
                }
            }, 3000);
        };

        recognition.onerror = (event) => {
            console.error('Speech Recognition Error:', event.error);
            micBtn.classList.remove('mic-active');
        };
    } else {
        micBtn.style.display = 'none';
    }

    // Text to Speech (TTS)
    function speakText(btn, text, isAuto = false) {
        // If it's an auto-read (isAuto is true), respect the global toggle
        if (isAuto && !talkbackEnabled) return;

        if ('speechSynthesis' in window) {
            // Cancel any ongoing speech
            window.speechSynthesis.cancel();

            // Clean text for cleaner speech (remove markdown including headers)
            let cleanText = text
                .replace(/###\s+/g, '')        // Remove H3 headers
                .replace(/\*\*(.*?)\*\*/g, '$1') // Remove bold
                .replace(/\[(.*?)\]/g, '$1')     // Remove brackets
                .replace(/⚠️|✅|📊|👋|💡/g, '')   // Remove emojis for speech
                .replace(/\n/g, '. ');           // Replace newlines with pauses

            const utterance = new SpeechSynthesisUtterance(cleanText);
            utterance.rate = 1.05;
            utterance.pitch = 1.0;

            const icon = btn ? btn.querySelector('i') : null; // Only update icon if a button is provided
            if (icon) icon.className = 'bi bi-volume-up-fill';

            utterance.onend = () => {
                if (icon) icon.className = 'bi bi-volume-up';
            };

            window.speechSynthesis.speak(utterance);
        } else if (!isAuto) {
            Swal.fire('Not Supported', 'Your browser does not support text-to-speech.', 'error');
        }
    }
</script>

<?php require_once 'footer.php'; ?>