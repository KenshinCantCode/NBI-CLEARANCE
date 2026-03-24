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
const homeLayout = document.querySelector(".home-layout");

if (menuToggle && sidebar && overlay && homeLayout) {
    const setSidebarState = (isOpen) => {
        homeLayout.classList.toggle("sidebar-open", isOpen);
        sidebar.classList.toggle("show", isOpen);
        overlay.classList.toggle("show", isOpen);
        menuToggle.classList.toggle("is-active", isOpen);
        document.body.classList.toggle("menu-open", isOpen);
        menuToggle.setAttribute("aria-expanded", isOpen ? "true" : "false");
        menuToggle.setAttribute("aria-label", isOpen ? "Close sidebar" : "Open sidebar");
        sidebar.setAttribute("aria-hidden", isOpen ? "false" : "true");
    };

    const closeSidebar = () => {
        setSidebarState(false);
    };

    menuToggle.addEventListener("click", () => {
        const isOpen = homeLayout.classList.contains("sidebar-open");
        setSidebarState(!isOpen);
    });

    overlay.addEventListener("click", closeSidebar);

    const navLinks = document.querySelectorAll("[data-nav-link]");
    navLinks.forEach((link) => {
        link.addEventListener("click", closeSidebar);
    });

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && homeLayout.classList.contains("sidebar-open")) {
            closeSidebar();
        }
    });

    window.addEventListener("resize", () => {
        if (!homeLayout.classList.contains("sidebar-open")) {
            document.body.classList.remove("menu-open");
        }
    });

    closeSidebar();
}


