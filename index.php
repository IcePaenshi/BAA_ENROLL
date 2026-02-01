<!DOCTYPE html>
<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}
?>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Baesa Adventist Academy</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* Enrollment Form Style */
        .enrollment-page {
            display: none;
            height: 100vh;
            background: #f5f5f0;
            width: 100%;
            position: fixed;
            top: 0;
            left: 0;
            overflow: hidden;
        }

        .enrollment-container {
            display: grid;
            grid-template-columns: 40% 60%;
            height: 100vh;
            width: 100%;
            margin: 0;
        }

        .enrollment-left {
            padding: 60px 50px;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            background: white;
            border-right: 1px solid #eef2f7;
            z-index: 10;
            position: relative;
            padding-top: 40px;
            height: 100vh;
            overflow-y: auto;
        }

        .enrollment-header {
            text-align: center;
            margin-bottom: 25px;
            margin-top: 0;
        }

        .enrollment-logo img {
            width: 100px;
            height: 100px;
            margin-bottom: 25px;
        }

        .enrollment-title h1 {
            color: #0a2d63;
            font-size: 32px;
            font-weight: 700;
            margin: 0;
            line-height: 1.3;
        }

        .enrollment-form-container {
            width: 100%;
            max-width: 350px;
            margin: 0 auto;
        }

        .enrollment-form-container h2 {
            color: #0a2d63;
            font-size: 28px;
            margin-bottom: 30px;
            font-weight: 600;
            text-align: center;
        }

        .enroll-input-group {
            margin-bottom: 20px;
        }

        .enroll-input-group label {
            display: block;
            margin-bottom: 10px;
            color: #334155;
            font-weight: 600;
            font-size: 14px;
        }

        .enroll-input-group input,
        .enroll-input-group select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 15px;
            transition: all 0.3s;
            background: white;
            color: #333;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .enroll-input-group input:focus,
        .enroll-input-group select:focus {
            outline: none;
            border-color: #0a2d63;
            box-shadow: 0 0 0 3px rgba(10, 45, 99, 0.1);
        }

        .enroll-input-group select {
            cursor: pointer;
        }

        .phone-input-wrapper {
            display: flex;
            align-items: center;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: white;
            transition: all 0.3s;
        }

        .phone-input-wrapper:focus-within {
            border-color: #0a2d63;
            box-shadow: 0 0 0 3px rgba(10, 45, 99, 0.1);
        }

        .phone-prefix {
            padding: 12px 12px;
            color: #666;
            font-weight: 600;
            border-right: 1px solid #e2e8f0;
            background: #f8f9fa;
            border-radius: 7px 0 0 7px;
        }

        .phone-input-wrapper input {
            flex: 1;
            border: none;
            padding: 12px 12px;
            border-radius: 0 7px 7px 0;
        }

        .phone-input-wrapper input:focus {
            box-shadow: none;
            border: none;
            outline: none;
        }

        .file-upload-label {
            display: block;
            margin-bottom: 10px;
            color: #334155;
            font-weight: 600;
            font-size: 14px;
        }

        .file-upload-box {
            border: 2px dashed #0a2d63;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #f8f9fa;
            margin-bottom: 15px;
        }

        .file-upload-box:hover {
            background: #f0f2f5;
            border-color: #1a4a9c;
        }

        .file-upload-box input[type="file"] {
            display: none;
        }

        .file-upload-text {
            color: #0a2d63;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 5px;
        }

        .file-upload-hint {
            color: #999;
            font-size: 13px;
        }

        .file-list {
            margin-top: 10px;
            padding: 10px;
            background: #f0f2f5;
            border-radius: 4px;
            max-height: 100px;
            overflow-y: auto;
        }

        .file-item {
            padding: 5px;
            color: #666;
            font-size: 13px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .file-item button {
            background: #d32f2f;
            color: white;
            border: none;
            padding: 3px 8px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 11px;
        }

        .enroll-submit-btn {
            width: 100%;
            background: #0a2d63;
            color: white;
            border: none;
            padding: 16px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 10px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .enroll-submit-btn:hover {
            background: #082347;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(10, 45, 99, 0.3);
        }

        .enrollment-success {
            display: none;
            text-align: center;
            padding: 40px;
        }

        .enrollment-success.show {
            display: block;
        }

        .success-icon {
            font-size: 48px;
            margin-bottom: 20px;
        }

        .success-message h2 {
            color: #0a2d63;
            margin-bottom: 10px;
        }

        .success-message p {
            color: #666;
            margin-bottom: 30px;
        }

        .enrollment-right {
            position: relative;
            overflow: hidden;
        }

        .enrollment-right .animated-blue-bg {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(45deg, #0a2d63 0%, #1a4a9c 25%, #0a2d63 50%, #1a4a9c 75%, #0a2d63 100%);
            background-size: 400% 400%;
            animation: swervingGradient 15s ease infinite;
            z-index: 1;
        }

        .back-to-landing {
            text-align: center;
            margin-top: 20px;
        }

        .back-to-landing a {
            color: #64748b;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            transition: color 0.3s;
        }

        .back-to-landing a:hover {
            color: #0a2d63;
            text-decoration: underline;
        }

        @media (max-width: 1024px) {
            .enrollment-container {
                grid-template-columns: 1fr;
                grid-template-rows: auto 1fr;
            }

            .enrollment-left {
                padding: 40px 30px;
                border-right: none;
                border-bottom: 1px solid #eef2f7;
                max-height: 70vh;
            }

            .enrollment-right {
                min-height: 30vh;
            }
        }

        @media (max-width: 768px) {
            .enrollment-left {
                padding: 30px 20px;
            }

            .enrollment-logo img {
                width: 80px;
                height: 80px;
            }

            .enrollment-title h1 {
                font-size: 24px;
            }

            .enrollment-form-container h2 {
                font-size: 22px;
            }
        }
    </style>
</head>
<body>
    <!-- Landing Page -->
    <div class="landing-page" id="landingPage">
        <div class="landing-header">
            <div class="header-buttons">
                <button class="login-btn" onclick="showEnrollment()">Enroll</button>
                <button class="login-btn" onclick="showLogin()">Login</button>
            </div>
        </div>
        <div class="landing-hero">
            <div class="logo-container">
                <img src="images/logo.png" alt="BAA Logo">
                <h1 class="school-name">Baesa Adventist Academy</h1>
                <p class="school-tagline">The School That Trains for Service</p>
            </div>
            <div class="rotating-images">
                <div class="rotating-image active">
                    <img src="images/school1.jpg" alt="School Image 1">
                </div>
                <div class="rotating-image">
                    <img src="images/school2.jpg" alt="School Image 2">
                </div>
                <div class="rotating-image">
                    <img src="images/school3.jpg" alt="School Image 3">
                </div>
                <div class="rotating-image">
                    <img src="images/school4.jpg" alt="School Image 4">
                </div>
            </div>
        </div>
    </div>

    <!-- Login Page -->
    <div class="login-page" id="loginPage" style="display: none;">
        <div class="login-container">
            <div class="login-left">
                <div class="login-header">
                    <div class="login-logo">
                        <img src="images/logo.png" alt="BAA Logo">
                    </div>
                    <div class="login-title">
                        <h1>Baesa Adventist Academy</h1>
                    </div>
                </div>

                <div class="login-form-container">
                    <h2>Welcome Back</h2>

                    <div class="error-message" id="errorMessage" style="display: <?php echo isset($_GET['error']) ? 'block' : 'none'; ?>">
                        Invalid username or password
                    </div>

                    <form method="POST" action="php/login.php">
                        <div class="input-group">
                            <label for="studentId">Email / Student ID</label>
                            <div class="input-with-icon">
                                <div class="custom-icon username-icon"></div>
                                <input type="text" id="studentId" name="username" required 
                                       placeholder="Enter your email or student ID">
                            </div>
                        </div>

                        <div class="input-group">
                            <label for="password">Password</label>
                            <div class="input-with-icon">
                                <div class="custom-icon password-icon"></div>
                                <input type="password" id="password" name="password" required 
                                       placeholder="Enter your password">
                                <button type="button" class="toggle-password" id="togglePassword">
                                    <div class="custom-icon eye-icon" id="eyeIcon"></div>
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="login-btn-submit">
                            Login
                        </button>

                        <div class="back-to-home">
                            <a href="#" onclick="showLanding(); return false;">← Back to Home</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="login-right">
                <div class="right-content">
                    <div class="animated-blue-bg"></div>
                    <div class="swerving-waves">
                        <div class="swerving-wave"></div>
                        <div class="swerving-wave"></div>
                        <div class="swerving-wave"></div>
                    </div>
                    <div class="rotating-login-images">
                        <div class="login-image active">
                            <img src="images/school1.jpg" alt="School Image 1">
                        </div>
                        <div class="login-image">
                            <img src="images/school2.jpg" alt="School Image 2">
                        </div>
                        <div class="login-image">
                            <img src="images/school3.jpg" alt="School Image 3">
                        </div>
                        <div class="login-image">
                            <img src="images/school4.jpg" alt="School Image 4">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Enrollment Page -->
    <div class="enrollment-page" id="enrollmentPage" style="display: none;">
        <div class="enrollment-container">
            <!-- Left Side: Enrollment Form -->
            <div class="enrollment-left">
                <div class="enrollment-header">
                    <div class="enrollment-logo">
                        <img src="images/logo.png" alt="School Logo">
                    </div>
                    <div class="enrollment-title">
                        <h1>Baesa Adventist Academy</h1>
                    </div>
                </div>

                <div class="enrollment-form-container">
                    <div class="error-message" id="enrollmentError"></div>
                    
                    <div class="enrollment-success" id="enrollmentSuccess">
                        <div class="success-icon"></div>
                        <div class="success-message">
                            <h2>Enrollment Successful!</h2>
                            <p>Your enrollment application has been submitted successfully. We will review your documents and contact you soon.</p>
                            <button type="button" class="enroll-submit-btn" onclick="backFromEnrollment()">
                                Back to Home
                            </button>
                        </div>
                    </div>

                    <form id="enrollmentForm" style="display: block;">
                        <h2>Student Enrollment</h2>

                        <!-- Full Name -->
                        <div class="enroll-input-group">
                            <label for="fullName">Full Name *</label>
                            <input type="text" id="fullName" name="fullName" required placeholder="Enter your full name">
                        </div>

                        <!-- Age -->
                        <div class="enroll-input-group">
                            <label for="age">Age *</label>
                            <input type="number" id="age" name="age" min="1" max="120" required placeholder="Enter your age">
                        </div>

                        <!-- Gender -->
                        <div class="enroll-input-group">
                            <label for="gender">Gender *</label>
                            <select id="gender" name="gender" required>
                                <option value="">--Select Gender--</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>

                        <!-- Birthdate -->
                        <div class="enroll-input-group">
                            <label for="birthdate">Birthdate *</label>
                            <div class="birthdate-group" style="display: flex; gap: 10px;">
                                <select id="birthMonth" name="birthMonth" required style="flex: 1;">
                                    <option value="">Month</option>
                                    <option value="01">January</option>
                                    <option value="02">February</option>
                                    <option value="03">March</option>
                                    <option value="04">April</option>
                                    <option value="05">May</option>
                                    <option value="06">June</option>
                                    <option value="07">July</option>
                                    <option value="08">August</option>
                                    <option value="09">September</option>
                                    <option value="10">October</option>
                                    <option value="11">November</option>
                                    <option value="12">December</option>
                                </select>
                                <select id="birthDay" name="birthDay" required style="flex: 1;">
                                    <option value="">Day</option>
                                    <!-- Days will be populated by JS -->
                                </select>
                                <input type="number" id="birthYear" name="birthYear" placeholder="Year" required readonly style="flex: 1;">
                            </div>
                        </div>

                        <!-- Grade Level -->
                        <div class="enroll-input-group">
                            <label for="grade">Grade Level *</label>
                            <select id="grade" name="grade" required>
                                <option value="">--Select Grade--</option>
                                <option value="7">Grade 7</option>
                                <option value="8">Grade 8</option>
                                <option value="9">Grade 9</option>
                                <option value="10">Grade 10</option>
                                <option value="11">Grade 11</option>
                                <option value="12">Grade 12</option>
                            </select>
                        </div>

                        <!-- Strand (for Grades 11-12) -->
                        <div class="enroll-input-group" id="strandPicker" style="display: none;">
                            <label for="strand">Strand *</label>
                            <select id="strand" name="strand">
                                <option value="">--Select Strand--</option>
                                <option value="STEM">STEM (Science, Technology, Engineering, Mathematics)</option>
                                <option value="ABM">ABM (Accountancy, Business, Management)</option>
                                <option value="HUMSS">HUMSS (Humanities and Social Sciences)</option>
                            </select>
                        </div>

                        <!-- Email -->
                        <div class="enroll-input-group">
                            <label for="enrollEmail">Email Address *</label>
                            <input type="email" id="enrollEmail" name="email" required placeholder="Enter your email">
                        </div>

                        <!-- Phone Number -->
                        <div class="enroll-input-group">
                            <label for="enrollPhone">Phone Number *</label>
                            <div class="phone-input-wrapper">
                                <span class="phone-prefix">+63</span>
                                <input type="text" id="enrollPhone" name="phone" maxlength="10" required placeholder="9XXXXXXXXX" pattern="[0-9]{10}">
                            </div>
                        </div>

                        <!-- File Upload -->
                        <div class="enroll-input-group">
                            <label class="file-upload-label">Upload Required Documents *</label>
                            <div class="file-upload-box" id="fileUploadBox">
                                <div class="file-upload-text">📄 Click to upload or drag files</div>
                                <div class="file-upload-hint">Accepted: PDF, Images (Max 5 files, 5MB each)</div>
                                <input type="file" id="enrollDocuments" name="documents" multiple accept=".pdf,.jpg,.jpeg,.png" required>
                            </div>
                            <div class="file-list" id="fileList"></div>
                        </div>

                        <button type="submit" class="enroll-submit-btn">Submit Enrollment</button>
                    </form>

                    <div class="back-to-landing">
                        <a href="index.php">← Back to Home</a>
                    </div>
                </div>
            </div>

            <!-- Right Side: Blue Animated Background with Rotating Images -->
            <div class="enrollment-right">
                <div class="right-content">
                    <div class="animated-blue-bg"></div>
                    <div class="swerving-waves">
                        <div class="swerving-wave"></div>
                        <div class="swerving-wave"></div>
                        <div class="swerving-wave"></div>
                    </div>
                    <div class="rotating-login-images">
                        <div class="login-image active">
                            <img src="images/school1.jpg" alt="School Image 1">
                        </div>
                        <div class="login-image">
                            <img src="images/school2.jpg" alt="School Image 2">
                        </div>
                        <div class="login-image">
                            <img src="images/school3.jpg" alt="School Image 3">
                        </div>
                        <div class="login-image">
                            <img src="images/school4.jpg" alt="School Image 4">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="js/script.js"></script>
    <script>
        // Enrollment Form Handler
        document.addEventListener('DOMContentLoaded', function() {
            const fileUploadBox = document.getElementById('fileUploadBox');
            const fileInput = document.getElementById('enrollDocuments');
            const fileList = document.getElementById('fileList');
            const phoneInput = document.getElementById('enrollPhone');
            const enrollmentForm = document.getElementById('enrollmentForm');
            const gradeSelect = document.getElementById('grade');
            const strandPicker = document.getElementById('strandPicker');
            const strandSelect = document.getElementById('strand');
            const ageInput = document.getElementById('age');
            const birthMonth = document.getElementById('birthMonth');
            const birthDay = document.getElementById('birthDay');
            const birthYear = document.getElementById('birthYear');

            if (!fileUploadBox || !fileInput || !enrollmentForm) {
                return;
            }

            // Populate days
            function populateDays() {
                const month = birthMonth.value;
                const year = birthYear.value || 2026;
                const daysInMonth = new Date(year, month, 0).getDate();
                birthDay.innerHTML = '<option value="">Day</option>';
                for (let i = 1; i <= daysInMonth; i++) {
                    birthDay.innerHTML += `<option value="${i.toString().padStart(2, '0')}">${i}</option>`;
                }
            }

            birthMonth.addEventListener('change', populateDays);
            birthYear.addEventListener('input', populateDays);

            // Age guesser
            ageInput.addEventListener('input', function() {
                const age = parseInt(this.value);
                if (age && age > 0 && age < 120) {
                    const currentYear = 2026;
                    const birthYearValue = currentYear - age;
                    birthYear.value = birthYearValue;
                    populateDays();
                }
            });

            // Grade level change handler
            if (gradeSelect) {
                gradeSelect.addEventListener('change', function() {
                    const selectedGrade = parseInt(this.value);
                    
                    if (selectedGrade === 11 || selectedGrade === 12) {
                        if (strandPicker) strandPicker.style.display = 'block';
                        if (strandSelect) strandSelect.required = true;
                    } else {
                        if (strandPicker) strandPicker.style.display = 'none';
                        if (strandSelect) {
                            strandSelect.required = false;
                            strandSelect.value = '';
                        }
                    }
                });
            }

            // Initialize file list
            if (fileList) {
                fileList.innerHTML = '<p style="color: #999; margin: 0;">No files selected</p>';
            }

            // Drag and drop functionality
            fileUploadBox.addEventListener('dragover', (e) => {
                e.preventDefault();
                fileUploadBox.style.background = '#f0f2f5';
            });

            fileUploadBox.addEventListener('dragleave', () => {
                fileUploadBox.style.background = '#f8f9fa';
            });

            fileUploadBox.addEventListener('drop', (e) => {
                e.preventDefault();
                fileUploadBox.style.background = '#f8f9fa';
                fileInput.files = e.dataTransfer.files;
                updateFileList();
            });

            fileUploadBox.addEventListener('click', () => {
                fileInput.click();
            });

            fileInput.addEventListener('change', updateFileList);

            function updateFileList() {
                if (!fileList) return;
                fileList.innerHTML = '';
                const files = fileInput.files;

                if (files.length === 0) {
                    fileList.innerHTML = '<p style="color: #999; margin: 0;">No files selected</p>';
                    return;
                }

                for (let i = 0; i < files.length; i++) {
                    const file = files[i];
                    const fileSize = (file.size / 1024 / 1024).toFixed(2);
                    const fileItem = document.createElement('div');
                    fileItem.className = 'file-item';
                    fileItem.innerHTML = `
                        <span>${file.name} (${fileSize}MB)</span>
                        <button type="button" onclick="removeEnrollmentFile(${i})">Remove</button>
                    `;
                    fileList.appendChild(fileItem);
                }
            }

            // Phone number validation
            if (phoneInput) {
                phoneInput.addEventListener('input', (e) => {
                    e.target.value = e.target.value.replace(/[^0-9]/g, '');
                    if (e.target.value.length > 10) {
                        e.target.value = e.target.value.slice(0, 10);
                    }
                });
            }

            // Form submission
            enrollmentForm.addEventListener('submit', async (e) => {
                e.preventDefault();

                const errorDiv = document.getElementById('enrollmentError');
                if (errorDiv) {
                    errorDiv.classList.remove('show');
                }

                // Validate file upload
                if (fileInput.files.length === 0) {
                    if (errorDiv) {
                        errorDiv.textContent = 'Please upload at least one document.';
                        errorDiv.classList.add('show');
                    }
                    return;
                }

                // Validate grade selection
                if (!gradeSelect.value) {
                    if (errorDiv) {
                        errorDiv.textContent = 'Please select a grade level.';
                        errorDiv.classList.add('show');
                    }
                    return;
                }

                // Validate strand for Grades 11-12
                const selectedGrade = parseInt(gradeSelect.value);
                if ((selectedGrade === 11 || selectedGrade === 12) && !strandSelect.value) {
                    if (errorDiv) {
                        errorDiv.textContent = 'Please select a strand for Senior High School (Grade 11-12).';
                        errorDiv.classList.add('show');
                    }
                    return;
                }

                // Validate birthdate
                if (!birthYear.value || !birthMonth.value || !birthDay.value) {
                    if (errorDiv) {
                        errorDiv.textContent = 'Please complete the birthdate fields.';
                        errorDiv.classList.add('show');
                    }
                    return;
                }

                // Validate file sizes
                for (let file of fileInput.files) {
                    if (file.size > 5 * 1024 * 1024) {
                        if (errorDiv) {
                            errorDiv.textContent = 'Each file must be less than 5MB.';
                            errorDiv.classList.add('show');
                        }
                        return;
                    }
                }

                // Validate phone number format
                const phone = phoneInput.value;
                if (phone.length !== 10) {
                    if (errorDiv) {
                        errorDiv.textContent = 'Phone number must be 10 digits.';
                        errorDiv.classList.add('show');
                    }
                    return;
                }

                // Create FormData for file upload
                const formData = new FormData();
                formData.append('fullName', document.getElementById('fullName').value);
                formData.append('age', document.getElementById('age').value);
                formData.append('gender', document.getElementById('gender').value);
                const birthdate = `${birthYear.value}-${birthMonth.value}-${birthDay.value}`;
                formData.append('birthdate', birthdate);
                formData.append('grade', gradeSelect.value);
                formData.append('strand', strandSelect.value || '');
                formData.append('email', document.getElementById('enrollEmail').value);
                formData.append('phone', '+63' + phone);

                for (let file of fileInput.files) {
                    formData.append('documents[]', file);
                }

                try {
                    const response = await fetch('php/handle_enrollment.php', {
                        method: 'POST',
                        body: formData
                    });

                    const responseText = await response.text();
                    console.log('Response text:', responseText);
                    
                    let result;
                    try {
                        result = JSON.parse(responseText);
                    } catch(e) {
                        console.error('Failed to parse JSON:', e);
                        console.error('Response was:', responseText);
                        throw new Error('Invalid response format');
                    }

                    if (result.success) {
                        document.getElementById('enrollmentForm').style.display = 'none';
                        const successDiv = document.getElementById('enrollmentSuccess');
                        if (successDiv) {
                            successDiv.classList.add('show');
                        }
                    } else {
                        if (errorDiv) {
                            errorDiv.textContent = result.message || 'An error occurred. Please try again.';
                            errorDiv.classList.add('show');
                        }
                    }
                } catch (error) {
                    console.error('Error:', error);
                    if (errorDiv) {
                        errorDiv.textContent = 'An error occurred while processing your enrollment. Please try again.';
                        errorDiv.classList.add('show');
                    }
                }
            });
        });

        // Define showEnrollment function globally
        function showEnrollment() {
            const landingPage = document.getElementById('landingPage');
            const loginPage = document.getElementById('loginPage');
            const enrollmentPage = document.getElementById('enrollmentPage');
            const dashboardPage = document.getElementById('dashboardPage');
            
            if (landingPage) landingPage.style.display = 'none';
            if (loginPage) loginPage.style.display = 'none';
            if (enrollmentPage) enrollmentPage.style.display = 'block';
            if (dashboardPage) dashboardPage.style.display = 'none';
        }

        function removeEnrollmentFile(index) {
            const fileInput = document.getElementById('enrollDocuments');
            const dataTransfer = new DataTransfer();
            
            for (let i = 0; i < fileInput.files.length; i++) {
                if (i !== index) {
                    dataTransfer.items.add(fileInput.files[i]);
                }
            }
            
            fileInput.files = dataTransfer.files;
            updateEnrollmentFileList();
        }

        function updateEnrollmentFileList() {
            const fileInput = document.getElementById('enrollDocuments');
            const fileList = document.getElementById('fileList');
            if (!fileList) return;
            
            fileList.innerHTML = '';
            const files = fileInput.files;

            if (files.length === 0) {
                fileList.innerHTML = '<p style="color: #999; margin: 0;">No files selected</p>';
                return;
            }

            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                const fileSize = (file.size / 1024 / 1024).toFixed(2);
                const fileItem = document.createElement('div');
                fileItem.className = 'file-item';
                fileItem.innerHTML = `
                    <span>${file.name} (${fileSize}MB)</span>
                    <button type="button" onclick="removeEnrollmentFile(${i})">Remove</button>
                `;
                fileList.appendChild(fileItem);
            }
        }

        function backFromEnrollment() {
        const form = document.getElementById('enrollmentForm');
        const success = document.getElementById('enrollmentSuccess');

        if (form) {
            form.style.display = 'block';
            form.reset();
        }

        if (success) {
            success.classList.remove('show');
        }
        showLanding();
    }
    </script>
</body>
</html>