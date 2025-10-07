function debounce(func, delay) {
    let timeoutId;
    return function(...args) {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => func.apply(this, args), delay);
    };
}

function fetchSearchResults() {
    const query = document.getElementById('searchInput').value.trim();
    const tbody = document.getElementById('appointmentTableBody');

    fetch('./Search/search_appointments.php?q=' + encodeURIComponent(query))
        .then(response => response.text())
        .then(data => {
            tbody.innerHTML = data;
        })
        .catch(error => {
            console.error("Error fetching search results:", error);
        });
}

const debouncedSearch = debounce(fetchSearchResults, 500); // Adjust delay if needed

document.getElementById('searchInput').addEventListener('input', debouncedSearch);