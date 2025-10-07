function printAppointment(certificate, fullname, res_zone, birth_date = "", birth_place = "", res_street_address = "", purpose = "", residency_start = "") {
    let printAreaContent = "";

    // Check the certificate type and set the corresponding content
    if (certificate === "Postal ID Application") {
        printAreaContent = `
            <html>
                <head>
                    <link rel="stylesheet" href="css/form.css">
                </head>
                <body>
                    <div class="container" id="printArea">
                        <header>
                            <div class="logo-header">
                                <img src="assets/logo/bugo_logo.png" alt="Barangay Logo" class="logo">
                                <div class="header-text">
                                    <h2 style="font-family: cursive;"><strong>Republic of the Philippines</strong></h2>
                                    <h3><strong>CITY OF CAGAYAN DE ORO</strong></h3>
                                    <h3><strong>BARANGAY BUGO</strong></h3>
                                    <h2><strong>OFFICE OF THE PUNONG BARANGAY</strong></h2>
                                </div>
                                <img src="assets/logo/cdo_logo.png" alt="City Logo" class="logo">
                            </div>
                        </header>

                        <section class="barangay-certification">
                            <h4 style="text-align: center; font-size: 50px;"><strong>CERTIFICATION</strong></h4>
                            <br>
                            <p>TO WHOM IT MAY CONCERN:</p>
                            <br>
                            <p>THIS IS TO CERTIFY that <strong>${fullname}</strong>, a resident of 
                            <strong>${res_zone}</strong>,  <strong>${res_street_address}</strong>,Bugo, Cagayan de Oro City.</p>
                            <br>
                            <p>This Certification is issued upon the request of the above-mentioned person 
                                for <strong>POSTAL I.D. APPLICATION</strong> only.</p>
                            <br>
                            <p>Issued this __________ day of ____________, 2024, at Barangay Bugo, Cagayan de Oro City.</p>
                        </section>
                        <br>
                        <section class="additional-details">
                            <p><strong>Community Tax No.:</strong> ___________________________</p>
                            <p><strong>Issued on:</strong> ___________________________</p>
                            <p><strong>Issued at:</strong> ___________________________</p>
                        </section>
                        <section class="signature-section">
                            <div class="sign">
                                <h5 class="signature-fname"><strong><u>SPENCER L. CAILING</u></strong></h5>
                                <p class="signature-name"><strong>Punong Barangay</strong></p>
                            </div>
                        </section>
                    </div>
                </body>
            </html>
        `;
    } else if (certificate === "Senior Citizen Membership Application") {
        printAreaContent = `
            <html>
                <head>
                    <link rel="stylesheet" href="css/form.css">
                </head>
                <body>
                    <div class="container" id="printArea">
                        <header>
                            <div class="logo-header">
                                <img src="assets/logo/bugo_logo.png" alt="Barangay Logo" class="logo">
                                <div class="header-text">
                                    <h2 style="font-family: cursive;"><strong>Republic of the Philippines</strong></h2>
                                    <h3><strong>CITY OF CAGAYAN DE ORO</strong></h3>
                                    <h3><strong>BARANGAY BUGO</strong></h3>
                                    <h2><strong>OFFICE OF THE PUNONG BARANGAY</strong></h2>
                                    <p>Tel No.: (08822) 742597: Telefax No.: (088) 8554246</p>
                                    <p class="mails">Mailto: eikano@rocketmail.com or ong11kie@yahoo.com</p>
                                </div>
                                <img src="assets/logo/cdo_logo.png" alt="City Logo" class="logo">
                            </div>
                        </header>
                        <hr class="header-line">
                        <section class="barangay-certification">
                            <h4 style="text-align: center; font-size: 50px;"><strong>CERTIFICATION</strong></h4>
                            <p>TO WHOM IT MAY CONCERN:</p>
                            <br>
                            <p>THIS IS TO CERTIFY that <strong>${fullname}</strong>, is a resident of 
                            <strong>${res_zone}</strong>, <strong>${res_street_address}</strong>  Bugo, Cagayan de Oro City. He/She was born on <strong>${birth_date}</strong> at <strong>${birth_place}</strong>. 
                                Stayed in Bugo, CDOC since <strong>${residency_start}</strong> and up to present.</p>
                            <br>
                            <p>This Certification is issued upon the request of the above-mentioned person 
                                for <strong>SENIOR CITIZEN MEMBERSHIP APPLICATION</strong> only.</p>
                            <br>
                            <p>Issued this __________ day of ____________, 2024, at Barangay Bugo, Cagayan de Oro City.</p>
                        </section>
                        <br>
                        <section class="additional-details">
                            <p><strong>Community Tax No.:</strong> ___________________________</p>
                            <p><strong>Issued on:</strong> ___________________________</p>
                            <p><strong>Issued at:</strong> ___________________________</p>
                        </section>
                        <section class="signature-section">
                            <div class="sign">
                                <h5 class="signature-fname"><strong><u>SPENCER L. CAILING</u></strong></h5>
                                <p class="signature-name"><strong>Punong Barangay</strong></p>
                            </div>
                        </section>
                    </div>
                </body>
            </html>
        `;
    } else if (certificate === "Residency") {
        printAreaContent = `
            <html>
                <head>
                    <link rel="stylesheet" href="css/form.css">
                </head>
                <body>
                    <div class="container" id="printArea">
                        <header>
                            <div class="logo-header">
                                <img src="assets/logo/bugo_logo.png" alt="Barangay Logo" class="logo">
                                <div class="header-text">
                                    <h2 style="font-family: cursive;"><strong>Republic of the Philippines</strong></h2>
                                    <h3><strong>CITY OF CAGAYAN DE ORO</strong></h3>
                                    <h3><strong>BARANGAY BUGO</strong></h3>
                                    <h2><strong>OFFICE OF THE PUNONG BARANGAY</strong></h2>
                                </div>
                                <img src="assets/logo/cdo_logo.png" alt="City Logo" class="logo">
                            </div>
                            <hr class="header-line">
                        </header>
                        <section class="barangay-certification">
                            <h4 style="text-align: center;font-size: 50px;"><strong>CERTIFICATION</strong></h4>
                            <p>TO WHOM IT MAY CONCERN:</p>
                            <br>
                            <p>THIS IS TO CERTIFY that <strong>${fullname}</strong>, is a resident of 
                            <strong>${purok}</strong>, Bugo, Cagayan de Oro City.</p>
                            <br>
                            <p>This certify further that according to and as reported by ___________________________________ 
                                he/she has been at the said area since <strong>${residency_start}</strong> up to present.</p>
                            <br>
                            <p>This certification is issued upon the request of the above-mentioned person for 
                                <strong>${purpose}</strong>.</p>
                            <br>
                            <p>Issued this __________ day of ____________, 2024, at Barangay Bugo, Cagayan de Oro City.</p>
                        </section>
                        <br>
                        <section class="additional-details">
                            <p><strong>Community Tax No.:</strong> ___________________________</p>
                            <p><strong>Issued on:</strong> ___________________________</p>
                            <p><strong>Issued at:</strong> ___________________________</p>
                        </section>
                        <section class="signature-section">
                            <div class="sign">
                                <h5 class="signature-fname"><strong><u>SPENCER L. CAILING</u></strong></h5>
                                <p class="signature-name"><strong>Punong Barangay</strong></p>
                            </div>
                        </section>
                    </div>
                </body>
            </html>
        `;
    } else if (certificate === "Barangay Clearance") {
    printAreaContent = `
       <html>
    <head>
        <link rel="stylesheet" href="css/clearance.css">
    </head>
    <body>
        <div class="container" id="printArea">
            <header>
                <div class="logo-header">
                    <img src="assets/logo/bugo_logo.png" alt="Barangay Logo" class="logo">
                    <div class="header-text" style="text-align: center;">
                        <h3><strong>Republic of the Philippines</strong></h3>
                        <h4><strong>CITY OF CAGAYAN DE ORO</strong></h4>
                        <h4><strong>BARANGAY BUGO</strong></h4>
                        <h3><strong>OFFICE OF THE PUNONG BARANGAY</strong></h3>
                        <p>Tel No.: 3093227; (088) 855-4246</p>
                    </div>
                    <img src="assets/logo/cdo_logo.png" alt="City Logo" class="logo">
                </div>
                <section style="text-align: center; margin-top: 10px;">
                    <hr class="header-line" style="border: 1px solid black; margin-top: 10px;">
                    <h2 style="font-size: 30px;"><strong>BARANGAY CLEARANCE</strong></h2>
                    <br>
                </section>
                <section style="display: flex; justify-content: space-between; margin-top: 10px;">
                    <!-- Left side empty or other content (if needed) -->
                    <div style="flex: 1;"></div>
                    <!-- Right side Control No. -->
                    <div style="text-align: right; flex: 1;">
                        <p><strong>Control No.</strong> _________________ <br>Series of 2024</p>
                    </div>
                </section>
            </header>

            <div class="side-by-side">
                <!-- Left Section: Council Members -->
                <div class="left-content">
                    <div class="council-box">
                        <h1>18<sup>th</sup> COUNCIL</h1><br>
                        <div class="official-title">
                            <span>Punong Barangay</span>
                            <strong><u>SPENCER L. CAILING</u></strong>

                            <span>Brgy. Kagawad</span>
                            <strong><u>MONICO R. CAPIRIG</u></strong>
                            
                            <span>Brgy. Kagawad</span>
                            <strong><u>NOEL CHE G. GUEVARA</u></strong>

                            <span>Brgy. Kagawad</span>
                            <strong><u>NOLI P. YAGAO</u></strong>

                            <span>Brgy. Kagawad</span>
                            <strong><u>ARIEL V. IGOT</u></strong>

                            <span>Brgy. Kagawad</span>
                            <strong><u>EDWIN V. ABAN</u></strong>

                            <span>Brgy. Kagawad</span>
                            <strong><u>RAUL M. ALERIA</u></strong>

                            <span>Brgy. Kagawad</span>
                            <strong><u>PRESLEY C. EMANO</u></strong>

                            <span>SK Chairman</span>
                            <strong><u>CLINT RUSSEL P. DOSAL</u></strong>

                          <span>Brgy. Secretary</span>
                            <strong><u>EMILOR J. CABANOS</u></strong>
  
                            <span>Brgy. Treasurer</span>
                            <strong><u>MARIANN J. DITAN</u></strong>

                        </div>
                    </div>
                </div>

                <!-- Right Section: Certification Text -->
                <div class="right-content">
                    <p>TO WHOM IT MAY CONCERN:</p>
                    <p>THIS IS TO CERTIFY that <strong>________________________</strong>, legal age, <strong>[ ] Single [ ] Married [ ] Widow</strong>, 
                    Filipino citizen, is a resident of Barangay Bugo, this City, particularly in <strong>________________________</strong>.</p><br>
                    <p>FURTHER CERTIFIES that the above-named person is known to be a person of good moral character and reputation as far as this office is concerned.
                    He/She has no pending case filed and blottered before this office.</p><br>
                    <p>This certification is being issued upon the request of the above-named person, in connection with his/her desire <strong>________________________</strong>.</p><br>

                    <!-- New Section Added Below -->
                    <p>Given this __________ day of ________________, 2025 at Barangay Bugo, Cagayan de Oro City.</p>
                    <br>
                    <div style="text-align: center;">
                        <p>_______________________________</p>
                        <p>AFFIANT SIGNATURE</p>
                    </div>

                    <div style="display: flex; justify-content: space-between; margin-top: 70px;">
                        <section style="width: 48%;">
                            <p><strong>As per records (LUPON TAGAPAMAYAPA):</strong></p>
                            <p>Brgy. Case #: ___________________________</p>
                            <p>Certified by: ___________________________</p>
                            <p>Date: ___________________________</p>
                        </section>

                        <section style="width: 48%;">
                            <p><strong>As per records (BARANGAY TANOD):</strong></p>
                            <p>Brgy. Tanod Remarks: _____________________</p>
                            <p>Certified by: ___________________________</p>
                            <p>Date: ___________________________</p>
                        </section>
                    </div>
                    
                </div>
            </div>

            <!-- Thumbprint Section Below Left Content -->
            <section style="margin-top: 20px; text-align: center;">
                <div style="display: flex; justify-content: left; gap: 20px;">
                    <!-- Left Thumb Box with Label Above -->
                    <div style="text-align: center; font-size:6px;" >
                        <p><strong>Left Thumb:</strong></p>
                        <div style="border: 1px solid black; width: 60px; height: 60px; display: flex; justify-content: center; align-items: center;">
                        </div>
                    </div>

                    <!-- Right Thumb Box -->
                    <div style="text-align: center; font-size:6px;">
                        <p><strong>Right Thumb:</strong></p>
                        <div style="border: 1px solid black; width: 60px; height: 60px; display: flex; justify-content: center; align-items: center;">
                        </div>
                    </div>
                </div>
            </section>

            <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
                <section style="width: 48%; line-height: 1.8;">
                    <p><strong>Community Tax No.:</strong> ___________________________</p>
                    <p><strong>Issued on:</strong> ___________________________</p>
                    <p><strong>Issued at:</strong> ___________________________</p>
                </section>
                <section style="width: 48%; text-align: center; font-size: 18px;">
                    <h5 style="text-decoration: underline;"><strong>SPENCER L. CAILING</strong></h5>
                    <p>Punong Barangay</p>
                </section>
            </div>
        </div>
    </body>
</html>


    `;
}

    // Open a new print window with the content
    const printWindow = window.open('', '_blank');
    printWindow.document.write(printAreaContent);
    printWindow.document.close();

    // Wait for the document to load, then print
    printWindow.onload = function () {
        printWindow.print();
    };
}



