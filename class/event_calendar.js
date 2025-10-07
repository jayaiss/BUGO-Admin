
const title = document.getElementById("calendar-title");
const daysContainer = document.getElementById("calendar-days");
const prevButton = document.getElementById("prev-month");
const nextButton = document.getElementById("next-month");
const yearSelect = document.getElementById("year-select");

const eventDetailsContainer = document.getElementById("event-details");
const selectedDateElement = document.getElementById("selected-date");

// Reference to the "Add Event" button container
const addEventBtnContainer = document.getElementById("add-event-btn-container");

let currentDate = new Date();
let selectedDate = null;
let eventsData = {};  // Object to store events by date (in MM/DD/YYYY format)

function generateYearOptions() {
  const currentYear = currentDate.getFullYear();
  for (let i = currentYear; i <= currentYear + 100; i++) {
    const option = document.createElement("option");
    option.value = i;
    option.textContent = i;
    if (i === currentYear) {
      option.selected = true;
    }
    yearSelect.appendChild(option);
  }
}

function getFormattedDate(date) {
  const options = { year: 'numeric', month: 'long', day: 'numeric' };
  return date.toLocaleDateString(undefined, options);
}

function renderCalendar(date) {
  daysContainer.innerHTML = "";
  const year = date.getFullYear();
  const month = date.getMonth();

  title.textContent = `${date.toLocaleString("default", { month: "long" })} ${year}`;

  const firstDay = new Date(year, month, 1).getDay();
  const lastDay = new Date(year, month + 1, 0).getDate();

  const adjustedFirstDay = (firstDay === 0) ? 6 : firstDay - 1;

  for (let i = 0; i < adjustedFirstDay; i++) {
    const emptyCell = document.createElement("div");
    emptyCell.classList.add("day-cell", "empty-cell");
    daysContainer.appendChild(emptyCell);
  }

  for (let day = 1; day <= lastDay; day++) {
    const dayCell = document.createElement("div");
    dayCell.classList.add("day-cell", "border");

    const isToday = 
      day === new Date().getDate() && 
      month === new Date().getMonth() && 
      year === new Date().getFullYear();

    if (isToday) dayCell.classList.add("today");

    dayCell.textContent = day;

    // Format the current day as MM/DD/YYYY for event checking
    const formattedDate = (month + 1).toString().padStart(2, '0') + '/' +
                          day.toString().padStart(2, '0') + '/' + 
                          year;

    // Check if the current date has an event
    if (eventsData[formattedDate]) {
      dayCell.classList.add("event-day");
      const eventIndicator = document.createElement("div");
      eventIndicator.classList.add("event-indicator");
      dayCell.appendChild(eventIndicator);
    }

    // Handle when a user selects a date from the calendar
    dayCell.addEventListener("click", () => {
        // Store the selected date in a variable
        selectedDate = new Date(year, month, day);
        selectedDateElement.textContent = getFormattedDate(selectedDate);

        // Format the selected date as MM/DD/YYYY (e.g., 12/05/2024)
        const formattedDate = (selectedDate.getMonth() + 1).toString().padStart(2, '0') + '/' +
                              selectedDate.getDate().toString().padStart(2, '0') + '/' +
                              selectedDate.getFullYear();

        // Log the formatted date to console (for debugging purposes)
        console.log("Selected Date:", formattedDate);

        // Store the formatted date in a hidden global variable (for form submission)
        window.selectedEventDate = formattedDate;

        // Show the "Add Event" button after selecting a date
        addEventBtnContainer.style.display = "block";

        // Fetch and display event details for the selected date
        fetchEventDetails(formattedDate);
    });

    daysContainer.appendChild(dayCell);
  }
}

// Function to fetch events from the database
function fetchEvents() {
  fetch('get_events.php')  // This URL should point to your PHP endpoint that fetches events
    .then(response => response.json())
    .then(data => {
      // Populate the eventsData object with the event data from the server
      data.forEach(event => {
        // Assuming event.event_date is in MM/DD/YYYY format
        eventsData[event.event_date] = {
          event_title: event.event_title,
          event_description: event.event_description,
          event_location: event.event_location,
          event_time: event.event_time,
        };
      });

      // Once events are fetched, render the calendar
      renderCalendar(currentDate);
    })
    .catch(error => {
      console.error("Error fetching events:", error);
    });
}


