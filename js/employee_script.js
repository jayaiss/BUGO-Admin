// Sanitize function to clean input data
function sanitizeInput(input) {
  return input.replace(/[<>\"\'&]/g, ''); // Basic sanitization to prevent XSS
}

// Validate email format
function validateEmail(email) {
  const emailPattern = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
  return emailPattern.test(email);
}

// Validate contact number (example: 10 digits for simplicity)
function validateContactNumber(contact) {
  const contactPattern = /^\d{10}$/;
  return contactPattern.test(contact);
}

// Validate required fields (example: first name and last name)
function validateRequiredFields(...fields) {
  return fields.every(field => field.trim() !== '');
}

// Edit Modal Data Population with Validation & Sanitization
const editModal = document.getElementById('editModal');
editModal.addEventListener('show.bs.modal', function (event) {
  const button = event.relatedTarget;

  // Populate the modal with sanitized data
  const firstName = sanitizeInput(button.getAttribute('data-fname'));
  const middleName = sanitizeInput(button.getAttribute('data-mname'));
  const lastName = sanitizeInput(button.getAttribute('data-lname'));
  const birthDate = sanitizeInput(button.getAttribute('data-birthdate'));
  const birthPlace = sanitizeInput(button.getAttribute('data-birthplace'));
  const gender = sanitizeInput(button.getAttribute('data-gender'));
  const contactNumber = sanitizeInput(button.getAttribute('data-contact'));
  const civilStatus = sanitizeInput(button.getAttribute('data-civilstatus'));
  const email = sanitizeInput(button.getAttribute('data-email'));
  const zone = sanitizeInput(button.getAttribute('data-zone'));
  const citizenship = sanitizeInput(button.getAttribute('data-citizenship'));
  const religion = sanitizeInput(button.getAttribute('data-religion'));
  const term = sanitizeInput(button.getAttribute('data-term'));

  document.getElementById('editEmployeeId').value = button.getAttribute('data-id');
  document.getElementById('editFirstName').value = firstName;
  document.getElementById('editMiddleName').value = middleName;
  document.getElementById('editLastName').value = lastName;
  document.getElementById('editBirthDate').value = birthDate;
  document.getElementById('editBirthPlace').value = birthPlace;
  document.getElementById('editGender').value = gender;
  document.getElementById('editContactNumber').value = contactNumber;
  document.getElementById('editCivilStatus').value = civilStatus;
  document.getElementById('editEmail').value = email;
  document.getElementById('editZone').value = zone;
  document.getElementById('editCitizenship').value = citizenship;
  document.getElementById('editReligion').value = religion;
  document.getElementById('editTerm').value = term;
});

// View Modal Data Population
const viewModal = document.getElementById('viewModal');
viewModal.addEventListener('show.bs.modal', function (event) {
  const button = event.relatedTarget;
  document.getElementById('viewFirstName').textContent = button.getAttribute('data-fname');
  document.getElementById('viewMiddleName').textContent = button.getAttribute('data-mname');
  document.getElementById('viewLastName').textContent = button.getAttribute('data-lname');
  document.getElementById('viewBirthDate').textContent = button.getAttribute('data-birthdate');
  document.getElementById('viewBirthPlace').textContent = button.getAttribute('data-birthplace');
  document.getElementById('viewGender').textContent = button.getAttribute('data-gender');
  document.getElementById('viewContactNumber').textContent = button.getAttribute('data-contact');
  document.getElementById('viewCivilStatus').textContent = button.getAttribute('data-civilstatus');
  document.getElementById('viewEmail').textContent = button.getAttribute('data-email');
  document.getElementById('viewZone').textContent = button.getAttribute('data-zone');
  document.getElementById('viewCitizenship').textContent = button.getAttribute('data-citizenship');
  document.getElementById('viewReligion').textContent = button.getAttribute('data-religion');
  document.getElementById('viewTerm').textContent = button.getAttribute('data-term');
});

// Function to validate form inputs before submitting
function validateForm() {
  const firstName = document.getElementById('editFirstName').value;
  const lastName = document.getElementById('editLastName').value;
  const email = document.getElementById('editEmail').value;
  const contactNumber = document.getElementById('editContactNumber').value;

  // Check if required fields are filled
  if (!validateRequiredFields(firstName, lastName)) {
    alert('First Name and Last Name are required.');
    return false;
  }

  // Validate email format
  if (!validateEmail(email)) {
    alert('Please enter a valid email address.');
    return false;
  }

  // Validate contact number (example 10 digits)
  if (!validateContactNumber(contactNumber)) {
    alert('Please enter a valid 10-digit contact number.');
    return false;
  }

  return true;
}

// Adding an event listener to form submission (for example, using a submit button)
// document.getElementById('editForm').addEventListener('submit', function(event) {
//   if (!validateForm()) {
//     event.preventDefault(); // Prevent form submission if validation fails
//   }
// });

// function confirmDelete(employeeId) {
//     if (confirm("Are you sure you want to delete this employee?")) {
//         window.location.href = 'delete/delete_employee.php?id=' + employeeId;
//     }
// }


