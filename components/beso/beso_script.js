function confirmDelete(id) {
  Swal.fire({
    title: 'Are you sure?',
    text: "This will archive the record.",
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Yes, archive it!',
    cancelButtonText: 'Cancel',
    confirmButtonColor: '#d33',
    cancelButtonColor: '#6c757d'
  }).then((result) => {
    if (result.isConfirmed) {
      window.location.href = deleteBaseUrl + '&delete_id=' + id;
    }
  });
}

function confirmEditSubmit() {
  Swal.fire({
    title: 'Save changes?',
    text: "This will update the BESO entry.",
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Yes, update it!',
    cancelButtonText: 'Cancel',
    confirmButtonColor: '#3085d6',
    cancelButtonColor: '#6c757d'
  }).then((result) => {
    if (result.isConfirmed) {
      document.getElementById('submitEditForm').click();
    }
  });
}

document.addEventListener('DOMContentLoaded', function () {
  const editButtons = document.querySelectorAll('.editBtn');

  editButtons.forEach(button => {
    button.addEventListener('click', function () {
      document.getElementById('edit_beso_id').value = this.dataset.id;
      document.getElementById('edit_education').value = this.dataset.education;
      document.getElementById('edit_course').value = this.dataset.course;
    });
  });
});