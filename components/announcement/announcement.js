function confirmDelete(id) {
  Swal.fire({
    title: 'Are you sure?',
    text: "This will archive the announcement.",
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#d33',
    cancelButtonColor: '#6c757d',
    confirmButtonText: 'Yes, archive'
  }).then((result) => {
    if (result.isConfirmed) {
    //   window.location.href = 'index_admin.php?page=<?= urlencode(encrypt('announcements')) ?>&delete_id=' + id;
      window.location.href = deleteBaseUrl + '&delete_id=' + id;
    }
  });
}

document.querySelectorAll('.editBtn').forEach(btn => {
  btn.addEventListener('click', function () {
    document.getElementById('editId').value = this.dataset.id;
    document.getElementById('editDetails').value = this.dataset.details;
  });
});