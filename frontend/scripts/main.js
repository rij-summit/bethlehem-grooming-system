// THIS IS FOR THE MAIN UI FUNCTION

// Connected to index.html line 47 - 80
// Choice between Admin or Customer login
const openLoginModal = document.getElementById("openLoginModal");
const closeLoginModal = document.getElementById("closeLoginModal");
const loginChoiceModal = document.getElementById("loginChoiceModal");

if (openLoginModal && closeLoginModal && loginChoiceModal) {
  openLoginModal.addEventListener("click", () => {
    loginChoiceModal.classList.remove("hidden");
    loginChoiceModal.classList.add("flex");
    document.body.classList.add("overflow-hidden");
  });

  closeLoginModal.addEventListener("click", () => {
    loginChoiceModal.classList.add("hidden");
    loginChoiceModal.classList.remove("flex");
    document.body.classList.remove("overflow-hidden");
  });

  loginChoiceModal.addEventListener("click", (e) => {
    if (e.target === loginChoiceModal) {
      loginChoiceModal.classList.add("hidden");
      loginChoiceModal.classList.remove("flex");
      document.body.classList.remove("overflow-hidden");
    }
  });
}