// Function to fetch event details dynamically
function fetchEventDetails(eventDate) {
  fetch(`class/get_event_details.php?event_date=${eventDate}`)
    .then(response => response.json())
    .then(data => {
      if (data.event_title) {
        // If event found, display event details
        eventDetailsContainer.innerHTML = `
          <h2>${data.event_title}</h2>
          <p><strong>Description:</strong> ${data.event_description}</p>
          <p><strong>Location:</strong> ${data.event_location}</p>
          <p><strong>Time:</strong> ${data.event_time}</p>
        `;
      } else {
        // If no event found, display a message
        eventDetailsContainer.innerHTML = `<p>No event details found.</p>`;
      }
    })
    .catch(error => {
      console.error("Error fetching event details:", error);
      eventDetailsContainer.innerHTML = "<p>Error loading event details. Please try again.</p>";
    });
}

prevButton.addEventListener("click", () => {
  currentDate.setMonth(currentDate.getMonth() - 1);
  renderCalendar(currentDate);
});

nextButton.addEventListener("click", () => {
  currentDate.setMonth(currentDate.getMonth() + 1);
  renderCalendar(currentDate);
});

yearSelect.addEventListener("change", () => {
  const selectedYear = parseInt(yearSelect.value);
  currentDate.setFullYear(selectedYear);
  renderCalendar(currentDate);
});

generateYearOptions();
fetchEvents();  // Fetch events from the database when the page loads
renderCalendar(currentDate);

// Function to convert 24-hour time to 12-hour format with AM/PM
function convertTo12HourFormat(time) {
    let [hours, minutes] = time.split(":");
    hours = parseInt(hours);  // Convert string to number
    
    const period = hours >= 12 ? "PM" : "AM";
    hours = hours % 12 || 12;  // Convert hours to 12-hour format
    minutes = minutes.padStart(2, "0");  // Ensure minutes are two digits
  
    return `${hours}:${minutes} ${period}`;
}

// Function to format date as MM/DD/YYYY
function formatDateToMMDDYYYY(date) {
  const month = String(date.getMonth() + 1).padStart(2, '0');  // Month is 0-indexed, so add 1
  const day = String(date.getDate()).padStart(2, '0');  // Ensure day is two digits
  const year = date.getFullYear();
  
  return `${month}/${day}/${year}`;
}

// Handle Add Event form submission
const saveEventBtn = document.getElementById("save-event-btn");

saveEventBtn.addEventListener("click", (event) => {
  event.preventDefault();
  
  const title = document.getElementById("event-title-input").value;
  const description = document.getElementById("event-description-input").value;
  const location = document.getElementById("event-location-input").value;
  const time24hr = document.getElementById("event-time-input").value;  // Get the 24-hour time input
  const eventDate = selectedDate;  // Get the selected date (from calendar)
  
  // Convert 24-hour time to 12-hour format with AM/PM
  const time12hr = convertTo12HourFormat(time24hr);
  
  // Format the selected date to MM/DD/YYYY
  const formattedDate = formatDateToMMDDYYYY(eventDate);

  // Display confirmation dialog
  const isConfirmed = confirm(`Are you sure you want to add the event "${title}"?\n\nDescription: ${description}\nLocation: ${location}\nTime: ${time12hr}\nDate: ${formattedDate}`);

  if (isConfirmed) {
    // If the user confirms, proceed to save the event
    const formData = new FormData();
    formData.append('event_title', title);
    formData.append('event_description', description);
    formData.append('event_location', location);
    formData.append('event_time', time12hr);  // Send the time in 12-hour format
    formData.append('event_date', formattedDate);  // Send the date in MM/DD/YYYY format

    fetch('class/save_event.php', {
      method: 'POST',
      body: formData
    })
    .then(response => response.text())
    .then(data => {
      console.log("Event saved:", data);
      const modal = new bootstrap.Modal(document.getElementById("addEventModal"));
      modal.hide();

      // Reload the page after event is saved
      window.location.reload(); // Reloads the page to reflect changes
    })
    .catch(error => {
      console.error("Error saving event:", error);
      alert("There was an error saving the event. Please try again.");
    });
  } else {
    // If the user cancels, do nothing
    console.log("Event not saved.");
  }
});
