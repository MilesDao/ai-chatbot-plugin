/**
 * Frontend Controller for AI Chatbot
 * Manages multiple chatbot instances, AJAX requests, scrolling, and markdown parsing.
 */

jQuery(document).ready(function($) {
    
    // Initialize every chatbot instance on the page
    $('.ai_chatbot-chatbot-container').each(function() {
        var container = $(this);
        var id = container.attr('id');
        var isInline = container.hasClass('ai_chatbot-layout-inline');
        
        var bubble = $('#' + id + '-trigger');
        var chatWindow = $('#' + id + '-window');
        var minimizeBtn = $('#' + id + '-minimize');
        
        // Views
        var viewAI = $('#' + id + '-view-ai');
        var viewHuman = $('#' + id + '-view-human');
        
        // AI elements
        var messagesViewport = $('#' + id + '-messages');
        var chatForm = $('#' + id + '-form');
        var inputField = $('#' + id + '-input');
        var submitBtn = $('#' + id + '-submit');
        var inputArea = $('#' + id + '-input-area');
        
        // Human elements
        var humanMessagesViewport = $('#' + id + '-human-messages');
        var humanForm = $('#' + id + '-human-form');
        var humanInput = $('#' + id + '-human-input');
        var humanSubmit = $('#' + id + '-human-submit');
        var btnBackAI = $('#' + id + '-back-ai');
        
        // Lead capture selectors
        var leadOverlay = $('#' + id + '-lead-overlay');
        var leadForm = $('#' + id + '-lead-capture-form');
        var leadSubmit = $('#' + id + '-lead-submit');
        var leadError = $('#' + id + '-lead-error');
        
        // Initial setup for Lead Capture Form
        var hasLeadSubmitted = false;
        var leadName = '';
        var requireLeadForm = true;
        
        // Modes & Polling
        var currentMode = 'bot';
        var humanPollingInterval = null;
        var loadedHistoryCount = 0;
        
        // Session / Conversation persistence
        var sessionId = '';
        try {
            sessionId = localStorage.getItem('ai_chatbot_session_id');
            if (!sessionId) {
                sessionId = 'sess_' + Date.now() + '_' + Math.random().toString(36).substring(2, 10);
                localStorage.setItem('ai_chatbot_session_id', sessionId);
            }
            hasLeadSubmitted = localStorage.getItem('ai_chatbot_lead_submitted') === 'true';
            leadName = localStorage.getItem('ai_chatbot_lead_name') || '';
        } catch(e) {
            console.warn("AI Chatbot: localStorage is blocked or unsupported in this browser environment.", e);
            sessionId = 'sess_' + Date.now() + '_' + Math.random().toString(36).substring(2, 10);
        }

        // Mode Switching
        function switchMode(mode) {
            currentMode = mode;
            var ajaxUrl = (typeof aiChatbotVars !== 'undefined' && aiChatbotVars.ajax_url) ? aiChatbotVars.ajax_url : '/wp-admin/admin-ajax.php';
            var nonceValue = (typeof aiChatbotVars !== 'undefined' && aiChatbotVars.nonce) ? aiChatbotVars.nonce : '';

            $.post(ajaxUrl, { action: 'ai_chatbot_switch_mode', nonce: nonceValue, session_id: sessionId, mode: mode });

            if (mode === 'human') {
                viewAI.hide().addClass('ai_chatbot-hidden');
                viewHuman.show().removeClass('ai_chatbot-hidden');
                scrollToBottom(false, 'human');
                if (!humanPollingInterval) {
                    humanPollingInterval = setInterval(loadHistory, 4000);
                }
            } else {
                viewHuman.hide().addClass('ai_chatbot-hidden');
                viewAI.show().removeClass('ai_chatbot-hidden');
                scrollToBottom(false, 'ai');
                if (humanPollingInterval) {
                    clearInterval(humanPollingInterval);
                    humanPollingInterval = null;
                }
            }
        }
        
        chatWindow.on('click', '.ai_chatbot-btn-takeover', function(e) {
            e.preventDefault();
            switchMode('human');
            
            // Hide the banner if they clicked it
            var takeoverBanner = $('#' + id + '-takeover-banner');
            if (takeoverBanner.length) {
                takeoverBanner.hide();
            }
        });
        
        btnBackAI.on('click', function(e) {
            e.preventDefault();
            switchMode('bot');
        });

        // Persist a message to the backend
        function saveMessage(role, content, chatType) {
            chatType = chatType || 'ai';
            var ajaxUrl = (typeof aiChatbotVars !== 'undefined' && aiChatbotVars.ajax_url) ? aiChatbotVars.ajax_url : '/wp-admin/admin-ajax.php';
            var nonceValue = (typeof aiChatbotVars !== 'undefined' && aiChatbotVars.nonce) ? aiChatbotVars.nonce : '';
            try {
                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ai_chatbot_save_message',
                        nonce: nonceValue,
                        session_id: sessionId,
                        role: role,
                        content: content,
                        lead_name: leadName,
                        chat_type: chatType
                    },
                    async: true
                });
            } catch(e) {
                console.warn("AI Chatbot: Failed to persist message.", e);
            }
        }

        // Load conversation history from backend
        function loadHistory() {
            var ajaxUrl = (typeof aiChatbotVars !== 'undefined' && aiChatbotVars.ajax_url) ? aiChatbotVars.ajax_url : '/wp-admin/admin-ajax.php';
            var nonceValue = (typeof aiChatbotVars !== 'undefined' && aiChatbotVars.nonce) ? aiChatbotVars.nonce : '';

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ai_chatbot_get_history',
                    nonce: nonceValue,
                    session_id: sessionId
                },
                success: function(response) {
                    if (typeof response === 'string') {
                        try { response = JSON.parse(response); } catch(e) {}
                    }
                    if (response && response.success && response.data && response.data.messages) {
                        var msgs = response.data.messages;
                        
                        if (msgs.length > loadedHistoryCount) {
                            if (loadedHistoryCount === 0 && msgs.length > 0) {
                                // Remove AI welcome placeholder on first load
                                messagesViewport.find('.ai_chatbot-msg-wrapper.bot:first').remove();
                            }
                            
                            var newMsgs = msgs.slice(loadedHistoryCount);
                            $.each(newMsgs, function(i, msg) {
                                var sender = msg.role === 'user' ? 'user' : 'bot';
                                var msgMode = msg.chat_type === 'human' ? 'human' : 'ai';
                                
                                var msgTime = '';
                                if (msg.created_at) {
                                    var parts = msg.created_at.split(' ');
                                    if (parts.length > 1) {
                                        var timeParts = parts[1].split(':');
                                        if (timeParts.length > 1) {
                                            msgTime = timeParts[0] + ':' + timeParts[1];
                                        }
                                    }
                                }
                                
                                if (sender === 'bot' && msgMode === 'ai') {
                                    var html = parseMarkdown(msg.content);
                                    appendMessage(html, sender, true, msgTime, msgMode);
                                } else {
                                    var html = parseMarkdown(msg.content);
                                    appendMessage(html, sender, true, msgTime, msgMode);
                                }
                            });
                            
                            loadedHistoryCount = msgs.length;
                            scrollToBottom(false, 'ai');
                            scrollToBottom(false, 'human');
                        }
                    }
                }
            });
        }

        try {
            if (typeof aiChatbotVars !== 'undefined' && aiChatbotVars.require_lead !== undefined) {
                requireLeadForm = aiChatbotVars.require_lead === '1';
            }
        } catch(e) {}

        if (requireLeadForm && !hasLeadSubmitted) {
            leadOverlay.removeClass('ai_chatbot-hidden').show();
            inputArea.addClass('ai_chatbot-hidden').hide();
            inputField.prop('disabled', true);
            submitBtn.prop('disabled', true);
        } else {
            leadOverlay.addClass('ai_chatbot-hidden').hide();
            inputArea.removeClass('ai_chatbot-hidden').show();
            inputField.prop('disabled', false);
            submitBtn.prop('disabled', false);
            
            if (leadName) {
                var nameParts = leadName.trim().split(/\s+/);
                var firstName = nameParts.length > 0 ? nameParts[nameParts.length - 1] : '';
                if (firstName) {
                    var welcomeGreeting = 'Trường Cao đẳng Kinh tế Công nghệ Hà Nội xin chào ' + firstName + '! Chúng tôi có thể giúp gì cho bạn hôm nay?';
                    messagesViewport.find('.ai_chatbot-msg-wrapper.bot:first .ai_chatbot-msg-bubble p').text(welcomeGreeting);
                }
            }
        }

        // Load history on page load
        if (sessionId) {
            setTimeout(function() { loadHistory(); }, 100);
        }

        // Lead Form Submit
        leadForm.on('submit', function(e) {
            e.preventDefault();
            
            var nameValue = $('#' + id + '-lead-name').val().trim();
            var emailValue = $('#' + id + '-lead-email').val().trim();
            var phoneValue = $('#' + id + '-lead-phone').val().trim();
            
            if (!nameValue || !emailValue || !phoneValue) {
                leadError.text('Vui lòng nhập đầy đủ thông tin bắt buộc.').show();
                return;
            }
            
            leadSubmit.prop('disabled', true).text('Đang xử lý...');
            leadError.hide();
            
            var ajaxUrl = (typeof aiChatbotVars !== 'undefined' && aiChatbotVars.ajax_url) ? aiChatbotVars.ajax_url : '/wp-admin/admin-ajax.php';
            var nonceValue = (typeof aiChatbotVars !== 'undefined' && aiChatbotVars.nonce) ? aiChatbotVars.nonce : '';
            
            try {
                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ai_chatbot_submit_lead',
                        nonce: nonceValue,
                        name: nameValue,
                        email: emailValue,
                        phone: phoneValue
                    },
                    success: function(response) {
                        if (typeof response === 'string') {
                            try { response = JSON.parse(response); } catch(e) {}
                        }
                        
                        if (response && response.success) {
                            try {
                                localStorage.setItem('ai_chatbot_lead_submitted', 'true');
                                if (nameValue) {
                                    localStorage.setItem('ai_chatbot_lead_name', nameValue);
                                    leadName = nameValue;
                                }
                            } catch(e) {}
                            
                            var firstName = 'bạn';
                            if (nameValue) {
                                var nameParts = nameValue.trim().split(/\s+/);
                                if (nameParts.length > 0) {
                                    firstName = nameParts[nameParts.length - 1];
                                }
                            }
                            
                            var welcomeGreeting = 'Trường Cao đẳng Kinh tế Công nghệ Hà Nội xin chào ' + firstName + '! Chúng tôi có thể giúp gì cho bạn hôm nay?';
                            messagesViewport.find('.ai_chatbot-msg-wrapper.bot:first .ai_chatbot-msg-bubble p').text(welcomeGreeting);
                            
                            setTimeout(function() {
                                saveMessage('bot', welcomeGreeting, 'ai');
                                // Only increment local history count if we append directly, but we let polling/reload handle it later
                            }, 200);

                            leadOverlay.addClass('ai_chatbot-hidden');
                            setTimeout(function() {
                                leadOverlay.hide();
                                inputArea.removeClass('ai_chatbot-hidden').hide().fadeIn(400, function() {
                                    inputField.prop('disabled', false).focus();
                                    submitBtn.prop('disabled', false);
                                });
                            }, 400);
                        } else {
                            var errMsg = 'Đăng ký thất bại. Vui lòng kiểm tra lại.';
                            if (response && response.data && response.data.message) {
                                errMsg = response.data.message;
                            }
                            leadError.text(errMsg).show();
                            leadSubmit.prop('disabled', false).text('Bắt đầu tư vấn');
                        }
                    },
                    error: function() {
                        leadError.text('Có lỗi kết nối hệ thống. Vui lòng thử lại.').show();
                        leadSubmit.prop('disabled', false).text('Bắt đầu tư vấn');
                    }
                });
            } catch (err) {
                leadError.text('Đã xảy ra lỗi ngoài ý muốn. Vui lòng tải lại trang.').show();
                leadSubmit.prop('disabled', false).text('Bắt đầu tư vấn');
            }
        });
        
        if (!isInline) {
            bubble.on('click', function(e) {
                e.stopPropagation();
                if (chatWindow.hasClass('ai_chatbot-state-closed')) {
                    openChat();
                } else {
                    closeChat();
                }
            });
            
            minimizeBtn.on('click', function(e) {
                e.stopPropagation();
                closeChat();
            });
            
            $(document).on('keyup', function(e) {
                if (e.key === "Escape" && !chatWindow.hasClass('ai_chatbot-state-closed')) {
                    closeChat();
                }
            });

            chatWindow.on('click', function(e) { e.stopPropagation(); });
        }
        
        function openChat() {
            chatWindow.removeClass('ai_chatbot-state-closed');
            bubble.find('.icon-open').hide();
            bubble.find('.icon-close').show();
            bubble.find('.ai_chatbot-pulse-dot').fadeOut(300);
            
            setTimeout(function() {
                if (currentMode === 'ai') inputField.focus();
                else humanInput.focus();
            }, 300);
            scrollToBottom(true, currentMode);
        }
        
        function closeChat() {
            chatWindow.addClass('ai_chatbot-state-closed');
            bubble.find('.icon-close').hide();
            bubble.find('.icon-open').show();
        }
        
        // AI Chat Form Submit
        chatForm.on('submit', function(e) {
            e.preventDefault();
            var userText = inputField.val().trim();
            if (!userText) return;
            
            inputField.val('');
            inputField.prop('disabled', true);
            submitBtn.prop('disabled', true);
            
            appendMessage(userText, 'user', false, '', 'ai');
            scrollToBottom(true, 'ai');
            
            saveMessage('user', userText, 'ai');
            loadedHistoryCount++; // increment to account for user msg just saved
            
            appendTypingIndicator();
            scrollToBottom(true, 'ai');
            
            var ajaxUrl = (typeof aiChatbotVars !== 'undefined' && aiChatbotVars.ajax_url) ? aiChatbotVars.ajax_url : '/wp-admin/admin-ajax.php';
            var nonceValue = (typeof aiChatbotVars !== 'undefined' && aiChatbotVars.nonce) ? aiChatbotVars.nonce : '';

            try {
                var formData = new FormData();
                formData.append('action', 'ai_chatbot_chat_query');
                formData.append('message', userText);
                formData.append('session_id', sessionId);
                formData.append('nonce', nonceValue);

                fetch(ajaxUrl, {
                    method: 'POST',
                    body: formData
                }).then(async response => {
                    if (!response.ok) throw new Error('Network response was not ok');
                    
                    removeTypingIndicator();
                    
                    var reader = response.body.getReader();
                    var decoder = new TextDecoder("utf-8");
                    var fullAnswer = "";
                    var currentBoxHTML = "";
                    var currentMsgBubble = null;
                    
                    while (true) {
                        const { done, value } = await reader.read();
                        if (done) break;
                        
                        var chunk = decoder.decode(value, { stream: true });
                        try {
                            var parsedJson = JSON.parse(chunk.trim());
                            if (parsedJson.success === false && parsedJson.data && parsedJson.data.message) {
                                appendMessage('<p style="color: #ef4444; font-weight: 500;">Lỗi: ' + parsedJson.data.message + '</p>', 'bot', true, '', 'ai');
                                fullAnswer = "[Lỗi hệ thống]";
                                break;
                            } else if (parsedJson.error && parsedJson.error.message) {
                                appendMessage('<p style="color: #ef4444; font-weight: 500;">Lỗi AI Model: ' + parsedJson.error.message + '</p>', 'bot', true, '', 'ai');
                                fullAnswer = "[Lỗi AI Model]";
                                break;
                            }
                        } catch (e) {}
                        
                        var lines = chunk.split('\n');
                        for (var i = 0; i < lines.length; i++) {
                            var line = lines[i].trim();
                            if (line.startsWith('data: ')) {
                                var dataStr = line.substring(6).trim();
                                if (dataStr === '[DONE]') continue;
                                if (dataStr.startsWith('[ERROR]')) {
                                    if (!currentMsgBubble) {
                                        appendMessage('<p style="color: #ef4444; font-weight: 500;">' + dataStr + '</p>', 'bot', true, '', 'ai');
                                        currentMsgBubble = messagesViewport.find('.ai_chatbot-msg-wrapper.bot:not(.ai_chatbot-typing-loader):last .ai_chatbot-msg-bubble');
                                    }
                                    continue;
                                }
                                
                                try {
                                    var dataObj = JSON.parse(dataStr);
                                    if (dataObj.choices && dataObj.choices[0] && dataObj.choices[0].delta && dataObj.choices[0].delta.content) {
                                        var deltaText = dataObj.choices[0].delta.content;
                                        fullAnswer += deltaText;
                                        
                                        currentBoxHTML = parseMarkdown(fullAnswer);
                                        
                                        if (!currentMsgBubble) {
                                            appendMessage('', 'bot', false, '', 'ai');
                                            currentMsgBubble = messagesViewport.find('.ai_chatbot-msg-wrapper.bot:not(.ai_chatbot-typing-loader):last .ai_chatbot-msg-bubble');
                                        }
                                        
                                        currentMsgBubble.html(currentBoxHTML);
                                        scrollToBottom(false, 'ai');
                                    }
                                } catch (err) {}
                            }
                        }
                    }
                    
                    inputField.prop('disabled', false).focus();
                    submitBtn.prop('disabled', false);
                    saveMessage('bot', fullAnswer, 'ai');
                    loadedHistoryCount++;
                    
                    // Show fixed suggestion banner
                    var takeoverBanner = $('#' + id + '-takeover-banner');
                    if (takeoverBanner.length) {
                        takeoverBanner.fadeIn(300);
                    }
                    
                }).catch(error => {
                    removeTypingIndicator();
                    inputField.prop('disabled', false).focus();
                    submitBtn.prop('disabled', false);
                    appendMessage('<p style="color: #ef4444; font-weight: 500;">Có lỗi kết nối hệ thống. Vui lòng thử lại.</p>', 'bot', true, '', 'ai');
                    scrollToBottom(true, 'ai');
                });
            } catch (err) {
                removeTypingIndicator();
                inputField.prop('disabled', false).focus();
                submitBtn.prop('disabled', false);
                appendMessage('<p style="color: #ef4444; font-weight: 500;">Đã xảy ra lỗi ngoài ý muốn. Vui lòng tải lại trang.</p>', 'bot', true, '', 'ai');
            }
        });

        // Human Chat Form Submit
        humanForm.on('submit', function(e) {
            e.preventDefault();
            var userText = humanInput.val().trim();
            if (!userText) return;

            humanInput.val('');
            appendMessage(userText, 'user', false, '', 'human');
            scrollToBottom(true, 'human');
            
            saveMessage('user', userText, 'human');
            loadedHistoryCount++;
        });
        
        // Helper to append speech bubble
        function appendMessage(content, sender, isHTML, time, mode) {
            mode = mode || 'ai';
            var timeString = time || getCurrentTime();
            var bubbleContent = isHTML ? content : escapeHtml(content);
            
            var isSuggestion = content.indexOf('ai_chatbot-takeover-suggestion') !== -1;
            var innerHtml = '';
            if (isSuggestion) {
                innerHtml = content;
            } else {
                var bubbleHtml = '<div class="ai_chatbot-msg-bubble">' + (isHTML ? bubbleContent : '<p>' + bubbleContent + '</p>') + '</div>';
                if (sender === 'bot' && mode === 'ai') {
                    var actionsHtml = 
                        '<div class="ai_chatbot-bot-actions">' +
                            '<button type="button" class="ai_chatbot-action-btn" title="Copy"><svg viewBox="0 0 24 24"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect></svg></button>' +
                            '<button type="button" class="ai_chatbot-action-btn" title="Thích"><svg viewBox="0 0 24 24"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"></path></svg></button>' +
                            '<button type="button" class="ai_chatbot-action-btn" title="Không thích"><svg viewBox="0 0 24 24"><path d="M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3zm7-13h2.67A2.31 2.31 0 0 1 22 4v7a2.31 2.31 0 0 1-2.33 2H17"></path></svg></button>' +
                            '<div class="ai_chatbot-action-divider"></div>' +
                            '<span>Just now</span>' +
                        '</div>';
                    innerHtml = bubbleHtml + actionsHtml;
                } else {
                    innerHtml = bubbleHtml + '<span class="ai_chatbot-msg-time">' + timeString + '</span>';
                }
            }
            
            var msgHtml = '<div class="ai_chatbot-msg-wrapper ' + sender + '">' + innerHtml + '</div>';
                
            if (mode === 'human') {
                humanMessagesViewport.append(msgHtml);
            } else {
                messagesViewport.append(msgHtml);
            }
        }
        
        // Typing loader helpers
        function appendTypingIndicator() {
            var loaderHtml = 
                '<div class="ai_chatbot-msg-wrapper bot ai_chatbot-typing-loader">' +
                    '<div class="ai_chatbot-msg-bubble">' +
                        '<div class="ai_chatbot-typing-indicator">' +
                            '<span class="ai_chatbot-typing-dot"></span>' +
                            '<span class="ai_chatbot-typing-dot"></span>' +
                            '<span class="ai_chatbot-typing-dot"></span>' +
                        '</div>' +
                    '</div>' +
                '</div>';
            messagesViewport.append(loaderHtml);
        }
        
        function removeTypingIndicator() {
            messagesViewport.find('.ai_chatbot-typing-loader').remove();
        }
        
        // Scroll conversation to bottom
        function scrollToBottom(animate, mode) {
            mode = mode || 'ai';
            var vp = mode === 'human' ? humanMessagesViewport : messagesViewport;
            var scrollHeight = vp[0].scrollHeight;
            if (animate) {
                vp.animate({ scrollTop: scrollHeight }, 300);
            } else {
                vp.scrollTop(scrollHeight);
            }
        }
        
        function getCurrentTime() {
            var now = new Date();
            var hours = now.getHours().toString().padStart(2, '0');
            var minutes = now.getMinutes().toString().padStart(2, '0');
            return hours + ':' + minutes;
        }

        function escapeHtml(text) {
            if (!text || typeof text !== 'string') return '';
            return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }

        function parseMarkdown(markdownText) {
            if (!markdownText || typeof markdownText !== 'string') return '';
            var html = escapeHtml(markdownText);
            html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
            html = html.replace(/\*(.*?)\*/g, '<em>$1</em>');
            
            var lines = html.split('\n');
            var listOpen = false;
            var processedLines = [];
            
            for (var i = 0; i < lines.length; i++) {
                var line = lines[i].trim();
                if (line.startsWith('- ') || line.startsWith('* ')) {
                    if (!listOpen) {
                        processedLines.push('<ul>');
                        listOpen = true;
                    }
                    processedLines.push('<li>' + line.substring(2) + '</li>');
                } else {
                    if (listOpen) {
                        processedLines.push('</ul>');
                        listOpen = false;
                    }
                    processedLines.push(lines[i]);
                }
            }
            if (listOpen) processedLines.push('</ul>');
            
            html = processedLines.join('\n');
            var paragraphs = html.split(/\n\n+/);
            var finalHTML = [];
            
            for (var p = 0; p < paragraphs.length; p++) {
                var pText = paragraphs[p].trim();
                if (!pText) continue;
                if (pText.startsWith('<ul>') || pText.startsWith('<ol>') || pText.startsWith('<li>')) {
                    finalHTML.push(pText);
                } else {
                    finalHTML.push('<p>' + pText.replace(/\n/g, '<br>') + '</p>');
                }
            }
            return finalHTML.join('');
        }
    });
});
