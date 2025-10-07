
    document.addEventListener("DOMContentLoaded", function () {
    const dateHearing = document.getElementById('date_hearing');
    const dateFiled = document.getElementById('date_filed');
    const submitBtn = document.querySelector('button[name="add_case"]');
    const dateFiledError = document.createElement('small');
    const dateHearingError = document.createElement('small');
    const hearingAfterFiledError = document.createElement('small');

    // Setup error elements
    dateFiledError.className = 'text-danger d-none';
    dateFiledError.id = 'dateFiledError';
    dateFiledError.textContent = '❌ Date filed cannot be in the future.';
    dateFiled.parentNode.appendChild(dateFiledError);

    hearingAfterFiledError.className = 'text-danger d-none';
    hearingAfterFiledError.id = 'hearingAfterFiledError';
    hearingAfterFiledError.textContent = '❌ Hearing date must be after the filing date.';
    dateHearing.parentNode.appendChild(hearingAfterFiledError);

    function resetValidation() {
      dateHearing.classList.remove('is-invalid');
      dateFiled.classList.remove('is-invalid');
      dateFiledError.classList.add('d-none');
      dateHearingError.classList.add('d-none');
      hearingAfterFiledError.classList.add('d-none');
    }

    function validateCaseForm() {
      resetValidation();

      let invalid = false;
      const today = new Date();
      today.setHours(0, 0, 0, 0);

      const filedDate = new Date(dateFiled.value + 'T00:00:00');
      const hearingDate = new Date(dateHearing.value + 'T00:00:00');

      // 2. Filed date cannot be in the future
      if (dateFiled.value && filedDate > today) {
        dateFiled.classList.add('is-invalid');
        dateFiledError.classList.remove('d-none');
        invalid = true;
      }

      // 3. Hearing must be after date filed
      if (dateHearing.value && dateFiled.value && hearingDate <= filedDate) {
        dateHearing.classList.add('is-invalid');
        hearingAfterFiledError.classList.remove('d-none');
        invalid = true;
      }

      submitBtn.disabled = invalid;
      return !invalid;
    }

    // Attach to form on submit
    const form = document.querySelector('form');
    form.addEventListener('submit', function (e) {
      if (!validateCaseForm()) {
        e.preventDefault();
      }
    });

    // Live validation
    [dateHearing, dateFiled].forEach(input => {
      input.addEventListener('change', validateCaseForm);
    });

    // Initial state
    validateCaseForm();
  });
  
  
  document.addEventListener("DOMContentLoaded", function () {
    const modalDateHearing = document.getElementById('modal_date_hearing');
    const modalDateFiled = document.getElementById('modal_date_filed');
    const modalSubmitBtn = document.querySelector('#editCaseForm button[type="submit"]');

    // Setup error elements if not already in HTML
    const modalDateFiledError = document.getElementById('editFiledError') || (() => {
      const e = document.createElement('small');
      e.id = 'editFiledError';
      e.className = 'text-danger d-none';
      e.textContent = '❌ Date filed cannot be in the future.';
      modalDateFiled.parentNode.appendChild(e);
      return e;
    })();

    const modalHearingAfterFiledError = document.getElementById('editHearingError') || (() => {
      const e = document.createElement('small');
      e.id = 'editHearingError';
      e.className = 'text-danger d-none';
      e.textContent = '❌ Hearing date must be after the filing date.';
      modalDateHearing.parentNode.appendChild(e);
      return e;
    })();

    function resetEditValidation() {
      modalDateHearing.classList.remove('is-invalid');
      modalDateFiled.classList.remove('is-invalid');
      modalDateFiledError.classList.add('d-none');
      modalHearingAfterFiledError.classList.add('d-none');
    }

    function validateEditModal() {
      resetEditValidation();

      let invalid = false;
      const today = new Date();
      today.setHours(0, 0, 0, 0);

      const filedDate = new Date(modalDateFiled.value + 'T00:00:00');
      const hearingDate = new Date(modalDateHearing.value + 'T00:00:00');

      if (modalDateFiled.value && filedDate > today) {
        modalDateFiled.classList.add('is-invalid');
        modalDateFiledError.classList.remove('d-none');
        invalid = true;
      }

      if (modalDateHearing.value && modalDateFiled.value && hearingDate <= filedDate) {
        modalDateHearing.classList.add('is-invalid');
        modalHearingAfterFiledError.classList.remove('d-none');
        invalid = true;
      }

      modalSubmitBtn.disabled = invalid;
      return !invalid;
    }

    // Attach validation events
    ['change', 'input'].forEach(evt => {
      modalDateFiled.addEventListener(evt, validateEditModal);
      modalDateHearing.addEventListener(evt, validateEditModal);
    });

    // Run on modal open
    const modalElement = document.getElementById('viewCaseModal');
    modalElement.addEventListener('shown.bs.modal', validateEditModal);

    // Attach to form submit
    const editForm = document.getElementById('editCaseForm');
    editForm.addEventListener('submit', function (e) {
      if (!validateEditModal()) {
        e.preventDefault();
      }
    });
  });