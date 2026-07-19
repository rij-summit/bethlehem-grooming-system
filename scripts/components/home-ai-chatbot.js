// Connected to index.html.
// Depends on: api.js (loaded before this script).

window.addEventListener("DOMContentLoaded", () => {
  window.lucide?.createIcons();

  const chatButton = document.getElementById("ai-chat-button");
  const chatPanel = document.getElementById("ai-chat-panel");
  const chatClose = document.getElementById("ai-chat-close");
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
    !chatMessages ||
    !chatLoading ||
    !chatError ||
    !chatForm ||
    !chatInput ||
    !chatSend
  ) {
    return;
  }

  chatButton.addEventListener("click", openChatPanel);
  chatClose.addEventListener("click", closeChatPanel);
  chatForm.addEventListener("submit", handleChatSubmit);

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

    try {
      if (!window.API?.sendChatbotMessage) {
        throw new Error("The chat service is not ready yet.");
      }

      const response = await window.API.sendChatbotMessage(message);
      const reply = String(response?.reply || "").trim();

      appendMessage(
        "bot",
        reply || "Sorry, I could not generate a response.",
      );
    } catch (error) {
      showError(error?.message || "Unable to send your message.");
    } finally {
      setLoading(false);
      chatInput.focus();
    }
  }

  function appendMessage(sender, text) {
    const messageEl = document.createElement("div");
    messageEl.className = [
      "ai-chatbot-message",
      sender === "user"
        ? "ai-chatbot-message--user"
        : "ai-chatbot-message--bot",
    ].join(" ");

    if (sender === "bot") {
      formatAssistantParagraphs(text).forEach((paragraph) => {
        const paragraphEl = document.createElement("p");
        paragraphEl.className = "ai-chatbot-message__paragraph";
        appendFormattedAssistantText(paragraphEl, paragraph);
        messageEl.appendChild(paragraphEl);
      });
    } else {
      messageEl.textContent = text;
    }

    chatMessages.appendChild(messageEl);
    chatMessages.scrollTop = chatMessages.scrollHeight;
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

  function formatAssistantParagraphs(text) {
    const normalized = String(text || "")
      .replace(/\r\n?/g, "\n")
      .replace(/\n{3,}/g, "\n\n")
      .trim();

    if (!normalized) {
      return ["Sorry, I could not generate a response."];
    }

    const explicitParagraphs = normalized
      .split(/\n{2,}/)
      .map((paragraph) => paragraph.replace(/\s*\n\s*/g, " ").trim())
      .filter(Boolean);

    const numberedParagraphs = explicitParagraphs.flatMap(
      splitNumberedInstructions,
    );

    if (numberedParagraphs.length > 1) {
      return numberedParagraphs;
    }

    if (explicitParagraphs.length > 1) {
      return explicitParagraphs;
    }

    const compactText =
      explicitParagraphs[0] || normalized.replace(/\s+/g, " ");
    const sentences = compactText.match(/[^.!?]+[.!?]+(?:["')\]]+)?|[^.!?]+$/g) || [
      compactText,
    ];
    const paragraphStarters =
      /^(For|However|But|If|When|Please|You|We|The|This|That|These|Those|I(?:'m| am))\b/;

    return sentences.reduce((paragraphs, sentence, index) => {
      const trimmedSentence = sentence.trim();
      if (!trimmedSentence) return paragraphs;

      if (index > 0 && paragraphStarters.test(trimmedSentence)) {
        paragraphs.push(trimmedSentence);
        return paragraphs;
      }

      if (paragraphs.length === 0) {
        paragraphs.push(trimmedSentence);
      } else {
        paragraphs[paragraphs.length - 1] += ` ${trimmedSentence}`;
      }

      return paragraphs;
    }, []);
  }

  function splitNumberedInstructions(text) {
    const paragraph = String(text || "").replace(/\s+/g, " ").trim();
    const markers = Array.from(
      paragraph.matchAll(/(^|\s)(\d{1,2})\.\s+/g),
    );

    if (markers.length < 2 || Number(markers[0][2]) !== 1) {
      return paragraph ? [paragraph] : [];
    }

    return paragraph
      .replace(/(^|\s)(\d{1,2})\.\s+/g, "\n$2. ")
      .split(/\n+/)
      .map((part) => part.trim())
      .filter(Boolean);
  }

  function setLoading(isLoading) {
    chatLoading.hidden = !isLoading;
    chatInput.disabled = isLoading;
    chatSend.disabled = isLoading;
  }

  function showError(message) {
    chatError.textContent = message;
    chatError.hidden = !message;
  }
});
