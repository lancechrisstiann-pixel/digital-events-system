document.addEventListener("DOMContentLoaded", () => {

    const modal = document.getElementById("addEventModal");
    const openBtn = document.getElementById("openAddEventModal");
    const closeBtn = document.getElementById("closeModal");

    if (!modal) return;
    if (openBtn) {
        openBtn.addEventListener("click", () => {
            modal.classList.add("show");
        });
    }
    if (closeBtn) {
        closeBtn.addEventListener("click", () => {
            modal.classList.remove("show");
        });
    }
    modal.addEventListener("click", (e) => {
        if (e.target === modal) {
            modal.classList.remove("show");
        }
    });
    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape") {
            modal.classList.remove("show");
        }
    });

});