// Customer/public chatbot launcher.
// Depends on: api.js (loaded before this script).

const aiChatbotScriptUrl = document.currentScript?.src || document.baseURI;
const aiChatbotIsAdminPage = /\/pages\/admin(?:\/|$)/i.test(
  window.location.pathname,
);

if (!aiChatbotIsAdminPage) {
  ensureAiChatbotStyles();
  window.addEventListener("DOMContentLoaded", initializeAiChatbot);
}

function initializeAiChatbot() {
  ensureAiChatbotMarkup();

  const chatButton = document.getElementById("ai-chat-button");
  const chatPanel = document.getElementById("ai-chat-panel");
  const chatClose = document.getElementById("ai-chat-close");
  const chatReset = document.getElementById("ai-chat-reset");
  const chatMessages = document.getElementById("ai-chat-messages");
  const chatLoading = document.getElementById("ai-chat-loading");
  const chatError = document.getElementById("ai-chat-error");
  const chatForm = document.getElementById("ai-chat-form");
  const chatInput = document.getElementById("ai-chat-input");
  const chatSend = document.getElementById("ai-chat-send");

  if (
    !chatButton ||
    !chatPanel ||
    !chatClose ||
    !chatReset ||
    !chatMessages ||
    !chatLoading ||
    !chatError ||
    !chatForm ||
    !chatInput ||
    !chatSend
  ) {
    return;
  }

  const maxContextMessages = 8;
  const maxContextContentLength = 1200;
  const maxStoredMessages = 12;
  const conversationStorageKey = "bethlehem.chatbot.conversation.v1";
  const conversationHistory = loadConversation();

  chatButton.addEventListener("click", openChatPanel);
  chatClose.addEventListener("click", closeChatPanel);
  chatReset.addEventListener("click", resetConversation);
  chatForm.addEventListener("submit", handleChatSubmit);
  restoreConversation();

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !chatPanel.hidden) {
      closeChatPanel();
    }
  });

  function openChatPanel() {
    chatPanel.hidden = false;
    chatButton.setAttribute("aria-expanded", "true");
    chatInput.focus();
  }

  function closeChatPanel() {
    chatPanel.hidden = true;
    chatButton.setAttribute("aria-expanded", "false");
    chatButton.focus();
  }

  async function handleChatSubmit(event) {
    event.preventDefault();

    const message = chatInput.value.trim();
    if (!message || chatSend.disabled) return;

    chatMessages.querySelector(".ai-chatbot-topics")?.remove();
    appendMessage("user", message);
    chatInput.value = "";
    showError("");
    setLoading(true);
    const contextHistory = conversationHistory
      .slice(-maxContextMessages)
      .map((entry) => ({ role: entry.role, content: entry.content }));

    try {
      if (!window.API?.sendChatbotMessage) {
        throw new Error("The chat service is not ready yet.");
      }

      const response = await window.API.sendChatbotMessage(
        message,
        contextHistory,
      );
      const reply = String(response?.reply || "").trim();
      const assistantReply =
        reply || "Sorry, I could not generate a response.";

      appendMessage("bot", assistantReply, followUpActions(message, response?.source));
      rememberConversationMessage("user", message);
      rememberConversationMessage("assistant", assistantReply);
    } catch (error) {
      showError(error?.message || "Unable to send your message.");
    } finally {
      setLoading(false);
      chatInput.focus();
    }
  }

  function rememberConversationMessage(role, content) {
    conversationHistory.push({
      role,
      content: sanitizeForStorage(role, content),
    });

    if (conversationHistory.length > maxStoredMessages) {
      conversationHistory.splice(
        0,
        conversationHistory.length - maxStoredMessages,
      );
    }

    persistConversation();
  }

  function appendMessage(sender, text, actions = []) {
    const messageEl = document.createElement("div");
    messageEl.className = [
      "ai-chatbot-message",
      sender === "user"
        ? "ai-chatbot-message--user"
        : "ai-chatbot-message--bot",
    ].join(" ");

    if (sender === "bot") {
      renderAssistantResponse(messageEl, text);
      if (actions.length) {
        const actionRow = document.createElement("div");
        actionRow.className = "ai-chatbot-message__actions";
        actions.forEach(([label, prompt]) => {
          const action = document.createElement("button");
          action.type = "button";
          action.className = "ai-chatbot-message__action";
          action.textContent = label;
          action.addEventListener("click", () => {
            if (chatSend.disabled) return;
            chatInput.value = prompt;
            chatForm.requestSubmit();
          });
          actionRow.appendChild(action);
        });
        messageEl.appendChild(actionRow);
      }
    } else {
      messageEl.textContent = text;
    }

    chatMessages.appendChild(messageEl);
    chatMessages.scrollTop = chatMessages.scrollHeight;
  }

  function followUpActions(message, source) {
    if (source === "customer_guide" && /(?:how|paano|steps).*pre[- ]?regist/i.test(message)) {
      return [
        ["Grooming steps", "Grooming pre-registration steps"],
        ["Clinic Visit steps", "Clinic Visit steps"],
      ];
    }
    if (source === "visit_process" && /walk[- ]?in/i.test(message)) {
      return [["How pre-registration works", "How do I pre-register?"]];
    }
    return [];
  }

  function loadConversation() {
    try {
      const parsed = JSON.parse(sessionStorage.getItem(conversationStorageKey) || "[]");
      if (!Array.isArray(parsed)) return [];

      return parsed
        .filter((entry) =>
          ["user", "assistant"].includes(entry?.role) &&
          typeof entry?.content === "string" &&
          entry.content.trim() !== "",
        )
        .slice(-maxStoredMessages)
        .map((entry) => ({
          role: entry.role,
          content: entry.content.slice(0, maxContextContentLength),
        }));
    } catch {
      return [];
    }
  }

  function persistConversation() {
    try {
      sessionStorage.setItem(
        conversationStorageKey,
        JSON.stringify(conversationHistory.slice(-maxStoredMessages)),
      );
    } catch {
      // The chatbot remains usable if private browsing blocks storage.
    }
  }

  function restoreConversation() {
    chatMessages.replaceChildren();
    if (conversationHistory.length === 0) {
      showWelcome();
      return;
    }
    conversationHistory.forEach((entry) => {
      appendMessage(entry.role === "user" ? "user" : "bot", entry.content);
    });
  }

  function resetConversation() {
    conversationHistory.splice(0, conversationHistory.length);
    try {
      sessionStorage.removeItem(conversationStorageKey);
    } catch {
      // Keep reset functional even if storage is unavailable.
    }

    chatMessages.replaceChildren();
    showWelcome();
    showError("");
    chatInput.focus();
  }

  function showWelcome() {
    appendMessage(
      "bot",
      "Hi! I'm Bethlehem's AI assistant. I can help with clinic visits, grooming, pre-registration, and using your customer account.\n\nWhat can I help you with?",
    );
    const topics = document.createElement("div");
    topics.className = "ai-chatbot-topics";
    topics.setAttribute("role", "group");
    topics.setAttribute("aria-label", "Suggested topics");

    const suggestions = [
      ["Grooming time estimate", "How long will grooming take?"],
      ["Grooming prices", "What are your grooming prices?"],
      ["How pre-registration works", "How do I pre-register?"],
      ["Walk-ins & queue", "Do you accept walk-ins, and when does my pet join the queue?"],
      ["Create an account", "How do I create an account?"],
      ["Clinic hours & location", "What are your clinic hours and location?"],
    ];

    if (window.API?.getCustomerToken?.()) {
      suggestions[4] = ["Check my schedule", "Check my schedule"];
      suggestions[5] = ["Grooming Tracker", "Where can I see my pet's grooming progress?"];
    }

    suggestions.forEach(([label, message]) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "ai-chatbot-topic";
      button.textContent = label;
      button.addEventListener("click", () => {
        if (chatSend.disabled) return;
        chatInput.value = message;
        chatForm.requestSubmit();
      });
      topics.appendChild(button);
    });
    chatMessages.appendChild(topics);
    chatMessages.scrollTop = 0;
  }

  function sanitizeForStorage(role, content) {
    const value = String(content || "").slice(0, maxContextContentLength);

    if (role === "user" && containsPrivateInformation(value)) {
      return "Private information was removed for your safety.";
    }

    return value;
  }

  function containsPrivateInformation(value) {
    return [
      /\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i,
      /(?:\+?63|0)?9\d{9}/,
      /\b(?:\d[ -]*?){13,19}\b/,
      /\b(?:password|passcode|pin)\s*(?:is|:|=)\s*\S+/i,
      /\b(?:verification|verify|otp|login|reset|security)\s*code\s*(?:is|:|=)?\s*[A-Z0-9-]{4,12}\b/i,
    ].some((pattern) => pattern.test(value));
  }

  function appendFormattedAssistantText(parentEl, text) {
    const rawText = String(text || "");
    const highlightPattern = /\*\*([^*]+)\*\*/g;
    let lastIndex = 0;
    let match;

    while ((match = highlightPattern.exec(rawText)) !== null) {
      if (match.index > lastIndex) {
        parentEl.appendChild(
          document.createTextNode(rawText.slice(lastIndex, match.index)),
        );
      }

      const highlightEl = document.createElement("strong");
      highlightEl.className = "ai-chatbot-message__highlight";
      highlightEl.textContent = match[1];
      parentEl.appendChild(highlightEl);

      lastIndex = highlightPattern.lastIndex;
    }

    if (lastIndex < rawText.length) {
      parentEl.appendChild(
        document.createTextNode(rawText.slice(lastIndex)),
      );
    }
  }

  function renderAssistantResponse(responseEl, text) {
    const lines = normalizeAssistantLines(text);
    let paragraphLines = [];
    let activeList = null;

    const flushParagraph = () => {
      if (paragraphLines.length === 0) return;

      const paragraphEl = document.createElement("p");
      paragraphEl.className = [
        "ai-chatbot-message__block",
        "ai-chatbot-message__paragraph",
      ].join(" ");
      appendFormattedAssistantLines(paragraphEl, paragraphLines);
      responseEl.appendChild(paragraphEl);
      paragraphLines = [];
    };

    lines.forEach((rawLine, index) => {
      const line = rawLine.trim();

      if (!line) {
        flushParagraph();
        activeList = null;
        return;
      }

      const heading = getAssistantHeading(lines, index);
      if (heading !== null) {
        flushParagraph();
        activeList = null;

        const headingEl = document.createElement("h3");
        headingEl.className = [
          "ai-chatbot-message__block",
          "ai-chatbot-message__heading",
        ].join(" ");
        appendFormattedAssistantText(headingEl, heading);
        responseEl.appendChild(headingEl);
        return;
      }

      const bulletItem = line.match(/^[-*]\s+(.+)$/);
      const numberedItem = line.match(/^(\d{1,2})[.)]\s+(.+)$/);

      if (bulletItem || numberedItem) {
        flushParagraph();

        const listType = bulletItem ? "ul" : "ol";
        if (!activeList || activeList.tagName.toLowerCase() !== listType) {
          activeList = document.createElement(listType);
          activeList.className = [
            "ai-chatbot-message__block",
            "ai-chatbot-message__list",
          ].join(" ");

          if (numberedItem && Number(numberedItem[1]) !== 1) {
            activeList.start = Number(numberedItem[1]);
          }

          responseEl.appendChild(activeList);
        }

        const listItemEl = document.createElement("li");
        listItemEl.className = "ai-chatbot-message__list-item";
        appendFormattedAssistantText(
          listItemEl,
          bulletItem ? bulletItem[1] : numberedItem[2],
        );
        activeList.appendChild(listItemEl);
        return;
      }

      activeList = null;
      paragraphLines.push(line);
    });

    flushParagraph();
  }

  function normalizeAssistantLines(text) {
    const normalized = String(text || "")
      .replace(/\r\n?/g, "\n")
      .replace(/\n{3,}/g, "\n\n")
      .trim();

    if (!normalized) {
      return ["Sorry, I could not generate a response."];
    }

    return normalized
      .split("\n")
      .flatMap((line) => splitInlineNumberedInstructions(line));
  }

  function splitInlineNumberedInstructions(text) {
    const line = String(text || "").trim();
    if (!line) return [""];

    const markers = Array.from(
      line.matchAll(/(^|\s)(\d{1,2})\.\s+/g),
    );

    if (markers.length < 2 || Number(markers[0][2]) !== 1) {
      return [line];
    }

    return line
      .replace(/(^|\s)(\d{1,2})\.\s+/g, "\n$2. ")
      .split(/\n+/)
      .map((part) => part.trim())
      .filter(Boolean);
  }

  function getAssistantHeading(lines, index) {
    const line = String(lines[index] || "").trim();
    const markdownHeading = line.match(/^#{1,6}\s+(.+?)\s*#*$/);

    if (markdownHeading) {
      return markdownHeading[1];
    }

    const previousLineIsBlank =
      index === 0 || String(lines[index - 1] || "").trim() === "";
    const nextLine = String(lines[index + 1] || "").trim();
    const hasFollowingContent = lines
      .slice(index + 1)
      .some((followingLine) => String(followingLine || "").trim() !== "");
    const introducesContent =
      nextLine !== "" || (index === 0 && hasFollowingContent);

    if (
      /^\*\*[^*]+\*\*:?$/.test(line) &&
      previousLineIsBlank &&
      introducesContent
    ) {
      return line;
    }

    const wordCount = line.split(/\s+/).filter(Boolean).length;
    const isShortTitle =
      line.length <= 64 &&
      wordCount <= 8 &&
      /^[A-Z0-9]/.test(line) &&
      !/[.!?,;]$/.test(line) &&
      !/^(?:[-*]\s+|\d{1,2}[.)]\s+)/.test(line) &&
      !/^https?:\/\//i.test(line);

    return previousLineIsBlank && isShortTitle && introducesContent
      ? line
      : null;
  }

  function appendFormattedAssistantLines(parentEl, lines) {
    lines.forEach((line, index) => {
      if (index > 0) {
        parentEl.appendChild(document.createElement("br"));
      }

      appendFormattedAssistantText(parentEl, line);
    });
  }

  function setLoading(isLoading) {
    chatLoading.hidden = !isLoading;
    chatInput.disabled = isLoading;
    chatSend.disabled = isLoading;
    chatReset.disabled = isLoading;
  }

  function showError(message) {
    chatError.textContent = message;
    chatError.hidden = !message;
  }
}

