$(document).ready(function () {
  // --------------------------
  // Case search input (with debounce)
  // --------------------------
  let debounceTimer;
  $("#searchInput").on("input", function () {
    clearTimeout(debounceTimer);
    const searchTerm = $(this).val();
    debounceTimer = setTimeout(() => {
      loadCases(searchTerm, 1);
    }, 500);
  });

  $("#searchInput").on("keypress", function (e) {
    if (e.which == 13) {
      loadCases($(this).val(), 1);
    }
  });

  // Initial load
  loadCases();

  // --------------------------
  // Load cases via AJAX
  // --------------------------
  function loadCases(search = "", page = 1) {
    $.ajax({
      url: "Search/case_search.php",
      method: "GET",
      data: { search: search, pagenum: page },
      success: function (response) {
        let res = typeof response === "string" ? JSON.parse(response) : response;
        let tableBody = $("#residentTableBody");
        tableBody.empty();

        if (!res.cases || res.cases.length === 0) {
          tableBody.append(
            '<tr><td colspan="9" class="text-center">No cases found.</td></tr>'
          );
        } else {
          res.cases.forEach((row) => {
            const viewBtn = `
              <button class="btn btn-sm btn-outline-primary view-details-btn" 
                data-bs-toggle="modal" 
                data-bs-target="#viewCaseModal"
                data-case='${JSON.stringify(row)}'
                title="View">
                <i class="bi bi-eye"></i>
              </button>`;

            let actionColumn = "";
            if ((userRole || "").toLowerCase() === "punong barangay") {
              actionColumn = `<div class="d-flex gap-1">${viewBtn}</div>`;
            } else {
              actionColumn = `
                <div class="d-flex gap-1">
                  ${viewBtn}
                  <button class="btn btn-sm btn-outline-success update-status-btn" 
                    data-bs-toggle="modal" 
                    data-bs-target="#statusModal"
                    data-case-number="${row.case_number}" 
                    data-current-action="${row.action_taken}"
                    title="Update Status">
                    <i class="bi bi-pencil-square"></i>
                  </button>
                </div>`;
            }

            const compFull = `${row.Comp_First_Name || ""} ${row.Comp_Middle_Name || ""} ${row.Comp_Last_Name || ""} ${row.Comp_Suffix_Name || ""}`.replace(/\s+/g, " ").trim();
            const respFull = `${row.Resp_First_Name || ""} ${row.Resp_Middle_Name || ""} ${row.Resp_Last_Name || ""} ${row.Resp_Suffix_Name || ""}`.replace(/\s+/g, " ").trim();

            tableBody.append(`
              <tr>
                <td>${row.case_number}</td>
                <td>${compFull}</td>
                <td>${respFull}</td>
                <td>${row.nature_offense}</td>
                <td>${row.date_filed}</td>
                <td>${row.time_filed}</td>
                <td>${row.date_hearing}</td>
                <td><span>${row.action_taken || "No status"}</span></td>
                <td>${actionColumn}</td>
              </tr>
            `);
          });
        }

        renderPagination(res.total_pages, res.current_page, search);
      },
    });
  }

  // --------------------------
  // Enhanced Pagination rendering
  // --------------------------
  function renderPagination(totalPages, currentPage, search) {
    let html = "";

    // First
    html += `
      <li class="page-item ${currentPage === 1 ? "disabled" : ""}">
        <button class="page-link" data-page="1" data-search="${encodeURIComponent(search)}" aria-label="First">
          <i class="fa fa-angle-double-left"></i>
        </button>
      </li>`;

    // Prev
    html += `
      <li class="page-item ${currentPage === 1 ? "disabled" : ""}">
        <button class="page-link" data-page="${currentPage - 1}" data-search="${encodeURIComponent(search)}" aria-label="Previous">
          <i class="fa fa-angle-left"></i>
        </button>
      </li>`;

    const windowSize = 5;
    let start = Math.max(1, currentPage - Math.floor(windowSize / 2));
    let end = Math.min(totalPages, start + windowSize - 1);
    if (end - start + 1 < windowSize) start = Math.max(1, end - windowSize + 1);

    // Left ellipsis
    if (start > 1) {
      html += `<li class="page-item disabled"><span class="page-link">…</span></li>`;
    }

    // Page numbers
    for (let i = start; i <= end; i++) {
      html += `
        <li class="page-item ${i === currentPage ? "active" : ""}">
          <button class="page-link" data-page="${i}" data-search="${encodeURIComponent(search)}">${i}</button>
        </li>`;
    }

    // Right ellipsis
    if (end < totalPages) {
      html += `<li class="page-item disabled"><span class="page-link">…</span></li>`;
    }

    // Next
    html += `
      <li class="page-item ${currentPage === totalPages ? "disabled" : ""}">
        <button class="page-link" data-page="${currentPage + 1}" data-search="${encodeURIComponent(search)}" aria-label="Next">
          <i class="fa fa-angle-right"></i>
        </button>
      </li>`;

    // Last
    html += `
      <li class="page-item ${currentPage === totalPages ? "disabled" : ""}">
        <button class="page-link" data-page="${totalPages}" data-search="${encodeURIComponent(search)}" aria-label="Last">
          <i class="fa fa-angle-double-right"></i>
        </button>
      </li>`;

    $(".pagination").html(html);

    // Bind clicks
    $(".pagination .page-link").click(function () {
      const p = $(this).data("page");
      if (p && !$(this).parent().hasClass("disabled")) {
        loadCases($(this).data("search"), p);
      }
    });
  }
});

// --------------------------
// View/Edit Case modal populate
// --------------------------
$(document).on("click", ".view-details-btn", function () {
  const row = $(this).data("case");
  $("#modal_case_number").val(row.case_number);
  $("#Comp_First_Name").val(row.Comp_First_Name || "");
  $("#Comp_Middle_Name").val(row.Comp_Middle_Name || "");
  $("#Comp_Last_Name").val(row.Comp_Last_Name || "");
  $("#Comp_Suffix_Name").val(row.Comp_Suffix_Name || "");
  $("#Resp_First_Name").val(row.Resp_First_Name || "");
  $("#Resp_Middle_Name").val(row.Resp_Middle_Name || "");
  $("#Resp_Last_Name").val(row.Resp_Last_Name || "");
  $("#Resp_Suffix_Name").val(row.Resp_Suffix_Name || "");
  $("#modal_nature_offense").val(row.nature_offense);
  $("#modal_date_filed").val(row.date_filed);
  $("#modal_time_filed").val(row.time_filed);
  $("#modal_date_hearing").val(row.date_hearing);

  $.post("logs/logs_trig.php", { filename: 5, viewedID: row.case_number });
});

// --------------------------
// Update Status modal populate
// --------------------------
$(document).on("click", ".update-status-btn", function () {
  $("#status_case_number").val($(this).data("case-number"));
  $("#status_action_taken").val($(this).data("current-action"));
});

// --------------------------
// Disable save for Punong Barangay
// --------------------------
$("#viewCaseModal").on("show.bs.modal", function () {
  $("#saveChangesBtn").prop(
    "disabled",
    (userRole || "").toLowerCase() === "punong barangay"
  );
});
