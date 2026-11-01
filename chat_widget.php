<?php
/**
 * Nexora AI Assistant - Floating Chatbot Widget
 * Included in footer.php across all pages.
 */
?>
<!-- Nexora AI Chat Widget Stylesheet -->
<link rel="stylesheet" href="css/chat.css">

<!-- Floating Chat Toggle Button -->
<button type="button" class="nexora-chat-toggle" id="nexoraChatToggle" aria-label="Open Nexora AI Shopping Assistant">
    <span class="icon-chat">💬</span>
    <span class="icon-close">✕</span>
    <span class="chat-toggle-badge" id="chatUnreadBadge">1</span>
</button>

<!-- Floating Chat Window Modal -->
<div class="nexora-chat-window" id="nexoraChatWindow" role="dialog" aria-modal="true" aria-label="Nexora AI Assistant">
    <!-- Header -->
    <div class="chat-header">
        <div class="chat-header-profile">
            <div class="chat-avatar-wrapper">
                <span>🤖</span>
                <span class="online-dot" title="AI Online"></span>
            </div>
            <div class="chat-header-info">
                <h3>Nexora AI Assistant</h3>
                <span class="chat-header-subtitle">Shopping Advisor & Orders</span>
            </div>
        </div>
        <div class="chat-header-actions">
            <button type="button" class="chat-btn-icon" id="chatBtnReset" title="Reset conversation" aria-label="Reset conversation">🔄</button>
            <button type="button" class="chat-btn-icon" id="chatBtnMinimize" title="Minimize chat" aria-label="Minimize chat">─</button>
            <button type="button" class="chat-btn-icon" id="chatBtnClose" title="Close chat" aria-label="Close chat">✕</button>
        </div>
    </div>

    <!-- Messages Container -->
    <div class="chat-messages" id="chatMessages">
        <!-- Initial Welcome Message -->
        <div class="chat-msg-row bot">
            <div class="chat-msg-avatar">🤖</div>
            <div class="chat-bubble">
                Hello! I'm your <strong>Nexora AI Assistant</strong>. I can help you choose products, track shipments, check PayPal transactions, or find gifts.
                <div class="chat-quick-chips" style="margin-top:0.6rem;">
                    <button type="button" class="quick-chip" onclick="window.nexoraChat.sendQuick('Find a gift for my friend')">🎁 Find a gift</button>
                    <button type="button" class="quick-chip" onclick="window.nexoraChat.sendQuick('Track my order TRK-FEDEX-882910')">🚚 Track order</button>
                    <button type="button" class="quick-chip" onclick="window.nexoraChat.sendQuick('Suggest a high-performance laptop')">💻 Best laptop</button>
                    <button type="button" class="quick-chip" onclick="window.nexoraChat.sendQuick('How does PayPal checkout work?')">💳 PayPal info</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Input Footer -->
    <div class="chat-footer">
        <form id="chatForm" class="chat-input-form" onsubmit="event.preventDefault(); window.nexoraChat.submitInput();">
            <input type="text" id="chatInput" class="chat-input" placeholder="Ask about products, orders, gifts..." autocomplete="off" maxlength="500">
            <button type="submit" id="chatBtnSend" class="chat-btn-send" title="Send message">➤</button>
        </form>
        <div class="chat-disclaimer">Nexora AI • Powered by OpenRouter & PostgreSQL</div>
    </div>
</div>

