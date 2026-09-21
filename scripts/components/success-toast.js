(function (global) {
  let dismissTimer;

  global.showSuccessToast = function showSuccessToast(message, duration = 5000) {
    let toast = document.getElementById("success-toast");

    if (!toast) {
      toast = document.createElement("div");
      toast.id = "success-toast";
      toast.className = "app-success-toast";
      toast.setAttribute("role", "status");
      toast.setAttribute("aria-live", "polite");
      toast.innerHTML = '<span class="app-success-toast__icon" aria-hidden="true">✓</span><span class="app-success-toast__message"></span>';
      document.body.appendChild(toast);
    }

    toast.querySelector(".app-success-toast__message").textContent = message;
    toast.classList.add("is-visible");
    clearTimeout(dismissTimer);
    dismissTimer = setTimeout(() => toast.classList.remove("is-visible"), duration);
  };
})(window);
