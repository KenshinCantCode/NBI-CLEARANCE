const signUpButton = document.getElementById("signUpButton");
const signInButton = document.getElementById("signInButton");
const signInForm = document.getElementById("signIn");
const signUpForm = document.getElementById("signup");

if (signUpButton && signInButton && signInForm && signUpForm) {
    signUpButton.addEventListener("click", () => {
        signInForm.style.display = "none";
        signUpForm.style.display = "block";
    });

    signInButton.addEventListener("click", () => {
        signInForm.style.display = "block";
        signUpForm.style.display = "none";
    });
}

const menuToggle = document.getElementById("menuToggle");
const sidebar = document.getElementById("sidebar");
const overlay = document.getElementById("overlay");

if (menuToggle && sidebar && overlay) {
    const openSidebar = () => {
        sidebar.classList.add("show");
        overlay.classList.add("show");
        menuToggle.classList.add("is-active");
        document.body.classList.add("menu-open");
    };

    const closeSidebar = () => {
        sidebar.classList.remove("show");
        overlay.classList.remove("show");
        menuToggle.classList.remove("is-active");
        document.body.classList.remove("menu-open");
    };

    menuToggle.addEventListener("click", () => {
        if (sidebar.classList.contains("show")) {
            closeSidebar();
        } else {
            openSidebar();
        }
    });

    overlay.addEventListener("click", closeSidebar);

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && sidebar.classList.contains("show")) {
            closeSidebar();
        }
    });

    window.addEventListener("resize", () => {
        if (window.innerWidth > 840) {
            closeSidebar();
        }
    });
}