const appointmentCalendar = document.getElementById("appointmentCalendar");
if (appointmentCalendar) {
    const calendarDays = document.getElementById("calendarDays");
    const calendarMonthLabel = document.getElementById("calendarMonthLabel");
    const calendarPrev = document.getElementById("calendarPrev");
    const calendarNext = document.getElementById("calendarNext");
    const appointmentDateInput = document.getElementById("appointment_date");
    const appointmentDateDisplay = document.getElementById("appointment_date_display");
    const appointmentTimeInput = document.getElementById("appointment_time");
    const appointmentForm = document.getElementById("appointmentForm");

    const pad2 = (value) => String(value).padStart(2, "0");
    const parseIsoDate = (value) => {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
            return null;
        }

        const [year, month, day] = value.split("-").map((chunk) => Number(chunk));
        const parsed = new Date(year, month - 1, day);
        parsed.setHours(0, 0, 0, 0);

        if (
            parsed.getFullYear() !== year ||
            parsed.getMonth() !== month - 1 ||
            parsed.getDate() !== day
        ) {
            return null;
        }

        return parsed;
    };

    const toIsoDate = (date) => `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`;
    const isWeekend = (date) => {
        const day = date.getDay();
        return day === 0 || day === 6;
    };

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const isPastDate = (date) => date.getTime() < today.getTime();
    const isSelectable = (date) => !isPastDate(date) && !isWeekend(date);
    const formatReadableDate = (date) => new Intl.DateTimeFormat("en-US", {
        weekday: "long",
        year: "numeric",
        month: "long",
        day: "numeric"
    }).format(date);

    if (appointmentDateInput) {
        // Align min date with the user's local date so browser timezone differences do not block selection.
        appointmentDateInput.min = toIsoDate(today);
    }

    let selectedDate = parseIsoDate(
        (appointmentDateInput && appointmentDateInput.value) ||
        (appointmentCalendar.dataset.selectedDate || "")
    );

    if (selectedDate && !isSelectable(selectedDate)) {
        selectedDate = null;
    }

    let viewMonth = selectedDate
        ? new Date(selectedDate.getFullYear(), selectedDate.getMonth(), 1)
        : new Date(today.getFullYear(), today.getMonth(), 1);

    const updateSelectedDateFields = () => {
        if (!appointmentDateInput) {
            return;
        }

        if (!selectedDate) {
            appointmentDateInput.value = "";
            appointmentDateInput.setCustomValidity("");
            if (appointmentDateDisplay) {
                appointmentDateDisplay.textContent = "No date selected yet.";
            }
            return;
        }

        appointmentDateInput.value = toIsoDate(selectedDate);
        appointmentDateInput.setCustomValidity("");
        if (appointmentDateDisplay) {
            appointmentDateDisplay.textContent = formatReadableDate(selectedDate);
        }
    };

    const validateSelectedDateInput = (isRequired = false) => {
        if (!appointmentDateInput) {
            return false;
        }

        const rawDateValue = appointmentDateInput.value;
        if (!rawDateValue) {
            selectedDate = null;
            if (isRequired) {
                appointmentDateInput.setCustomValidity("Please select an appointment date.");
            } else {
                appointmentDateInput.setCustomValidity("");
            }
            if (appointmentDateDisplay) {
                appointmentDateDisplay.textContent = "No date selected yet.";
            }
            return false;
        }

        const parsedDate = parseIsoDate(rawDateValue);
        if (!parsedDate) {
            selectedDate = null;
            appointmentDateInput.setCustomValidity("Please select a valid date.");
            if (appointmentDateDisplay) {
                appointmentDateDisplay.textContent = "No date selected yet.";
            }
            return false;
        }

        if (isPastDate(parsedDate)) {
            selectedDate = null;
            appointmentDateInput.setCustomValidity("Past dates are not available.");
            if (appointmentDateDisplay) {
                appointmentDateDisplay.textContent = "No date selected yet.";
            }
            return false;
        }

        if (isWeekend(parsedDate)) {
            selectedDate = null;
            appointmentDateInput.setCustomValidity("Saturday and Sunday are not available.");
            if (appointmentDateDisplay) {
                appointmentDateDisplay.textContent = "No date selected yet.";
            }
            return false;
        }

        selectedDate = parsedDate;
        appointmentDateInput.setCustomValidity("");
        viewMonth = new Date(selectedDate.getFullYear(), selectedDate.getMonth(), 1);
        if (appointmentDateDisplay) {
            appointmentDateDisplay.textContent = formatReadableDate(selectedDate);
        }
        return true;
    };

    const renderCalendar = () => {
        if (!calendarDays || !calendarMonthLabel || !calendarPrev || !calendarNext) {
            return;
        }

        calendarMonthLabel.textContent = new Intl.DateTimeFormat("en-US", {
            month: "long",
            year: "numeric"
        }).format(viewMonth);

        calendarDays.innerHTML = "";

        const firstDay = new Date(viewMonth.getFullYear(), viewMonth.getMonth(), 1);
        const daysInMonth = new Date(viewMonth.getFullYear(), viewMonth.getMonth() + 1, 0).getDate();
        const mondayIndex = (firstDay.getDay() + 6) % 7;

        for (let i = 0; i < mondayIndex; i += 1) {
            const ghostCell = document.createElement("span");
            ghostCell.className = "calendar-day ghost";
            calendarDays.appendChild(ghostCell);
        }

        for (let day = 1; day <= daysInMonth; day += 1) {
            const date = new Date(viewMonth.getFullYear(), viewMonth.getMonth(), day);
            date.setHours(0, 0, 0, 0);

            const dayButton = document.createElement("button");
            dayButton.type = "button";
            dayButton.className = "calendar-day";
            dayButton.textContent = String(day);

            if (date.getTime() === today.getTime()) {
                dayButton.classList.add("today");
            }
            if (selectedDate && date.getTime() === selectedDate.getTime()) {
                dayButton.classList.add("selected");
            }

            if (!isSelectable(date)) {
                dayButton.disabled = true;
                dayButton.classList.add("blocked");
                dayButton.title = isWeekend(date) ? "Weekend unavailable" : "Past date unavailable";
            } else {
                dayButton.addEventListener("click", () => {
                    selectedDate = date;
                    updateSelectedDateFields();
                    renderCalendar();
                });
            }

            calendarDays.appendChild(dayButton);
        }

        const cellsUsed = mondayIndex + daysInMonth;
        const trailingCells = (7 - (cellsUsed % 7)) % 7;
        for (let i = 0; i < trailingCells; i += 1) {
            const ghostCell = document.createElement("span");
            ghostCell.className = "calendar-day ghost";
            calendarDays.appendChild(ghostCell);
        }

        const earliestMonth = new Date(today.getFullYear(), today.getMonth(), 1);
        const previousMonth = new Date(viewMonth.getFullYear(), viewMonth.getMonth() - 1, 1);
        calendarPrev.disabled = previousMonth.getTime() < earliestMonth.getTime();
    };

    const validateAppointmentTime = () => {
        if (!appointmentTimeInput) {
            return true;
        }

        const value = appointmentTimeInput.value;
        if (!value) {
            appointmentTimeInput.setCustomValidity("");
            return false;
        }

        const [hourText, minuteText] = value.split(":");
        const hour = Number(hourText);
        const minute = Number(minuteText);
        if (Number.isNaN(hour) || Number.isNaN(minute)) {
            appointmentTimeInput.setCustomValidity("Please select a valid appointment time.");
            return false;
        }

        const totalMinutes = (hour * 60) + minute;
        if (totalMinutes < 540 || totalMinutes > 1020) {
            appointmentTimeInput.setCustomValidity("Available time is only from 9:00 AM to 5:00 PM.");
            return false;
        }

        appointmentTimeInput.setCustomValidity("");
        return true;
    };

    if (calendarPrev) {
        calendarPrev.addEventListener("click", () => {
            const previousMonth = new Date(viewMonth.getFullYear(), viewMonth.getMonth() - 1, 1);
            const earliestMonth = new Date(today.getFullYear(), today.getMonth(), 1);
            if (previousMonth.getTime() >= earliestMonth.getTime()) {
                viewMonth = previousMonth;
                renderCalendar();
            }
        });
    }

    if (calendarNext) {
        calendarNext.addEventListener("click", () => {
            viewMonth = new Date(viewMonth.getFullYear(), viewMonth.getMonth() + 1, 1);
            renderCalendar();
        });
    }

    if (appointmentTimeInput) {
        appointmentTimeInput.addEventListener("change", validateAppointmentTime);
        appointmentTimeInput.addEventListener("input", validateAppointmentTime);
    }

    if (appointmentDateInput) {
        appointmentDateInput.addEventListener("change", () => {
            validateSelectedDateInput(false);
            renderCalendar();
        });
        appointmentDateInput.addEventListener("input", () => {
            validateSelectedDateInput(false);
            renderCalendar();
        });
    }

    if (appointmentForm) {
        appointmentForm.addEventListener("submit", (event) => {
            const dateValid = validateSelectedDateInput(true);
            const timeValid = validateAppointmentTime();
            if (!dateValid || !timeValid) {
                event.preventDefault();
                if (!dateValid && appointmentDateInput) {
                    appointmentDateInput.reportValidity();
                } else if (appointmentTimeInput) {
                    appointmentTimeInput.reportValidity();
                }
            }
        });
    }

    if (appointmentDateInput && appointmentDateInput.value) {
        validateSelectedDateInput(false);
    } else {
        updateSelectedDateFields();
    }
    renderCalendar();
    validateAppointmentTime();
}

