const viewModal = document.getElementById('viewModal');
viewModal.addEventListener('show.bs.modal', function (event) {
    const button = event.relatedTarget;
    document.getElementById('modal-fullname').textContent = button.getAttribute('data-fullname');
    document.getElementById('modal-certificate').textContent = button.getAttribute('data-certificate');
    document.getElementById('modal-tracking-number').textContent = button.getAttribute('data-tracking-number');
    document.getElementById('modal-selected-date').textContent = button.getAttribute('data-selected-date');
    document.getElementById('modal-selected-time').textContent = button.getAttribute('data-selected-time');
    document.getElementById('modal-purpose').textContent = button.getAttribute('data-purpose');
    document.getElementById('modal-additional-details').textContent = button.getAttribute('data-additional-details');
    document.getElementById('modal-created-at').textContent = button.getAttribute('data-created-at');
});