<!-- Nexora AI Chat JavaScript (Vanilla JS, Zero Dependencies) -->
<script>
(function() {
    const STORAGE_KEY = 'nexora_chat_history_v2';
    const toggleBtn = document.getElementById('nexoraChatToggle');
    const chatWindow = document.getElementById('nexoraChatWindow');
    const closeBtn = document.getElementById('chatBtnClose');
    const minimizeBtn = document.getElementById('chatBtnMinimize');
    const resetBtn = document.getElementById('chatBtnReset');
    const messagesContainer = document.getElementById('chatMessages');
    const chatInput = document.getElementById('chatInput');
    const sendBtn = document.getElementById('chatBtnSend');
    const unreadBadge = document.getElementById('chatUnreadBadge');

    let history = [];
    let isOpen = false;

    // Load conversation from sessionStorage
    function loadHistory() {
        try {
            const saved = sessionStorage.getItem(STORAGE_KEY);
            if (saved) {
                history = JSON.parse(saved);
                renderHistory();
            }
        } catch (e) {
            history = [];
        }
    }

    // Save conversation to sessionStorage
    function saveHistory() {
        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(history));
        } catch (e) {}
    }

    // Basic Markdown Parser (converts **bold**, `code`, lists, and newlines safely)
    function formatMarkdown(text) {
        if (!text) return '';
        let escaped = text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');

        // Bold: **text**
        escaped = escaped.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        // Inline code: `text`
        escaped = escaped.replace(/`([^`]+)`/g, '<code>$1</code>');
        // Unordered lists: lines starting with • or -
        escaped = escaped.replace(/^[•\-]\s+(.*)$/gm, '<li>$1</li>');
        escaped = escaped.replace(/(<li>.*<\/li>)/s, '<ul>$1</ul>');
        // Numbered lists: lines starting with 1. 2.
        escaped = escaped.replace(/^(\d+)\.\s+(.*)$/gm, '<li>$2</li>');
        // Newlines
        escaped = escaped.replace(/\n{2,}/g, '<br><br>').replace(/\n/g, '<br>');
        return escaped;
    }

    // Render historical messages
    function renderHistory() {
        if (!history || history.length === 0) return;
        messagesContainer.innerHTML = '';
        history.forEach(msg => {
            appendMessageBubble(msg.role, msg.content, msg.products || [], false);
        });
        scrollToBottom();
    }

    // Append single bubble to container
    function appendMessageBubble(role, content, products = [], save = true) {
        const row = document.createElement('div');
        row.className = 'chat-msg-row ' + (role === 'user' ? 'user' : 'bot');
        if (products && products.length > 0) {
            row.classList.add('has-products');
        }

        if (role !== 'user') {
            const avatar = document.createElement('div');
            avatar.className = 'chat-msg-avatar';
            avatar.textContent = '🤖';
            row.appendChild(avatar);
        }

        const bubble = document.createElement('div');
        bubble.className = 'chat-bubble';
        bubble.innerHTML = formatMarkdown(content);

        // Render product cards if available
        if (products && products.length > 0) {
            const prodWrap = document.createElement('div');
            prodWrap.className = 'chat-products-container';

            products.forEach(p => {
                const card = document.createElement('div');
                card.className = 'chat-product-card';
                card.innerHTML = `
                    <img src="${p.image || 'images/default.jpg'}" alt="${p.name}" class="chat-prod-thumb" onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=120&auto=format&fit=crop&q=80';">
                    <div class="chat-prod-details">
                        <div class="chat-prod-title" title="${p.name}">${p.name}</div>
                        <div class="chat-prod-meta">
                            <span class="chat-prod-price">$${parseFloat(p.price).toFixed(2)}</span>
                            <span class="chat-prod-rating">★ ${parseFloat(p.rating || 4.5).toFixed(1)}</span>
                        </div>
                    </div>
                    <button type="button" class="chat-btn-add" data-id="${p.id}" onclick="window.nexoraChat.addProductToCart(${p.id}, this)">
                        + Add
                    </button>
                `;
                prodWrap.appendChild(card);
            });
            bubble.appendChild(prodWrap);
        }

        row.appendChild(bubble);
        messagesContainer.appendChild(row);
        scrollToBottom();

        if (save) {
            history.push({ role, content, products });
            saveHistory();
        }
    }

    // Show Typing Indicator
    function showTyping() {
        const row = document.createElement('div');
        row.className = 'chat-msg-row bot';
        row.id = 'chatTypingIndicator';
        row.innerHTML = `
            <div class="chat-msg-avatar">🤖</div>
            <div class="chat-typing-dots">
                <span class="chat-dot"></span>
                <span class="chat-dot"></span>
                <span class="chat-dot"></span>
            </div>
        `;
        messagesContainer.appendChild(row);
        scrollToBottom();
    }

    // Remove Typing Indicator
    function hideTyping() {
        const ind = document.getElementById('chatTypingIndicator');
        if (ind) ind.remove();
    }

    function scrollToBottom() {
        setTimeout(() => {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }, 50);
    }

    // Toggle Chat Window
    function toggleChat() {
        isOpen = !isOpen;
        if (isOpen) {
            chatWindow.classList.add('is-open');
            toggleBtn.classList.add('is-open');
            toggleBtn.setAttribute('aria-expanded', 'true');
            if (unreadBadge) unreadBadge.style.display = 'none';
            chatInput.focus();
            scrollToBottom();
        } else {
            chatWindow.classList.remove('is-open');
            toggleBtn.classList.remove('is-open');
            toggleBtn.setAttribute('aria-expanded', 'false');
        }
    }

    // Send Message
    async function sendMessage(text) {
        const query = (text || '').trim();
        if (!query) return;

        chatInput.value = '';
        sendBtn.disabled = true;

        appendMessageBubble('user', query);
        showTyping();

        try {
            const res = await fetch('chat_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    messages: history.map(m => ({ role: m.role, content: m.content }))
                })
            });

            const data = await res.json();
            hideTyping();
            sendBtn.disabled = false;

            if (data.reply) {
                appendMessageBubble('model', data.reply, data.products || []);
            } else if (data.error) {
                appendMessageBubble('model', "⚠️ " + data.error);
            } else {
                appendMessageBubble('model', "I apologize, but I couldn't process that request right now. Please try again.");
            }

            // Update cart count badge if returned
            if (typeof data.cart_count === 'number') {
                updateHeaderCartCount(data.cart_count);
            }

        } catch (err) {
            hideTyping();
            sendBtn.disabled = false;
            appendMessageBubble('model', "Network error: Unable to contact Nexora AI Assistant. Please check your connection.");
        }
    }

    // Add product to cart directly from chat card
    async function addProductToCart(productId, btnElement) {
        if (!productId) return;
        const origText = btnElement.textContent;
        btnElement.disabled = true;
        btnElement.textContent = '...';

        try {
            const formData = new FormData();
            formData.append('action', 'add');
            formData.append('product_id', productId);

            // Fetch to cart_action.php
            await fetch('cart_action.php', {
                method: 'POST',
                body: formData
            });

            btnElement.classList.add('added');
            btnElement.textContent = '✓ Added';

            // Also inform assistant in chat history
            appendMessageBubble('user', `Added product #${productId} to cart`, [], false);
            appendMessageBubble('model', `Great pick! Product #${productId} was added to your shopping cart. You can click on the cart icon anytime to review or checkout.`, [], false);

            // Update header cart badge
            const cartBadge = document.querySelector('.cart-count');
            if (cartBadge) {
                const current = parseInt(cartBadge.textContent) || 0;
                cartBadge.textContent = current + 1;
            } else {
                const cartBtn = document.querySelector('.cart-btn');
                if (cartBtn) {
                    const newBadge = document.createElement('span');
                    newBadge.className = 'cart-count';
                    newBadge.textContent = '1';
                    cartBtn.appendChild(newBadge);
                }
            }

        } catch (e) {
            btnElement.disabled = false;
            btnElement.textContent = origText;
        }
    }

    function updateHeaderCartCount(count) {
        const cartBadge = document.querySelector('.cart-count');
        if (cartBadge) {
            if (count > 0) {
                cartBadge.textContent = count;
                cartBadge.style.display = 'inline-flex';
            } else {
                cartBadge.style.display = 'none';
            }
        }
    }

    // Reset Chat
    function resetChat() {
        if (confirm('Start a fresh conversation with Nexora AI Assistant?')) {
            history = [];
            sessionStorage.removeItem(STORAGE_KEY);
            messagesContainer.innerHTML = `
                <div class="chat-msg-row bot">
                    <div class="chat-msg-avatar">🤖</div>
                    <div class="chat-bubble">
                        Conversation cleared! How can I assist you with products, orders, or gifts today?
                        <div class="chat-quick-chips" style="margin-top:0.6rem;">
                            <button type="button" class="quick-chip" onclick="window.nexoraChat.sendQuick('Find a gift for my friend')">🎁 Find a gift</button>
                            <button type="button" class="quick-chip" onclick="window.nexoraChat.sendQuick('Track my order TRK-FEDEX-882910')">🚚 Track order</button>
                            <button type="button" class="quick-chip" onclick="window.nexoraChat.sendQuick('Suggest a high-performance laptop')">💻 Best laptop</button>
                        </div>
                    </div>
                </div>
            `;
            chatInput.focus();
        }
    }

    // Event Listeners
    toggleBtn.addEventListener('click', toggleChat);
    closeBtn.addEventListener('click', toggleChat);
    if (minimizeBtn) {
        minimizeBtn.addEventListener('click', toggleChat);
    }
    resetBtn.addEventListener('click', resetChat);

    // Keyboard support: Escape closes/minimizes chat when open
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && isOpen) {
            toggleChat();
        }
    });

    // Expose global methods for inline clicks
    window.nexoraChat = {
        sendQuick: function(text) {
            if (!isOpen) toggleChat();
            sendMessage(text);
        },
        submitInput: function() {
            sendMessage(chatInput.value);
        },
        addProductToCart: addProductToCart
    };

    // Initialize
    loadHistory();
})();
</script>
