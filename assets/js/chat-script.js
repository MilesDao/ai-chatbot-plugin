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
        var messagesViewport = $('#' + id + '-messages');
        var chatForm = $('#' + id + '-form');
        var inputField = $('#' + id + '-input');
        var submitBtn = $('#' + id + '-submit');
        var inputArea = container.find('.ai_chatbot-chat-input-area');
        
        // Lead capture selectors
        var leadOverlay = $('#' + id + '-lead-overlay');
        var leadForm = $('#' + id + '-lead-capture-form');
        var leadSubmit = $('#' + id + '-lead-submit');
        var leadError = $('#' + id + '-lead-error');
        
        // Initial setup for Lead Capture Form
        var hasLeadSubmitted = false;
        var leadName = '';
        var requireLeadForm = true;
        
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

        // Persist a message to the backend
        function saveMessage(role, content) {
            var ajaxUrl = (typeof aiChatbotVars !== 'undefined' && aiChatbotVars.ajax_url) 
                ? aiChatbotVars.ajax_url 
                : (window.ajaxurl || '/wp-admin/admin-ajax.php');
            var nonceValue = (typeof aiChatbotVars !== 'undefined' && aiChatbotVars.nonce) 
                ? aiChatbotVars.nonce 
                : '';
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
                        lead_name: leadName
                    },
                    async: true
                });
            } catch(e) {
                console.warn("AI Chatbot: Failed to persist message.", e);
            }
        }

        // Load conversation history from backend
        function loadHistory() {
            var ajaxUrl = (typeof aiChatbotVars !== 'undefined' && aiChatbotVars.ajax_url) 
                ? aiChatbotVars.ajax_url 
                : (window.ajaxurl || '/wp-admin/admin-ajax.php');
            var nonceValue = (typeof aiChatbotVars !== 'undefined' && aiChatbotVars.nonce) 
                ? aiChatbotVars.nonce 
                : '';

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
                        // Remove welcome message placeholder if we have history
                        if (msgs.length > 0) {
                            messagesViewport.find('.ai_chatbot-msg-wrapper.bot:first').remove();
                        }
                        $.each(msgs, function(i, msg) {
                            var sender = msg.role === 'user' ? 'user' : 'bot';
                            
                            // Format database timestamp YYYY-MM-DD HH:MM:SS to HH:MM
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
                            
                            if (sender === 'bot') {
                                var splitParts = msg.content.split(/<tách box chat>/i);
                                $.each(splitParts, function(j, part) {
                                    var partText = part.trim();
                                    if (partText) {
                                        var html = parseMarkdown(partText);
                                        appendMessage(html, sender, true, msgTime);
                                    }
                                });
                            } else {
                                var html = parseMarkdown(msg.content);
                                appendMessage(html, sender, true, msgTime);
                            }
                        });
                        scrollToBottom(false);
                    }
                }
            });
        }

        try {
            hasLeadSubmitted = localStorage.getItem('ai_chatbot_lead_submitted') === 'true';
            leadName = localStorage.getItem('ai_chatbot_lead_name') || '';
        } catch(e) {
            console.warn("AI Chatbot: localStorage is blocked or unsupported in this browser environment.", e);
        }

        try {
            if (typeof aiChatbotVars !== 'undefined' && aiChatbotVars.require_lead !== undefined) {
                requireLeadForm = aiChatbotVars.require_lead === '1';
            }
        } catch(e) {
            console.warn("AI Chatbot: aiChatbotVars is not enqueued.", e);
        }

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
            
            // Personalize initial greeting on page load if user name exists in storage
            if (leadName) {
                var nameParts = leadName.trim().split(/\s+/);
                var firstName = nameParts.length > 0 ? nameParts[nameParts.length - 1] : '';
                if (firstName) {
                    var welcomeGreeting = 'Trường Cao đẳng Kinh tế Công nghệ Hà Nội xin chào ' + firstName + '! Chúng tôi có thể giúp gì cho bạn hôm nay?';
                    messagesViewport.find('.ai_chatbot-msg-wrapper.bot:first .ai_chatbot-msg-bubble p').text(welcomeGreeting);
                }
            }
        }

        // Load history on page load (after a short delay for inline layouts)
        if (sessionId) {
            setTimeout(function() { loadHistory(); }, 100);
        }

        // Bind Lead Form Submit
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
            
            // Safe fallback for AJAX URL and Nonce if variables are missing
            var ajaxUrl = (typeof aiChatbotVars !== 'undefined' && aiChatbotVars.ajax_url) 
                ? aiChatbotVars.ajax_url 
                : (window.ajaxurl || '/wp-admin/admin-ajax.php');
                
            var nonceValue = (typeof aiChatbotVars !== 'undefined' && aiChatbotVars.nonce) 
                ? aiChatbotVars.nonce 
                : '';
            
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
                        // Safe JSON parse fallback if response is returned as string
                        if (typeof response === 'string') {
                            try {
                                response = JSON.parse(response);
                            } catch(e) {
                                console.warn("Failed to parse lead submit response as JSON:", e);
                            }
                        }
                        
                        if (response && response.success) {
                            try {
                                localStorage.setItem('ai_chatbot_lead_submitted', 'true');
                                if (nameValue) {
                                    localStorage.setItem('ai_chatbot_lead_name', nameValue);
                                    leadName = nameValue;
                                }
                            } catch(e) {
                                console.warn("AI Chatbot: Failed to save submission in localStorage.", e);
                            }
                            
                            // Extract Vietnamese first name (last word of the full name)
                            var firstName = 'bạn';
                            if (nameValue) {
                                var nameParts = nameValue.trim().split(/\s+/);
                                if (nameParts.length > 0) {
                                    firstName = nameParts[nameParts.length - 1];
                                }
                            }
                            
                            // Dynamic personalized initial greeting bubble update
                            var welcomeGreeting = 'Trường Cao đẳng Kinh tế Công nghệ Hà Nội xin chào ' + firstName + '! Chúng tôi có thể giúp gì cho bạn hôm nay?';
                            messagesViewport.find('.ai_chatbot-msg-wrapper.bot:first .ai_chatbot-msg-bubble p').text(welcomeGreeting);
                            
                            // Persist the bot welcome message
                            setTimeout(function() {
                                saveMessage('bot', welcomeGreeting);
                            }, 200);

                            // Elegant fade-out via class transition to avoid click-blocking
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
                    error: function(xhr, status, error) {
                        console.error("AI Chatbot AJAX error:", status, error, xhr.responseText);
                        leadError.text('Có lỗi kết nối hệ thống. Vui lòng thử lại.').show();
                        leadSubmit.prop('disabled', false).text('Bắt đầu tư vấn');
                    }
                });
            } catch (err) {
                console.error("Lead form submit crash:", err);
                leadError.text('Đã xảy ra lỗi ngoài ý muốn. Vui lòng tải lại trang.').show();
                leadSubmit.prop('disabled', false).text('Bắt đầu tư vấn');
            }
        });
        
        // 1. Floating trigger toggles (Only for non-inline layouts)
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
            
            // Close floating widget on escape key
            $(document).on('keyup', function(e) {
                if (e.key === "Escape" && !chatWindow.hasClass('ai_chatbot-state-closed')) {
                    closeChat();
                }
            });

            // Prevent clicks inside window from bubbling and closing it
            chatWindow.on('click', function(e) {
                e.stopPropagation();
            });
        }
        
        function openChat() {
            chatWindow.removeClass('ai_chatbot-state-closed');
            bubble.find('.icon-open').hide();
            bubble.find('.icon-close').show();
            bubble.find('.ai_chatbot-pulse-dot').fadeOut(300); // hide notification dot once clicked
            
            // Focus input field on slide up completion
            setTimeout(function() {
                inputField.focus();
            }, 300);
            
            scrollToBottom(true);
        }
        
        function closeChat() {
            chatWindow.addClass('ai_chatbot-state-closed');
            bubble.find('.icon-close').hide();
            bubble.find('.icon-open').show();
        }
        
        // 2. Message submission handler
        chatForm.on('submit', function(e) {
            e.preventDefault();
            
            var userText = inputField.val().trim();
            if (!userText) {
                return;
            }
            
            // Disable input and clear
            inputField.val('');
            inputField.prop('disabled', true);
            submitBtn.prop('disabled', true);
            
            // Append User Message to Viewport
            appendMessage(userText, 'user');
            scrollToBottom(true);
            
            // Persist user message
            saveMessage('user', userText);
            
            // Append Bot Typing Indicator
            appendTypingIndicator();
            scrollToBottom(true);
            
            // Safe fallback for AJAX URL and Nonce if variables are missing
            var ajaxUrl = (typeof aiChatbotVars !== 'undefined' && aiChatbotVars.ajax_url) 
                ? aiChatbotVars.ajax_url 
                : (window.ajaxurl || '/wp-admin/admin-ajax.php');
                
            var nonceValue = (typeof aiChatbotVars !== 'undefined' && aiChatbotVars.nonce) 
                ? aiChatbotVars.nonce 
                : '';

            try {
                // Prepare form data for fetch
                var formData = new FormData();
                formData.append('action', 'ai_chatbot_chat_query');
                formData.append('message', userText);
                formData.append('session_id', sessionId);
                formData.append('nonce', nonceValue);

                // Send request using fetch to read stream
                fetch(ajaxUrl, {
                    method: 'POST',
                    body: formData
                }).then(async response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    
                    removeTypingIndicator();
                    
                    var reader = response.body.getReader();
                    var decoder = new TextDecoder("utf-8");
                    var fullAnswer = "";
                    var currentBoxHTML = "";
                    
                    // We need to keep track of the current message bubble element being written to
                    var currentMsgBubble = null;
                    
                    while (true) {
                        const { done, value } = await reader.read();
                        if (done) break;
                        
                        var chunk = decoder.decode(value, { stream: true });
                        
                        // Try to parse the chunk as a plain JSON error object (in case backend or API fails without streaming)
                        try {
                            var parsedJson = JSON.parse(chunk.trim());
                            if (parsedJson.success === false && parsedJson.data && parsedJson.data.message) {
                                appendMessage('<p style="color: #ef4444; font-weight: 500;">Lỗi: ' + parsedJson.data.message + '</p>', 'bot', true);
                                fullAnswer = "[Lỗi hệ thống]";
                                break;
                            } else if (parsedJson.error && parsedJson.error.message) {
                                appendMessage('<p style="color: #ef4444; font-weight: 500;">Lỗi AI Model: ' + parsedJson.error.message + '</p>', 'bot', true);
                                fullAnswer = "[Lỗi AI Model]";
                                break;
                            }
                        } catch (e) {
                            // Not a plain JSON error object, proceed with SSE parsing
                        }
                        
                        var lines = chunk.split('\n');
                        
                        for (var i = 0; i < lines.length; i++) {
                            var line = lines[i].trim();
                            if (line.startsWith('data: ')) {
                                var dataStr = line.substring(6).trim();
                                if (dataStr === '[DONE]') {
                                    continue;
                                }
                                if (dataStr.startsWith('[ERROR]')) {
                                    if (!currentMsgBubble) {
                                        appendMessage('<p style="color: #ef4444; font-weight: 500;">' + dataStr + '</p>', 'bot', true);
                                        currentMsgBubble = messagesViewport.find('.ai_chatbot-msg-wrapper.bot:last .ai_chatbot-msg-bubble');
                                    }
                                    continue;
                                }
                                
                                try {
                                    var dataObj = JSON.parse(dataStr);
                                    if (dataObj.choices && dataObj.choices[0] && dataObj.choices[0].delta && dataObj.choices[0].delta.content) {
                                        var deltaText = dataObj.choices[0].delta.content;
                                        fullAnswer += deltaText;
                                        
                                        // Handle <tách box chat> dynamically
                                        if (fullAnswer.toLowerCase().includes('<tách box chat>')) {
                                            var parts = fullAnswer.split(/<tách box chat>/i);
                                            // The last part is the currently growing one
                                            var latestPart = parts[parts.length - 1];
                                            
                                            // If we just detected a split, create a new bubble for the new part
                                            if (parts.length > 1 && deltaText.toLowerCase().includes('chat>')) {
                                                currentMsgBubble = null;
                                            }
                                            currentBoxHTML = parseMarkdown(latestPart);
                                        } else {
                                            currentBoxHTML = parseMarkdown(fullAnswer);
                                        }
                                        
                                        if (!currentMsgBubble) {
                                            // Create a new empty bubble and save its reference
                                            appendMessage('', 'bot', false); // Note: false means no animation here to avoid jumps
                                            currentMsgBubble = messagesViewport.find('.ai_chatbot-msg-wrapper.bot:last .ai_chatbot-msg-bubble');
                                        }
                                        
                                        currentMsgBubble.html(currentBoxHTML);
                                        scrollToBottom(false);
                                    }
                                } catch (err) {
                                    console.warn("Parse error for chunk:", dataStr, err);
                                }
                            }
                        }
                    }
                    
                    // Finalize
                    inputField.prop('disabled', false).focus();
                    submitBtn.prop('disabled', false);
                    saveMessage('bot', fullAnswer);
                    scrollToBottom(true);
                    
                }).catch(error => {
                    console.error("AI Chatbot Fetch error:", error);
                    removeTypingIndicator();
                    inputField.prop('disabled', false).focus();
                    submitBtn.prop('disabled', false);
                    appendMessage('<p style="color: #ef4444; font-weight: 500;">Có lỗi kết nối hệ thống. Vui lòng thử lại.</p>', 'bot', true);
                    scrollToBottom(true);
                });
            } catch (err) {
                console.error("Chat form submit crash:", err);
                removeTypingIndicator();
                inputField.prop('disabled', false).focus();
                submitBtn.prop('disabled', false);
                appendMessage('<p style="color: #ef4444; font-weight: 500;">Đã xảy ra lỗi ngoài ý muốn. Vui lòng tải lại trang.</p>', 'bot', true);
            }
        });
        
        // 3. Helper to append speech bubble
        function appendMessage(content, sender, isHTML, time) {
            var timeString = time || getCurrentTime();
            var bubbleContent = isHTML ? content : escapeHtml(content);
            
            var msgHtml = 
                '<div class="ai_chatbot-msg-wrapper ' + sender + '">' +
                    '<div class="ai_chatbot-msg-bubble">' +
                        (isHTML ? bubbleContent : '<p>' + bubbleContent + '</p>') +
                    '</div>' +
                    '<span class="ai_chatbot-msg-time">' + timeString + '</span>' +
                '</div>';
                
            messagesViewport.append(msgHtml);
        }
        
        // 4. Typing loader helpers
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
        
        // 5. Scroll conversation to bottom
        function scrollToBottom(animate) {
            var scrollHeight = messagesViewport[0].scrollHeight;
            if (animate) {
                messagesViewport.animate({ scrollTop: scrollHeight }, 300);
            } else {
                messagesViewport.scrollTop(scrollHeight);
            }
        }
        
        // 6. Time retriever
        function getCurrentTime() {
            var now = new Date();
            var hours = now.getHours().toString().padStart(2, '0');
            var minutes = now.getMinutes().toString().padStart(2, '0');
            return hours + ':' + minutes;
        }

        // 7. Security: Escape raw strings to prevent XSS
        function escapeHtml(text) {
            if (!text || typeof text !== 'string') {
                return '';
            }
            return text
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        // 8. Custom lightweight parser for Gemini markdown formatting
        function parseMarkdown(markdownText) {
            if (!markdownText || typeof markdownText !== 'string') {
                return '';
            }
            // First escape html to keep things secure
            var html = escapeHtml(markdownText);
            
            // Bold formatting (**word**)
            html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
            
            // Single asterisk/underscore italics (*word*)
            html = html.replace(/\*(.*?)\*/g, '<em>$1</em>');
            
            // Split lines to detect list structures
            var lines = html.split('\n');
            var listOpen = false;
            var processedLines = [];
            
            for (var i = 0; i < lines.length; i++) {
                var line = lines[i].trim();
                
                // Matches bullet lines: starting with - or * followed by space
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
            
            if (listOpen) {
                processedLines.push('</ul>');
            }
            
            html = processedLines.join('\n');
            
            // Group paragraphs using double newline separations
            var paragraphs = html.split(/\n\n+/);
            var finalHTML = [];
            
            for (var p = 0; p < paragraphs.length; p++) {
                var pText = paragraphs[p].trim();
                if (!pText) continue;
                
                // If it is a block container tag, do not wrap in <p>
                if (pText.startsWith('<ul>') || pText.startsWith('<ol>') || pText.startsWith('<li>')) {
                    finalHTML.push(pText);
                } else {
                    // Convert remaining single newlines to <br>
                    finalHTML.push('<p>' + pText.replace(/\n/g, '<br>') + '</p>');
                }
            }
            
            return finalHTML.join('');
        }
    });
});
