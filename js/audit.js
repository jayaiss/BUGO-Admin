function showDetails(logID, id, logPath) {
    fetch('./logs/logs_trig.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `logID=${logID}&id=${id}&logPath=${logPath}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.error) {
            console.error(data.error);
            alert(data.error);
            return;
        }

        const oldData = data.old || {};
        const newData = data.new || {};
        const modalId = `auditModal-${id}-${logPath}`;

        if (document.getElementById(modalId)) {
            document.getElementById(modalId).remove();
        }

        let tableRows = '';
        switch(parseInt(logPath)) {
            case 1:
                tableRows = generateEmployeeRows(oldData, newData);
                break;
            case 2:
                tableRows = generateResidentRows(oldData, newData);
                break;
            case 3:
                tableRows = generateSchedulesRows(oldData, newData);
                break;
        }

        const modalHTML = `
            <div class="auditModal" id="${modalId}">
                <div class="auditModal-content">
                    <div class="auditModal-header">
                        <h3>Details for Log ID: ${logPath}</h3>
                        <span class="auditModal-close" onclick="closeModal('${modalId}')">&times;</span>
                    </div>
                    <div class="auditModal-body">
                        <table class="auditModal-table">
                            <thead>
                                <tr>
                                    <th>Field</th>
                                    <th>Old Value</th>
                                    <th>New Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${tableRows}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>`;

        document.body.insertAdjacentHTML('beforeend', modalHTML);
        document.getElementById(modalId).style.display = 'block';
    })
    .catch(error => {
        console.error("Fetch Error:", error);
        alert('An error occurred while processing your request. Please try again.');
    });
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
        modal.remove();
    }
}

window.onclick = function(event) {
    if (event.target.classList.contains('auditModal')) {
        event.target.style.display = 'none';
        event.target.remove();
    }
}

function generateEmployeeRows(oldData, newData) {
    return `
        <tr class="auditModal-section-header"><td colspan="3">Employee Information</td></tr>
        <tr>
            <td>Employee ID</td>
            <td>${oldData.employee_id || ''}</td>
            <td>${newData.employee_id || ''}</td>
        </tr>
        <tr>
            <td>First Name</td>
            <td>${oldData.employee_fname || ''}</td>
            <td>${newData.employee_fname || ''}</td>
        </tr>
        <tr>
            <td>Middle Name</td>
            <td>${oldData.employee_mname || ''}</td>
            <td>${newData.employee_mname || ''}</td>
        </tr>
        <tr>
            <td>Last Name</td>
            <td>${oldData.employee_lname || ''}</td>
            <td>${newData.employee_lname || ''}</td>
        </tr>
         <tr>
            <td>Last Name</td>
            <td>${oldData.employee_sname || ''}</td>
            <td>${newData.employee_sname || ''}</td>
        </tr>
        <tr class="auditModal-section-header"><td colspan="3">Employment Details</td></tr>
        <tr>
            <td>Date Hired</td>
            <td>${oldData.created_at || ''}</td>
            <td>${newData.created_at || ''}</td>
        </tr>
        <tr>
            <td>Role</td>
            <td>${oldData.Role_Id || ''}</td>
            <td>${newData.Role_Id || ''}</td>
        </tr>
        <tr class="auditModal-section-header"><td colspan="3">Personal Information</td></tr>
        <tr>
            <td>Birthday</td>
            <td>${oldData.employee_birth_date || ''}</td>
            <td>${newData.employee_birth_date || ''}</td>
        </tr>
        <tr>
            <td>Gender</td>
            <td>${oldData.employee_gender || ''}</td>
            <td>${newData.employee_gender || ''}</td>
        </tr>
        <tr>
            <td>Marital Status</td>
            <td>${oldData.employee_civil_status || ''}</td>
            <td>${newData.employee_civil_status || ''}</td>
        </tr>
        <tr>
            <td>Nationality</td>
            <td>${oldData.employee_citizenship || ''}</td>
            <td>${newData.employee_citizenship || ''}</td>
        </tr>
        <tr class="auditModal-section-header"><td colspan="3">Contact Information</td></tr>
        <tr>
            <td>Phone</td>
            <td>${oldData.employee_contact_number || ''}</td>
            <td>${newData.employee_contact_number || ''}</td>
        </tr>
        <tr>
            <td>Email</td>
            <td>${oldData.employee_email || ''}</td>
            <td>${newData.employee_email || ''}</td>
        </tr>
        <tr>
            <td>Username</td>
            <td>${oldData.employee_username || ''}</td>
            <td>${newData.employee_username || ''}</td>
        </tr>
        <tr class="auditModal-section-header"><td colspan="3">Address Information</td></tr>
        <tr>
            <td>Country</td>
            <td>${oldData.employee_province || ''}</td>
            <td>${newData.employee_province || ''}</td>
        </tr>
        <tr>
            <td>City</td>
            <td>${oldData.employee_city_municipality || ''}</td>
            <td>${newData.employee_city_municipality || ''}</td>
        </tr>
        <tr>
            <td>Barangay</td>
            <td>${oldData.employee_barangay || ''}</td>
            <td>${newData.employee_barangay || ''}</td>
        </tr>
        <tr>
            <td>Zone</td>
            <td>${oldData.employee_zone || ''}</td>
            <td>${newData.employee_zone || ''}</td>
        </tr>
        <tr>
            <td>Street</td>
            <td>${oldData.employee_street_address || ''}</td>
            <td>${newData.employee_street_address || ''}</td>
        </tr>`;
}

