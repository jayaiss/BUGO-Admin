
/* ===========================
   Email Validation Utilities
   =========================== */

/** ⛳ Config — adjust as needed */
const emailDupURL = "ajax/check_email_exists.php"; // your PHP endpoint
const useDeliverabilityAPI = true;                 // flip to false to skip external API
const abstractApiKey = "f030e1843d104aa3932f9a0719c1ea1a"; // Abstract Email Validation API key

/* ---------------- Debounce helper ---------------- */
function debounce(fn, delay) {
  let timer;
  return function (...args) {
    clearTimeout(timer);
    timer = setTimeout(() => fn.apply(this, args), delay);
  };
}

/* ---------------- DB duplicate check (resident/employee) ----------------
   scope: 'resident' | 'employee' | 'both'
   excludeResident / excludeEmployee: numeric ID of the record being edited (to ignore self)
--------------------------------------------------------------------------- */
async function emailAlreadyUsed(email, { scope = "both", excludeResident = null, excludeEmployee = null } = {}) {
  const params = new URLSearchParams({ email, scope });
  if (excludeResident) params.set("exclude_resident", String(excludeResident));
  if (excludeEmployee) params.set("exclude_employee", String(excludeEmployee));

  const res = await fetch(`${emailDupURL}?${params.toString()}`, { credentials: "same-origin" });
  if (!res.ok) throw new Error(`Lookup failed: ${res.status}`);
  return res.json(); // { exists, resident_exists, employee_exists }
}

/* ---------------- External deliverability check (Abstract API) ---------------- */
async function validateDeliverability(email) {
  if (!useDeliverabilityAPI) return { ok: true, reason: "skipped" };

  const url = `https://emailvalidation.abstractapi.com/v1/?api_key=${encodeURIComponent(abstractApiKey)}&email=${encodeURIComponent(email)}`;

  try {
    const res = await fetch(url);
    if (res.status === 429) {
      return { ok: false, reason: "Too many requests — try again later." };
    }
    if (!res.ok) {
      return { ok: false, reason: `Validation error (${res.status})` };
    }
    const data = await res.json();
    // Accept DELIVERABLE; treat UNKNOWN/RISKY as invalid (tune if you like)
    if (data && String(data.deliverability).toUpperCase() === "DELIVERABLE") {
      return { ok: true };
    }
    return { ok: false, reason: "Invalid Email" };
  } catch (err) {
    console.error("Email validation error:", err);
    return { ok: false, reason: "Could not validate email right now." };
  }
}

/* ---------------- UI helpers ---------------- */
function markInvalid(input, feedback, message) {
  input.classList.remove("is-valid");
  input.classList.add("is-invalid");
  if (feedback) {
    feedback.classList.remove("valid-feedback");
    feedback.classList.add("invalid-feedback");
    feedback.textContent = message;
  }
}

function markValid(input, feedback, message = "Email is valid.") {
  input.classList.remove("is-invalid");
  input.classList.add("is-valid");
  if (feedback) {
    feedback.classList.remove("invalid-feedback");
    feedback.classList.add("valid-feedback");
    feedback.textContent = message;
  }
}

/* ---------------- Core single-field validator ---------------- */
async function validateEmailField(input, feedback, {
  scope = "both",
  excludeResident = null,
  excludeEmployee = null,
  primarySelector = "#primary_email",
} = {}) {
  const email = (input.value || "").trim();
  if (!email) {
    input.classList.remove("is-valid", "is-invalid");
    if (feedback) feedback.textContent = "";
    return;
  }

  const basicPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  if (!basicPattern.test(email)) {
    markInvalid(input, feedback, "Please enter a valid email address.");
    return;
  }

  const isPrimary = input.matches(primarySelector);
  const primaryEl = document.querySelector(primarySelector);
  const primaryEmail = (primaryEl?.value || "").trim().toLowerCase();

  if (!isPrimary && email.toLowerCase() === primaryEmail && primaryEmail.length) {
    markInvalid(input, feedback, "This email is already used as the primary email.");
    return;
  }

  const allEmails = Array.from(document.querySelectorAll("input[type='email']"))
    .filter(el => el !== input)
    .map(el => (el.value || "").trim().toLowerCase())
    .filter(v => v.length > 0);

  if (allEmails.includes(email.toLowerCase())) {
    markInvalid(input, feedback, "Duplicate email within the form.");
    return;
  }

  const deliverability = await validateDeliverability(email);
  if (!deliverability.ok) {
    markInvalid(input, feedback, deliverability.reason || "Invalid Email");
    return;
  }

  try {
    const dup = await emailAlreadyUsed(email, { scope, excludeResident, excludeEmployee });

    if (scope === "resident" && dup.resident_exists) {
      markInvalid(input, feedback, "Email already used by another resident.");
      return;
    }
    if (scope === "employee" && dup.employee_exists) {
      markInvalid(input, feedback, "Email already used by another employee.");
      return;
    }
    if (scope === "both" && dup.exists) {
      markInvalid(input, feedback, "Email already used.");
      return;
    }

    markValid(input, feedback);
  } catch (err) {
    console.error("DB email check error:", err);
    markInvalid(input, feedback, "Could not verify email right now.");
  }
}