function ensureAiChatbotStyles() {
  const stylesheetVersion = "chatbot-assistant-20261001";
  const existingStylesheet = Array.from(
    document.querySelectorAll('link[rel~="stylesheet"]'),
  ).find((link) => {
    try {
      return new URL(link.href, document.baseURI).pathname.endsWith(
        "/css/custom.css",
      );
    } catch {
      return false;
    }
  });

  if (existingStylesheet) {
    const existingUrl = new URL(existingStylesheet.href, document.baseURI);

    if (existingUrl.searchParams.get("v") !== stylesheetVersion) {
      existingUrl.searchParams.set("v", stylesheetVersion);
      existingStylesheet.href = existingUrl.href;
    }

    return;
  }

  const stylesheet = document.createElement("link");
  stylesheet.rel = "stylesheet";
  stylesheet.href = new URL(
    `../../css/custom.css?v=${stylesheetVersion}`,
    aiChatbotScriptUrl,
  ).href;
  stylesheet.dataset.aiChatbotStyles = "true";
  document.head.appendChild(stylesheet);
}

function ensureAiChatbotMarkup() {
  if (
    document.getElementById("ai-chat-button") ||
    document.getElementById("ai-chat-panel")
  ) {
    return;
  }

  const assistantLabel = "Bethlehem Assistant";
  const spriteUrl = new URL("../../assets/icons/phosphor.svg", aiChatbotScriptUrl).href;
  const renderIcon = (name, className) =>
    `<svg class="ph-icon ${className}" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true" focusable="false"><use href="${spriteUrl}#${name}"></use></svg>`;

  document.body.insertAdjacentHTML(
    "beforeend",
    `
      <button
        id="ai-chat-button"
        type="button"
        class="ai-chatbot-button ai-chatbot-button--icon-only"
        aria-label="Open ${assistantLabel}"
        aria-controls="ai-chat-panel"
        aria-expanded="false"
        data-ai-chatbot-launcher
      >
        ${renderIcon("chat-circle", "ai-chatbot-button__icon")}
      </button>

      <section
        id="ai-chat-panel"
        class="ai-chatbot-panel"
        role="dialog"
        aria-labelledby="ai-chat-title"
        hidden
      >
        <header class="ai-chatbot-panel__header">
          <div>
            <h2 id="ai-chat-title" class="ai-chatbot-panel__title">${assistantLabel}</h2>
            <p class="ai-chatbot-panel__eyebrow">AI assistant</p>
          </div>
          <div class="ai-chatbot-panel__actions">
            <button
              id="ai-chat-reset"
              type="button"
              class="ai-chatbot-reset"
              aria-label="Start a new chatbot conversation"
              title="New chat"
            >
              ${renderIcon("arrow-counter-clockwise", "ai-chatbot-reset__icon")}
            </button>
            <button
              id="ai-chat-close"
              type="button"
              class="ai-chatbot-close"
              aria-label="Close ${assistantLabel}"
            >
              ${renderIcon("x", "ai-chatbot-close__icon")}
            </button>
          </div>
        </header>

        <div
          id="ai-chat-messages"
          class="ai-chatbot-messages"
          aria-live="polite"
          aria-relevant="additions"
        >
        </div>

        <div id="ai-chat-loading" class="ai-chatbot-loading" role="status" hidden>
          ${assistantLabel} is typing...
        </div>

        <p id="ai-chat-error" class="ai-chatbot-error" role="alert" hidden></p>

        <form id="ai-chat-form" class="ai-chatbot-form">
          <label for="ai-chat-input" class="ai-chatbot-sr-only">Message</label>
          <input
            id="ai-chat-input"
            class="ai-chatbot-input"
            name="message"
            type="text"
            autocomplete="off"
            maxlength="1000"
            placeholder="Ask Bethlehem Assistant..."
            required
          />
          <button id="ai-chat-send" type="submit" class="ai-chatbot-send" aria-label="Send message">
            ${renderIcon("paper-plane-tilt", "ai-chatbot-send__icon")}
            <span>Send</span>
          </button>
        </form>
      </section>
    `,
  );
}
