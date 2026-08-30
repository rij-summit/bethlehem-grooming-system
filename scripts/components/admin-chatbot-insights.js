function adminChatbotInsights() {
  return {
    loading: false,
    error: "",
    page: 1,
    filters: { status: "all", reason: "", search: "" },
    summary: {
      new_questions: 0,
      total_occurrences: 0,
      groq_fallbacks: 0,
      helpful_feedback: 0,
      unhelpful_feedback: 0,
      helpful_percentage: null,
    },
    insights: [],
    reasonOptions: [],
    recentUnhelpful: [],
    pagination: { current_page: 1, last_page: 1, total: 0 },

    async init() {
      await this.load();
    },

    async load() {
      this.loading = true;
      this.error = "";

      try {
        const response = await API.getChatbotInsights({
          ...this.filters,
          page: this.page,
        });
        this.summary = { ...this.summary, ...(response.summary || {}) };
        this.insights = Array.isArray(response.insights) ? response.insights : [];
        this.reasonOptions = Array.isArray(response.reason_options)
          ? response.reason_options
          : [];
        this.recentUnhelpful = Array.isArray(response.recent_unhelpful)
          ? response.recent_unhelpful
          : [];
        this.pagination = {
          ...this.pagination,
          ...(response.pagination || {}),
        };
        this.page = Number(this.pagination.current_page || 1);
      } catch (error) {
        this.error = error?.message || "Unable to load chatbot insights.";
        this.insights = [];
      } finally {
        this.loading = false;
        this.$nextTick(() => window.lucide?.createIcons());
      }
    },

    async setStatus(insight, status) {
      if (!insight || insight.status === status) return;
      this.error = "";

      try {
        const response = await API.updateChatbotInsightStatus(insight.id, status);
        Object.assign(insight, response.insight || { status });
        await this.load();
      } catch (error) {
        this.error = error?.message || "Unable to update this insight.";
      }
    },

    previousPage() {
      if (this.page <= 1) return;
      this.page -= 1;
      this.load();
    },

    nextPage() {
      if (this.page >= Number(this.pagination.last_page || 1)) return;
      this.page += 1;
      this.load();
    },

    reasonLabel(reason) {
      const labels = {
        off_topic: "Unrecognized topic",
        ambiguous_question: "Needs clarification",
        sensitive_information_blocked: "Private information blocked",
        groq_unavailable: "Groq unavailable",
        groq_rate_limited: "Groq rate limited",
        groq_invalid_response: "Invalid Groq response",
        groq_not_configured: "Groq not configured",
        needs_human_handoff: "Human handoff",
        chatbot_error: "Chatbot error",
        unhelpful_answer: "Unhelpful answer",
      };

      return labels[reason] || String(reason || "Unknown").replace(/_/g, " ");
    },

    formatDate(value) {
      if (!value) return "Unknown time";
      const date = new Date(value);
      if (Number.isNaN(date.getTime())) return "Unknown time";

      return new Intl.DateTimeFormat("en-PH", {
        dateStyle: "medium",
        timeStyle: "short",
      }).format(date);
    },
  };
}
