(function() {
    'use strict';

    const config = window.scbConfig || {};
    let isOpen = false;
    let sessionId = localStorage.getItem('scb_session_id');
    let lastMessageId = null;
    let currentUtterance = null;
    let isSpeaking = false;
    
    // Impostazioni accessibilità (salvate in localStorage)
    let a11ySettings = {
        fontSize: localStorage.getItem('scb_font_size') || config.defaultFontSize || 'normal',
        contrast: localStorage.getItem('scb_contrast') || config.defaultContrast || 'normal'
    };
    
    // Genera session ID se non esiste
    if (!sessionId) {
        sessionId = 'scb_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        localStorage.setItem('scb_session_id', sessionId);
    }

    // Elementi DOM - Widget flottante
    const container = document.getElementById('scb-chatbot-container');
    const toggle = document.getElementById('scb-toggle');
    const chatWindow = document.getElementById('scb-chat-window');
    const closeBtn = document.getElementById('scb-close');
    const messages = document.getElementById('scb-messages');
    const suggestions = document.getElementById('scb-suggestions');
    const form = document.getElementById('scb-input-form');
    const input = document.getElementById('scb-input');
    const iconChat = document.getElementById('scb-icon-chat');
    const iconClose = document.getElementById('scb-icon-close');
    const a11yToggle = document.getElementById('scb-accessibility-toggle');
    const a11yPanel = document.getElementById('scb-accessibility-panel');

    // Elementi DOM - Widget inline (shortcode)
    const inlineWidgets = document.querySelectorAll('.scb-inline-chatbot');
    
    if (!container && !inlineWidgets.length) return;

    // Imposta colore CSS custom property
    document.documentElement.style.setProperty('--scb-primary', config.primaryColor || '#2563eb');
    
    // Applica impostazioni accessibilità iniziali
    applyAccessibilitySettings();

    // ==================== ACCESSIBILITÀ ====================
    
    function applyAccessibilitySettings() {
        var chatContainer = document.getElementById('scb-chat-window');
        if (!chatContainer) return;
        
        // Rimuovi classi esistenti
        chatContainer.classList.remove('scb-font-normal', 'scb-font-large', 'scb-font-xlarge');
        chatContainer.classList.remove('scb-contrast-normal', 'scb-contrast-high', 'scb-contrast-dark');
        
        // Applica nuove classi
        chatContainer.classList.add('scb-font-' + a11ySettings.fontSize);
        chatContainer.classList.add('scb-contrast-' + a11ySettings.contrast);
        
        // Aggiorna pulsanti attivi
        updateA11yButtons();
    }
    
    function updateA11yButtons() {
        // Font size buttons
        document.querySelectorAll('[data-fontsize]').forEach(function(btn) {
            btn.classList.toggle('active', btn.dataset.fontsize === a11ySettings.fontSize);
        });
        
        // Contrast buttons
        document.querySelectorAll('[data-contrast]').forEach(function(btn) {
            btn.classList.toggle('active', btn.dataset.contrast === a11ySettings.contrast);
        });
    }
    
    function setFontSize(size) {
        a11ySettings.fontSize = size;
        localStorage.setItem('scb_font_size', size);
        applyAccessibilitySettings();
    }
    
    function setContrast(contrast) {
        a11ySettings.contrast = contrast;
        localStorage.setItem('scb_contrast', contrast);
        applyAccessibilitySettings();
    }
    
    // Toggle pannello accessibilità
    if (a11yToggle && a11yPanel && config.enableAccessibilityPanel) {
        a11yToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            a11yPanel.classList.toggle('scb-hidden');
        });
    } else if (a11yToggle && !config.enableAccessibilityPanel) {
        a11yToggle.style.display = 'none';
    }
    
    // Click sui pulsanti accessibilità
    document.addEventListener('click', function(e) {
        if (e.target.dataset.fontsize) {
            setFontSize(e.target.dataset.fontsize);
        }
        if (e.target.dataset.contrast) {
            setContrast(e.target.dataset.contrast);
        }
    });

    // ==================== TTS (Text-to-Speech) ====================
    
    function speak(text) {
        if (!('speechSynthesis' in window)) {
            console.warn('TTS non supportato dal browser');
            return;
        }
        
        // Ferma eventuale lettura in corso
        stopSpeaking();
        
        // Rimuovi HTML e pulisci il testo
        var cleanText = text
            .replace(/<[^>]*>/g, '')
            .replace(/&amp;/g, '&')
            .replace(/&lt;/g, '<')
            .replace(/&gt;/g, '>')
            .replace(/https?:\/\/[^\s]+/g, 'link')
            .trim();
        
        if (!cleanText) return;
        
        currentUtterance = new SpeechSynthesisUtterance(cleanText);
        currentUtterance.rate = config.ttsRate || 1;
        currentUtterance.lang = detectLanguage(cleanText);
        
        currentUtterance.onstart = function() {
            isSpeaking = true;
            updateTtsButtons();
        };
        
        currentUtterance.onend = function() {
            isSpeaking = false;
            updateTtsButtons();
        };
        
        currentUtterance.onerror = function() {
            isSpeaking = false;
            updateTtsButtons();
        };
        
        window.speechSynthesis.speak(currentUtterance);
    }
    
    function stopSpeaking() {
        if ('speechSynthesis' in window) {
            window.speechSynthesis.cancel();
        }
        isSpeaking = false;
        updateTtsButtons();
    }
    
    function updateTtsButtons() {
        document.querySelectorAll('.scb-tts-btn').forEach(function(btn) {
            var isThisPlaying = isSpeaking && btn.dataset.playing === 'true';
            btn.innerHTML = isThisPlaying ? '⏹️' : '🔊';
            btn.title = isThisPlaying ? 'Ferma lettura' : 'Ascolta risposta';
            btn.dataset.playing = isThisPlaying ? 'true' : 'false';
        });
    }
    
    function detectLanguage(text) {
        // Rileva lingua per TTS
        var langPatterns = {
            'it-IT': /\b(ciao|buongiorno|grazie|scuola|orario|dove|quando)\b/i,
            'en-US': /\b(hello|thank|school|schedule|where|when|the)\b/i,
            'es-ES': /\b(hola|gracias|escuela|horario|dónde)\b/i,
            'fr-FR': /\b(bonjour|merci|école|horaire|où)\b/i,
            'de-DE': /\b(hallo|danke|schule|stundenplan|wo)\b/i
        };
        
        for (var lang in langPatterns) {
            if (langPatterns[lang].test(text)) {
                return lang;
            }
        }
        return 'it-IT';
    }

    // ==================== VOICE INPUT (Speech-to-Text) ====================
    
    var recognition = null;
    var isListening = false;
    var voiceBtn = document.getElementById('scb-voice-btn');
    
    function initVoiceInput() {
        if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
            console.warn('Speech Recognition non supportato dal browser');
            if (voiceBtn) voiceBtn.style.display = 'none';
            return;
        }
        
        if (!config.enableVoiceInput) {
            if (voiceBtn) voiceBtn.style.display = 'none';
            return;
        }
        
        var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        recognition = new SpeechRecognition();
        recognition.continuous = false;
        recognition.interimResults = true;
        recognition.lang = 'it-IT';
        
        recognition.onstart = function() {
            isListening = true;
            updateVoiceButton();
        };
        
        recognition.onend = function() {
            isListening = false;
            updateVoiceButton();
        };
        
        recognition.onerror = function(event) {
            isListening = false;
            updateVoiceButton();
            
            if (event.error === 'not-allowed') {
                alert('Per usare l\'input vocale, consenti l\'accesso al microfono.');
            }
        };
        
        recognition.onresult = function(event) {
            var transcript = '';
            for (var i = event.resultIndex; i < event.results.length; i++) {
                transcript += event.results[i][0].transcript;
            }
            
            if (input) {
                input.value = transcript;
            }
            
            // Se risultato finale, invia automaticamente
            if (event.results[event.results.length - 1].isFinal && transcript.trim()) {
                setTimeout(function() {
                    if (input && input.value.trim()) {
                        sendMessage(input.value, messages, input, suggestions);
                    }
                }, 500);
            }
        };
    }
    
    function updateVoiceButton() {
        if (!voiceBtn) return;
        
        if (isListening) {
            voiceBtn.classList.add('scb-listening');
            voiceBtn.title = 'Sto ascoltando... (clicca per fermare)';
        } else {
            voiceBtn.classList.remove('scb-listening');
            voiceBtn.title = 'Parla';
        }
    }
    
    function toggleVoiceInput() {
        if (!recognition) return;
        
        if (isListening) {
            recognition.stop();
        } else {
            // Rileva lingua dal contesto
            var detectedLang = detectLanguage(input ? input.value : '');
            recognition.lang = detectedLang;
            
            try {
                recognition.start();
            } catch (e) {
                console.error('Errore avvio riconoscimento vocale:', e);
            }
        }
    }
    
    // Inizializza voice input
    initVoiceInput();
    
    if (voiceBtn) {
        voiceBtn.addEventListener('click', function(e) {
            e.preventDefault();
            toggleVoiceInput();
        });
    }

    // ==================== WIDGET FLOTTANTE ====================
    
    if (container) {
        function toggleChat() {
            isOpen = !isOpen;
            chatWindow.classList.toggle('scb-hidden', !isOpen);
            
            if (iconChat && iconClose) {
                iconChat.style.display = isOpen ? 'none' : 'block';
                iconClose.style.display = isOpen ? 'block' : 'none';
            }
            
            if (isOpen && messages.children.length === 0) {
                addMessage(config.welcomeMessage || 'Ciao! Come posso aiutarti?', 'bot', true);
                showSuggestions();
            }
            
            if (isOpen) {
                input.focus();
            }
            
            // Ferma TTS quando chiudi
            if (!isOpen) {
                stopSpeaking();
            }
        }

        toggle.addEventListener('click', toggleChat);
        closeBtn.addEventListener('click', toggleChat);

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            sendMessage(input.value, messages, input, suggestions);
        });

        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage(input.value, messages, input, suggestions);
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && isOpen) {
                toggleChat();
            }
        });
    }

    // ==================== WIDGET INLINE ====================
    
    inlineWidgets.forEach(function(widget) {
        const inlineMessages = widget.querySelector('.scb-inline-messages');
        const inlineSuggestions = widget.querySelector('.scb-inline-suggestions');
        const inlineForm = widget.querySelector('.scb-inline-form');
        const inlineInput = widget.querySelector('.scb-inline-input');
        
        addMessageToContainer(config.welcomeMessage || 'Ciao! Come posso aiutarti?', 'bot', inlineMessages, true);
        showSuggestionsInContainer(inlineSuggestions, inlineMessages, inlineInput);
        
        inlineForm.addEventListener('submit', function(e) {
            e.preventDefault();
            sendMessage(inlineInput.value, inlineMessages, inlineInput, inlineSuggestions);
        });
    });

    // ==================== FUNZIONI COMUNI ====================

    function addMessageToContainer(text, type, container, isPrimary, messageId) {
        isPrimary = isPrimary || false;
        messageId = messageId || null;
        
        const div = document.createElement('div');
        div.className = 'scb-message scb-message-' + type;
        if (isPrimary) div.classList.add('scb-primary');
        
        // Contenuto messaggio
        var contentDiv = document.createElement('div');
        contentDiv.className = 'scb-message-content';
        contentDiv.innerHTML = linkify(text);
        div.appendChild(contentDiv);
        
        // Azioni (TTS + Feedback) per messaggi bot non-welcome
        if (type === 'bot' && !isPrimary) {
            var actionsDiv = document.createElement('div');
            actionsDiv.className = 'scb-message-actions';
            
            // Pulsante TTS
            if (config.enableTts && 'speechSynthesis' in window) {
                var ttsBtn = document.createElement('button');
                ttsBtn.className = 'scb-tts-btn';
                ttsBtn.innerHTML = '🔊';
                ttsBtn.title = 'Ascolta risposta';
                ttsBtn.dataset.text = text;
                ttsBtn.dataset.playing = 'false';
                actionsDiv.appendChild(ttsBtn);
            }
            
            // Pulsanti Feedback
            if (config.enableFeedback && messageId) {
                var feedbackDiv = document.createElement('span');
                feedbackDiv.className = 'scb-feedback';
                feedbackDiv.innerHTML = 
                    '<button class="scb-feedback-btn" data-feedback="1" data-message="' + messageId + '" title="Utile">👍</button>' +
                    '<button class="scb-feedback-btn" data-feedback="0" data-message="' + messageId + '" title="Non utile">👎</button>';
                actionsDiv.appendChild(feedbackDiv);
            }
            
            if (actionsDiv.children.length > 0) {
                div.appendChild(actionsDiv);
            }
            
            // Auto-play TTS se abilitato
            if (config.ttsAuto && config.enableTts) {
                setTimeout(function() {
                    speak(text);
                    if (ttsBtn) ttsBtn.dataset.playing = 'true';
                    updateTtsButtons();
                }, 300);
            }
        }
        
        container.appendChild(div);
        container.scrollTop = container.scrollHeight;
        
        return div;
    }
    
    function addMessage(text, type, isPrimary, messageId) {
        return addMessageToContainer(text, type, messages, isPrimary, messageId);
    }

    function linkify(text) {
        var urlRegex = /(https?:\/\/[^\s<]+)/g;
        return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(urlRegex, '<a href="$1" target="_blank" rel="noopener">$1</a>')
            .replace(/\n/g, '<br>');
    }

    function showSuggestionsInContainer(suggestionsContainer, messagesContainer, inputEl) {
        if (!config.suggestedQuestions || !config.suggestedQuestions.length) return;
        if (!suggestionsContainer) return;
        
        suggestionsContainer.innerHTML = '';
        suggestionsContainer.className = 'scb-suggestions-container';
        
        config.suggestedQuestions.forEach(function(q) {
            var btn = document.createElement('button');
            btn.className = 'scb-suggestion-btn';
            btn.textContent = q;
            btn.addEventListener('click', function() {
                sendMessage(q, messagesContainer, inputEl, suggestionsContainer);
            });
            suggestionsContainer.appendChild(btn);
        });
    }
    
    function showSuggestions() {
        showSuggestionsInContainer(suggestions, messages, input);
    }

    function showTyping(container) {
        var div = document.createElement('div');
        div.className = 'scb-message scb-message-bot scb-typing';
        div.id = 'scb-typing-indicator';
        div.innerHTML = '<span></span><span></span><span></span>';
        container.appendChild(div);
        container.scrollTop = container.scrollHeight;
    }

    function hideTyping(container) {
        var typing = container.querySelector('#scb-typing-indicator');
        if (typing) typing.remove();
    }

    function sendMessage(text, messagesContainer, inputEl, suggestionsContainer) {
        if (!text.trim()) return;
        
        // Ferma TTS quando si invia nuovo messaggio
        stopSpeaking();

        addMessageToContainer(text, 'user', messagesContainer, false, null);
        inputEl.value = '';
        inputEl.disabled = true;
        
        if (suggestionsContainer) {
            suggestionsContainer.innerHTML = '';
        }
        
        showTyping(messagesContainer);

        var formData = new FormData();
        formData.append('action', 'scb_chat');
        formData.append('nonce', config.nonce);
        formData.append('message', text);
        formData.append('session_id', sessionId);
        formData.append('page_url', config.currentUrl || window.location.href);

        fetch(config.ajaxUrl, {
            method: 'POST',
            body: formData
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(data) {
            hideTyping(messagesContainer);

            if (data.success) {
                lastMessageId = data.data.message_id;
                addMessageToContainer(data.data.reply, 'bot', messagesContainer, false, lastMessageId);
            } else {
                addMessageToContainer(data.data || 'Mi dispiace, si è verificato un errore. Riprova più tardi.', 'bot', messagesContainer, false, null);
                console.error('SCB Error:', data.data);
            }
            
            inputEl.disabled = false;
            inputEl.focus();
        })
        .catch(function(error) {
            hideTyping(messagesContainer);
            addMessageToContainer('Mi dispiace, si è verificato un errore di connessione.', 'bot', messagesContainer, false, null);
            console.error('SCB Error:', error);
            
            inputEl.disabled = false;
            inputEl.focus();
        });
    }

    // ==================== EVENT LISTENERS ====================
    
    document.addEventListener('click', function(e) {
        // TTS Button
        if (e.target.classList.contains('scb-tts-btn')) {
            var btn = e.target;
            var text = btn.dataset.text;
            
            if (isSpeaking && btn.dataset.playing === 'true') {
                stopSpeaking();
            } else {
                // Reset tutti i pulsanti
                document.querySelectorAll('.scb-tts-btn').forEach(function(b) {
                    b.dataset.playing = 'false';
                });
                btn.dataset.playing = 'true';
                speak(text);
            }
            return;
        }
        
        // Feedback Button
        if (e.target.classList.contains('scb-feedback-btn')) {
            var btn = e.target;
            var feedback = btn.dataset.feedback;
            var messageId = btn.dataset.message;
            
            var parent = btn.parentElement;
            parent.querySelectorAll('button').forEach(function(b) {
                b.disabled = true;
            });
            
            btn.classList.add('scb-feedback-selected');
            
            var formData = new FormData();
            formData.append('action', 'scb_feedback');
            formData.append('nonce', config.nonce);
            formData.append('message_id', messageId);
            formData.append('feedback', feedback);

            fetch(config.ajaxUrl, {
                method: 'POST',
                body: formData
            }).catch(function(error) {
                console.error('Feedback error:', error);
            });
        }
    });

})();
