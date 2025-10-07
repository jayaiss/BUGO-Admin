/*!
    * Start Bootstrap - SB Admin v7.0.7 (https://startbootstrap.com/template/sb-admin)
    * Copyright 2013-2023 Start Bootstrap
    * Licensed under MIT (https://github.com/StartBootstrap/startbootstrap-sb-admin/blob/master/LICENSE)
    */
    // 
// Scripts
// 

window.addEventListener('DOMContentLoaded', event => {

    // Toggle the side navigation
    const sidebarToggle = document.body.querySelector('#sidebarToggle');
    if (sidebarToggle) {
        // Uncomment Below to persist sidebar toggle between refreshes
        // if (localStorage.getItem('sb|sidebar-toggle') === 'true') {
        //     document.body.classList.toggle('sb-sidenav-toggled');
        // }
        sidebarToggle.addEventListener('click', event => {
            event.preventDefault();
            document.body.classList.toggle('sb-sidenav-toggled');
            localStorage.setItem('sb|sidebar-toggle', document.body.classList.contains('sb-sidenav-toggled'));
        });
    }

});
$(document).ready(function () {
    // Show the login modal
    $('a[data-target="#loginModal"]').on('click', function (e) {
        e.preventDefault();
        $('#loginModal').fadeIn();
    });

    // Close the login modal
    $('#closeLoginModal').on('click', function () {
        $('#loginModal').fadeOut();
    });

    // Handle form submission with AJAX
    $('#loginForm').on('submit', function (e) {
        e.preventDefault(); // Prevent form submission

        const formData = $(this).serialize(); // Get form data
        $('#errorMessage').hide(); // Hide any previous error messages

        $.ajax({
            url: 'validate_login.php',
            method: 'POST',
            data: formData,
            dataType: 'json',
            success: function (response) {
                if (response.status === 'error') {
                    // Show the error message and clear the fields
                    $('#errorMessage').text(response.message).show();
                    $('#username').val(''); // Clear the username field
                    $('#password').val(''); // Clear the password field
                } else if (response.status === 'redirect') {
                    window.location.href = response.url;
                }
            },
            error: function () {
                $('#errorMessage').text('An error occurred. Please try again.').show();
            },
        });
    });
});