/* ---------------- Debounced wrapper ---------------- */
const debouncedValidateEmailField = debounce((...args) => {
  validateEmailField(...args);
}, 500);

/* ===== Wiring: Primary (Resident) ===== */
const primaryInput = document.getElementById("primary_email");
const primaryFeedback = document.getElementById("emailFeedback");
if (primaryInput) {
  primaryInput.addEventListener("blur", () => {
    const currentResidentId = document.getElementById("residentId")?.value
      ? Number(document.getElementById("residentId").value)
      : null;

    debouncedValidateEmailField(primaryInput, primaryFeedback, {
      scope: "resident",
      excludeResident: currentResidentId,
      primarySelector: "#primary_email",
    });
  });
}

/* ===== Wiring: Family Members (Add) ===== */
document.addEventListener("blur", (e) => {
  const target = e.target;
  if (!(target instanceof HTMLElement)) return;

  if (target.classList.contains("family-email")) {
    const container = target.closest(".col-md-4, .form-group, .mb-3") || target.parentElement;
    let feedbackEl = container.querySelector(".email-feedback");
    if (!feedbackEl) {
      feedbackEl = document.createElement("small");
      feedbackEl.className = "form-text email-feedback";
      container.appendChild(feedbackEl);
    }

    const currentResidentId = document.getElementById("residentId")?.value
      ? Number(document.getElementById("residentId").value)
      : null;

    debouncedValidateEmailField(target, feedbackEl, {
      scope: "resident",
      excludeResident: currentResidentId,
      primarySelector: "#primary_email",
    });
  }
}, true);

/* ===== Wiring: Employee (Add/Edit) ===== */
document.addEventListener("blur", (e) => {
  const target = e.target;
  if (!(target instanceof HTMLElement)) return;

  if (target.classList.contains("employee-email")) {
    const container = target.closest(".col-md-4, .form-group, .mb-3") || target.parentElement;
    let feedbackEl = container.querySelector(".email-feedback");
    if (!feedbackEl) {
      feedbackEl = document.createElement("small");
      feedbackEl.className = "form-text email-feedback";
      container.appendChild(feedbackEl);
    }

    const currentEmployeeId = document.getElementById("employeeId")?.value
      ? Number(document.getElementById("employeeId").value)
      : null;

    debouncedValidateEmailField(target, feedbackEl, {
      scope: "employee",
      excludeEmployee: currentEmployeeId,
      primarySelector: "#primary_email",
    });
  }
}, true);

/* ===== Edit Modal (Resident) ===== */
const editEmail = document.getElementById("editEmail");
if (editEmail) {
  let editFeedback = editEmail.parentElement?.querySelector(".email-feedback");
  if (!editFeedback) {
    editFeedback = document.createElement("small");
    editFeedback.className = "form-text email-feedback";
    editEmail.parentElement.appendChild(editFeedback);
  }
  editEmail.addEventListener("blur", () => {
    const currentId = document.getElementById("editId")?.value
      ? Number(document.getElementById("editId").value)
      : null;

    debouncedValidateEmailField(editEmail, editFeedback, {
      scope: "resident",
      excludeResident: currentId,
      primarySelector: "#primary_email",
    });
  });
}

/* ===== Edit Modal Family Members ===== */
const editModals = document.getElementById("editModals");
if (editModals) {
  editModals.addEventListener("blur", (e) => {
    const target = e.target;
    if (!(target instanceof HTMLElement)) return;
    if (!target.classList.contains("family-email")) return;

    const container = target.closest(".col-md-4, .form-group, .mb-3") || target.parentElement;
    let feedbackEl = container.querySelector(".email-feedback");
    if (!feedbackEl) {
      feedbackEl = document.createElement("small");
      feedbackEl.className = "form-text email-feedback";
      container.appendChild(feedbackEl);
    }

    const currentId = document.getElementById("editId")?.value
      ? Number(document.getElementById("editId").value)
      : null;

    debouncedValidateEmailField(target, feedbackEl, {
      scope: "resident",
      excludeResident: currentId,
      primarySelector: "#primary_email",
    });
  }, true);
}

/* ===== Submit guards (All forms) ===== */
function blockSubmitIfInvalid(e) {
  const form = e.currentTarget;
  const invalids = form.querySelectorAll("input[type='email'].is-invalid");
  if (invalids.length > 0) {
    e.preventDefault();
    alert("Please fix invalid or duplicate email addresses before submitting.");
  }
}
document.querySelectorAll("form.email-guard").forEach((form) => {
  form.addEventListener("submit", blockSubmitIfInvalid);
});