const notificationOpenButtons = document.querySelectorAll(".notification-open-btn");
if (notificationOpenButtons.length > 0) {
    notificationOpenButtons.forEach((button) => {
        button.addEventListener("click", () => {
            const targetId = button.getAttribute("data-target");
            if (!targetId) {
                return;
            }

            const messageBody = document.getElementById(targetId);
            if (!messageBody) {
                return;
            }

            const isOpening = messageBody.hasAttribute("hidden");
            if (isOpening) {
                messageBody.removeAttribute("hidden");
                button.textContent = "Close";
                button.setAttribute("aria-expanded", "true");
            } else {
                messageBody.setAttribute("hidden", "");
                button.textContent = "Open";
                button.setAttribute("aria-expanded", "false");
            }
        });
    });
}

// Password change form validation
const changePasswordForm = document.getElementById("changePasswordForm");
if (changePasswordForm) {
    const newPasswordInput = document.getElementById("new_password");
    const confirmPasswordInput = document.getElementById("confirm_password");

    const validatePasswordMatch = () => {
        if (!newPasswordInput || !confirmPasswordInput) {
            return true;
        }

        if (newPasswordInput.value && confirmPasswordInput.value) {
            if (newPasswordInput.value !== confirmPasswordInput.value) {
                confirmPasswordInput.setCustomValidity("Passwords do not match.");
            } else {
                confirmPasswordInput.setCustomValidity("");
            }
        }
    };

    const validatePasswordLength = () => {
        if (!newPasswordInput) {
            return true;
        }

        if (newPasswordInput.value && newPasswordInput.value.length < 8) {
            newPasswordInput.setCustomValidity("Password must be at least 8 characters long.");
        } else {
            newPasswordInput.setCustomValidity("");
        }
    };

    if (newPasswordInput) {
        newPasswordInput.addEventListener("input", () => {
            validatePasswordLength();
            validatePasswordMatch();
        });
    }

    if (confirmPasswordInput) {
        confirmPasswordInput.addEventListener("input", validatePasswordMatch);
    }

    changePasswordForm.addEventListener("submit", (event) => {
        validatePasswordLength();
        validatePasswordMatch();

        if (!newPasswordInput || !confirmPasswordInput) {
            return;
        }

        if (newPasswordInput.checkValidity() && confirmPasswordInput.checkValidity()) {
            return;
        }

        event.preventDefault();
        if (!newPasswordInput.checkValidity()) {
            newPasswordInput.reportValidity();
        } else if (!confirmPasswordInput.checkValidity()) {
            confirmPasswordInput.reportValidity();
        }
    });
}