function generateResidentsRows(oldData, newData) {
    return `
        <tr class="auditModal-section-header"><td colspan="3">Personal Information</td></tr>
        <tr>
            <td>First Name</td>
            <td>${oldData.first_name || ''}</td>
            <td>${newData.first_name || ''}</td>
        </tr>
        <tr>
            <td>Middle Name</td>
            <td>${oldData.middle_name || ''}</td>
            <td>${newData.middle_name || ''}</td>
        </tr>
        <tr>
            <td>Last Name</td>
            <td>${oldData.last_name || ''}</td>
            <td>${newData.last_name || ''}</td>
        </tr>
        <tr>
            <td>Suffix Name</td>
            <td>${oldData.suffix_name || ''}</td>
            <td>${newData.suffix_name || ''}</td>
        </tr>
        <tr>
            <td>Birthday</td>
            <td>${oldData.birth_date || ''}</td>
            <td>${newData.birth_date || ''}</td>
        </tr>
        <tr class="auditModal-section-header"><td colspan="3">Contact Information</td></tr>
        <tr>
            <td>Contact Number</td>
            <td>${oldData.contact_number || ''}</td>
            <td>${newData.contact_number || ''}</td>
        </tr>
        <tr>
            <td>Email</td>
            <td>${oldData.email || ''}</td>
            <td>${newData.email || ''}</td>
        </tr>
        <tr class="auditModal-section-header"><td colspan="3">Address Information</td></tr>
        <tr>
            <td>Street</td>
            <td>${oldData.res_street_address || ''}</td>
            <td>${newData.res_street_address || ''}</td>
        </tr>
        <tr>
            <td>Barangay</td>
            <td>${oldData.res_barangay || ''}</td>
            <td>${newData.res_barangay || ''}</td>
        </tr>`;
}

function generateSchedulesRows(oldData, newData) {
    return `
        <tr class="auditModal-section-header"><td colspan="3">Personal Information</td></tr>
        <tr>
            <td>First Name</td>
            <td>${oldData.Comp_First_Name || ''}</td>
            <td>${newData.Comp_First_Name || ''}</td>
        </tr>
        <tr>
            <td>Middle Name</td>
            <td>${oldData.Comp_Middle_Name || ''}</td>
            <td>${newData.Comp_Middle_Name || ''}</td>
        </tr>
        <tr>
            <td>Last Name</td>
            <td>${oldData.Comp_Last_Name || ''}</td>
            <td>${newData.Comp_Last_Name || ''}</td>
        </tr>
        <tr>`;
}

function generateCaseRows(oldData, newData) {
    return `
        <tr class="auditModal-section-header"><td colspan="3">Case Information</td></tr>
        <tr>
            <td>Case Number</td>
            <td>${oldData['Case Number'] || ''}</td>
            <td>${newData['Case Number'] || ''}</td>
        </tr>
        <tr>
            <td>Case Complainant</td>
            <td>${oldData['Case Complainant'] || ''}</td>
            <td>${newData['Case Complainant'] || ''}</td>
        </tr>
        <tr>
            <td>Crime Nature</td>
            <td>${oldData['Nature Offense'] || ''}</td>
            <td>${newData['Nature Offense'] || ''}</td>
        </tr>
        <tr>
            <td>Case Respondent</td>
            <td>${oldData['Case Respondent'] || ''}</td>
            <td>${newData['Case Respondent'] || ''}</td>
        </tr>
        <tr>
            <td>Date Filed</td>
            <td>${oldData['Date Filed'] || ''}</td>
            <td>${newData['Date Filed'] || ''}</td>
        </tr>
        <tr>
            <td>Case First Hearing</td>
            <td>${oldData['Case Hearing'] || ''}</td>
            <td>${newData['Case Hearing'] || ''}</td>
        </tr>
        <tr>
            <td>Case Status</td>
            <td>${oldData['Case Status'] || ''}</td>
            <td>${newData['Case Status'] || ''}</td>
        </tr>`;
}





// The printing functionality
function printTable() {

    var printWindow = window.open('', '_blank');
  

    if (printWindow) {

      printWindow.document.open();
      printWindow.document.write('<html><head><title>Barangay Bugo</title>');
      printWindow.document.write('<style>');
      printWindow.document.write('body { font-size: 12px; font-family: Arial, sans-serif; }');
      printWindow.document.write('table { width: 100%; margin-top: 10px; border-collapse: collapse; }');
      printWindow.document.write('td { font-size: 12px; padding: 8px; text-align: left; }');
      printWindow.document.write('.centered-text { text-align: center; margin-top: 40px; margin-bottom: 40px; }');
      printWindow.document.write('</style>');
      printWindow.document.write('</head><body>');
      

      printWindow.document.write('<h3 class="centered-text">Crime Database System</h3>');
      

      printWindow.document.write('<table>');
      
      var headerText = "<tr><td><strong>Log ID</strong></td><td><strong>Logs Name</strong></td><td><strong>Name</strong></td><td><strong>Roles</strong></td><td><strong>Action Made</strong></td><td><strong>Action By</strong></td><td><strong>Time & Date</strong></td></tr>";
      printWindow.document.write(headerText); 
      
      var tableRows = document.querySelectorAll('#hidden-table-info tbody tr');
  
      tableRows.forEach(function(row) {
        var tds = row.querySelectorAll('td');
        var rowHtml = '<tr>';
        tds.forEach(function(td, index) {

          if (index !== 7) {
            rowHtml += '<td>' + td.innerHTML + '</td>';
          }
        });
        rowHtml += '</tr>';
        printWindow.document.write(rowHtml);
      });
      
      printWindow.document.write('</table>');
      printWindow.document.write('</body></html>');
      
      printWindow.document.close();
      
      printWindow.onload = function() {
        printWindow.print();
      };
    } else {
      console.error('Failed to open print window');
    }
  }
  document.addEventListener('DOMContentLoaded', function () {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.forEach(function (tooltipTriggerEl) {
      new bootstrap.Tooltip(tooltipTriggerEl);
    });
  });