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
  window.lucide?.createIcons();

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

  const maxContextMessages = 4;
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
    if (!message) return;

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

      appendMessage("bot", assistantReply, {
        feedbackToken: String(response?.feedback_token || ""),
      });
      rememberConversationMessage("user", message);
      rememberConversationMessage("assistant", assistantReply, {
        feedbackToken: String(response?.feedback_token || ""),
      });
    } catch (error) {
      showError(error?.message || "Unable to send your message.");
    } finally {
      setLoading(false);
      chatInput.focus();
    }
  }

  function rememberConversationMessage(role, content, options = {}) {
    conversationHistory.push({
      role,
      content: sanitizeForStorage(role, content),
      feedbackToken: String(options.feedbackToken || ""),
      feedbackSubmitted: Boolean(options.feedbackSubmitted),
    });

    if (conversationHistory.length > maxStoredMessages) {
      conversationHistory.splice(
        0,
        conversationHistory.length - maxStoredMessages,
      );
    }

    persistConversation();
  }

  function appendMessage(sender, text, options = {}) {
    const messageEl = document.createElement("div");
    messageEl.className = [
      "ai-chatbot-message",
      sender === "user"
        ? "ai-chatbot-message--user"
        : "ai-chatbot-message--bot",
    ].join(" ");

    if (sender === "bot") {
      renderAssistantResponse(messageEl, text);
      if (options.feedbackToken) {
        appendFeedbackControls(messageEl, options.feedbackToken, {
          submitted: Boolean(options.feedbackSubmitted),
        });
      }
    } else {
      messageEl.textContent = text;
    }

    chatMessages.appendChild(messageEl);
    chatMessages.scrollTop = chatMessages.scrollHeight;
  }

  function appendFeedbackControls(messageEl, feedbackToken, options = {}) {
    const feedbackEl = document.createElement("div");
    feedbackEl.className = "ai-chatbot-message__feedback";

    const promptEl = document.createElement("span");
    promptEl.className = "ai-chatbot-message__feedback-prompt";
    promptEl.textContent = options.submitted ? "Thanks for your feedback." : "Helpful?";
    feedbackEl.appendChild(promptEl);

    if (!options.submitted) {
      [
        { helpful: true, label: "Yes" },
        { helpful: false, label: "No" },
      ].forEach(({ helpful, label }) => {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "ai-chatbot-message__feedback-button";
        button.textContent = label;
        button.setAttribute(
          "aria-label",
          helpful ? "Mark response helpful" : "Mark response not helpful",
        );
        button.addEventListener("click", () =>
          submitFeedback(feedbackEl, feedbackToken, helpful),
        );
        feedbackEl.appendChild(button);
      });
    }

    messageEl.appendChild(feedbackEl);
  }

  async function submitFeedback(feedbackEl, feedbackToken, helpful) {
    const buttons = Array.from(feedbackEl.querySelectorAll("button"));
    buttons.forEach((button) => { button.disabled = true; });

    try {
      if (!window.API?.sendChatbotFeedback) {
        throw new Error("Feedback is not available yet.");
      }

      await window.API.sendChatbotFeedback(feedbackToken, helpful);
      feedbackEl.replaceChildren();

      const thanksEl = document.createElement("span");
      thanksEl.className = "ai-chatbot-message__feedback-prompt";
      thanksEl.textContent = "Thanks for your feedback.";
      feedbackEl.appendChild(thanksEl);
      markFeedbackSubmitted(feedbackToken);
    } catch (error) {
      buttons.forEach((button) => { button.disabled = false; });
      showError(error?.message || "Unable to save feedback.");
    }
  }

  function markFeedbackSubmitted(feedbackToken) {
    const entry = conversationHistory.find(
      (message) => message.feedbackToken === feedbackToken,
    );
    if (!entry) return;

    entry.feedbackSubmitted = true;
    persistConversation();
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
          feedbackToken: String(entry.feedbackToken || ""),
          feedbackSubmitted: Boolean(entry.feedbackSubmitted),
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
    conversationHistory.forEach((entry) => {
      appendMessage(entry.role === "user" ? "user" : "bot", entry.content, {
        feedbackToken: entry.feedbackToken,
        feedbackSubmitted: entry.feedbackSubmitted,
      });
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
    appendMessage(
      "bot",
      "Hi! I can help with general clinic and grooming questions.",
    );
    showError("");
    chatInput.focus();
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
  const stylesheetVersion = "chatbot-safety-insights-20260830";
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

  document.body.insertAdjacentHTML(
    "beforeend",
    `
      <button
        id="ai-chat-button"
        type="button"
        class="ai-chatbot-button"
        aria-label="Open AI Chatbot"
        aria-controls="ai-chat-panel"
        aria-expanded="false"
        data-ai-chatbot-launcher
      >
        <svg class="ai-chatbot-button__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"></path>
        </svg>
        <span>AI Chatbot</span>
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
            <p class="ai-chatbot-panel__eyebrow">Bethlehem Assistant</p>
            <h2 id="ai-chat-title" class="ai-chatbot-panel__title">AI Chatbot</h2>
          </div>
          <div class="ai-chatbot-panel__actions">
            <button
              id="ai-chat-reset"
              type="button"
              class="ai-chatbot-reset"
              aria-label="Start a new chatbot conversation"
              title="New chat"
            >
              <svg class="ai-chatbot-reset__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M3 12a9 9 0 1 0 3-6.7"></path>
                <path d="M3 3v6h6"></path>
              </svg>
            </button>
            <button
              id="ai-chat-close"
              type="button"
              class="ai-chatbot-close"
              aria-label="Close AI Chatbot"
            >
              <svg class="ai-chatbot-close__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M18 6 6 18M6 6l12 12"></path>
              </svg>
            </button>
          </div>
        </header>

        <div
          id="ai-chat-messages"
          class="ai-chatbot-messages"
          aria-live="polite"
          aria-relevant="additions"
        >
          <div class="ai-chatbot-message ai-chatbot-message--bot">
            Hi! I can help with general clinic and grooming questions.
          </div>
        </div>

        <div id="ai-chat-loading" class="ai-chatbot-loading" role="status" hidden>
          AI Chatbot is typing...
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
            placeholder="Type your question..."
            required
          />
          <button id="ai-chat-send" type="submit" class="ai-chatbot-send">
            <svg class="ai-chatbot-send__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path d="m22 2-7 20-4-9-9-4Z"></path>
              <path d="M22 2 11 13"></path>
            </svg>
            <span>Send</span>
          </button>
        </form>
      </section>
    `,
  );
}
