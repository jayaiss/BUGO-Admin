
document.addEventListener("DOMContentLoaded", () => {
  // ---------- helpers ----------
  const ymdToday = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
  };
  const toMinutes = t => {
    if (!t) return null;
    const [h, m] = t.split(":").map(Number);
    return h * 60 + m;
  };
  const ensureErrorEl = (id, afterEl, defaultText) => {
    let el = document.getElementById(id);
    if (!el && afterEl) {
      el = document.createElement("small");
      el.id = id;
      el.className = "text-danger d-none";
      el.textContent = defaultText;
      afterEl.insertAdjacentElement("afterend", el);
    }
    return el;
  };
  const setFieldState = (input, ok, errorEl, okMsg, errMsg) => {
    input.classList.remove("is-valid","is-invalid");
    if (ok) {
      input.classList.add("is-valid");
      if (errorEl) { errorEl.classList.add("d-none"); if (okMsg) errorEl.textContent = okMsg; }
      input.setAttribute("aria-invalid","false");
    } else {
      input.classList.add("is-invalid");
      if (errorEl) { errorEl.classList.remove("d-none"); if (errMsg) errorEl.textContent = errMsg; }
      input.setAttribute("aria-invalid","true");
    }
  };

  // ---------- core validator ----------
  function attachDateTimeValidation(cfg) {
    const {
      dateInput, startInput, endInput,
      dateErrorId, timeErrorId,
      submitBtn
    } = cfg;

    if (!dateInput || !startInput || !endInput || !submitBtn) return;

    // add aria for a11y
    [dateInput,startInput,endInput].forEach(i => i.setAttribute("aria-live","polite"));

    // enforce min attr in picker
    dateInput.min = ymdToday();

    // ensure error <small> exist (if not present, create them right after inputs)
    const dateErrorEl = ensureErrorEl(dateErrorId, dateInput, "Date must be today or later.");
    const timeErrorEl = ensureErrorEl(timeErrorId, endInput, "End time must be later than start time.");

    function validDate() {
      const val = dateInput.value;
      if (!val) { setFieldState(dateInput, false, dateErrorEl, "", "Please choose a date."); return false; }
      const ok = val >= ymdToday();
      setFieldState(dateInput, ok, dateErrorEl, "", "Date must be today or later.");
      return ok;
    }

    function validTime() {
      const s = startInput.value, e = endInput.value;
      if (!s || !e) {
        setFieldState(startInput, false, timeErrorEl, "", "Enter both start and end time.");
        setFieldState(endInput,   false, timeErrorEl);
        return false;
      }
      const ok = toMinutes(e) > toMinutes(s);
      setFieldState(startInput, ok, timeErrorEl, "", "End time must be later than start time (e.g., 10:00 AM → 11:00 AM).");
      setFieldState(endInput,   ok, timeErrorEl);
      return ok;
    }

    function updateState() {
      const dOk = validDate();
      const tOk = validTime();
      submitBtn.disabled = !(dOk && tOk);
      return dOk && tOk;
    }

    // real-time: validate on input & change
    ["input","change","blur"].forEach(ev => {
      dateInput.addEventListener(ev, updateState);
      startInput.addEventListener(ev, updateState);
      endInput.addEventListener(ev, updateState);
    });

    // block submit if invalid
    const form = submitBtn.closest("form");
    if (form) form.addEventListener("submit", e => { if (!updateState()) e.preventDefault(); });

    // initial
    updateState();
  }

  // ---------- wire Add modal ----------
  attachDateTimeValidation({
    dateInput:  document.getElementById("date"),
    startInput: document.getElementById("start_time"),
    endInput:   document.getElementById("end_time"),
    dateErrorId:"dateErrorAdd",
    timeErrorId:"timeErrorAdd",
    submitBtn:  document.querySelector('#addEventModal button[type="submit"]')
  });

  // ---------- wire Edit modal (late-populated) ----------
  function wireEdit() {
    attachDateTimeValidation({
      dateInput:  document.getElementById("edit_date"),
      startInput: document.getElementById("edit_start_time"),
      endInput:   document.getElementById("edit_end_time"),
      dateErrorId:"dateErrorEdit",
      timeErrorId:"timeErrorEdit",
      submitBtn:  document.querySelector('#viewEditModal button[type="submit"], #editEventForm button[type="submit"]')
    });
  }
  // try now and on modal show (covers first open)
  wireEdit();
  document.addEventListener("shown.bs.modal", e => { if (e.target.id === "viewEditModal") wireEdit(); });
});

