<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Baesa Adventist Academy - Student Enrollment</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .enrollment-page {
            display: none;
            min-height: 100vh;
            background: #f5f5f0;
        }

        .enrollment-container {
            display: grid;
            grid-template-columns: 40% 60%;
            min-height: 100vh;
            width: 100%;
            margin: 0;
        }

        .enrollment-left {
            padding: 60px 50px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: white;
            border-right: 1px solid #eef2f7;
            z-index: 10;
            position: relative;
            overflow-y: auto;
            max-height: 100vh;
        }

        .enrollment-header {
            text-align: center;
            margin-bottom: 40px;
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

        /* Enrollment Form */
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

        /* Input Groups */
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

        /* Phone Number */
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

        /* File Upload */
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

        /* Enrollment Button */
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
        }

        .enroll-submit-btn:hover {
            background: #082347;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(10, 45, 99, 0.3);
        }

        .enroll-submit-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }

        /* Back Link */
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

        /* Success Message */
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

        /* Right Side */
        .enrollment-right {
            position: relative;
            overflow: hidden;
        }

        .right-content {
            position: relative;
            width: 100%;
            height: 100%;
        }

        .animated-blue-bg {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(45deg, 
                #0a2d63 0%, 
                #1a4a9c 25%, 
                #0a2d63 50%, 
                #1a4a9c 75%, 
                #0a2d63 100%);
            background-size: 400% 400%;
            animation: swervingGradient 15s ease infinite;
            z-index: 1;
        }

        @keyframes swervingGradient {
            0% {
                background-position: 0% 0%;
                background: linear-gradient(45deg, #0a2d63, #1a4a9c, #0a2d63);
            }
            25% {
                background-position: 100% 0%;
                background: linear-gradient(135deg, #1a4a9c, #0a2d63, #1a4a9c);
            }
            50% {
                background-position: 100% 100%;
                background: linear-gradient(225deg, #0a2d63, #1a4a9c, #0a2d63);
            }
            75% {
                background-position: 0% 100%;
                background: linear-gradient(315deg, #1a4a9c, #0a2d63, #1a4a9c);
            }
            100% {
                background-position: 0% 0%;
                background: linear-gradient(45deg, #0a2d63, #1a4a9c, #0a2d63);
            }
        }

        /* Error Message */
        .error-message {
            background: #fee;
            color: #d32f2f;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #d32f2f;
            text-align: center;
            display: none;
            font-weight: 500;
            font-size: 14px;
        }

        .error-message.show {
            display: block;
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

            .enrollment-form-container {
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Enrollment Page -->
    <div class="enrollment-page" id="enrollmentPage">
        <div class="enrollment-container">
            <!-- Left Side: Enrollment Form -->
            <div class="enrollment-left">
                <div class="enrollment-header">
                    <div class="enrollment-logo">
                        <img src="images/logo.png" alt="School Logo">
                    </div>
                    <div class="enrollment-title">
                        <h1>Student Enrollment</h1>
                    </div>
                </div>

                <div class="enrollment-form-container">
                    <div class="error-message" id="enrollmentError"></div>
                    
                    <div class="enrollment-success" id="enrollmentSuccess">
                        <div class="success-icon"></div>
                        <div class="success-message">
                            <h2>Enrollment Successful!</h2>
                            <p>Your enrollment application has been submitted successfully. We will review your documents and contact you soon.</p>
                            <a href="index.php" class="enroll-submit-btn">Back to Home</a>
                        </div>
                    </div>

                    <form id="enrollmentForm">
                        <h2>Enroll Now</h2>

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
                            <input type="date" id="birthdate" name="birthdate" required>
                        </div>

                        <!-- Email -->
                        <div class="enroll-input-group">
                            <label for="email">Email Address *</label>
                            <input type="email" id="email" name="email" required placeholder="Enter your email">
                        </div>

                        <!-- Phone Number -->
                        <div class="enroll-input-group">
                            <label for="phone">Phone Number *</label>
                            <div class="phone-input-wrapper">
                                <span class="phone-prefix">+63</span>
                                <input type="text" id="phone" name="phone" maxlength="10" required placeholder="9XXXXXXXXX" pattern="[0-9]{10}">
                            </div>
                            <small style="color: #999;">Enter 10 digits (without +63)</small>
                        </div>

                        <!-- File Upload -->
                        <div class="enroll-input-group">
                            <label class="file-upload-label">Upload Required Documents *</label>
                            <div class="file-upload-box" id="fileUploadBox">
                                <div class="file-upload-text">📄 Click to upload or drag files</div>
                                <div class="file-upload-hint">Accepted: PDF, Images (Max 5 files, 5MB each)</div>
                                <input type="file" id="documents" name="documents" multiple accept=".pdf,.jpg,.jpeg,.png" required>
                            </div>
                            <div class="file-list" id="fileList"></div>
                        </div>

                        <button type="submit" class="enroll-submit-btn">Submit Enrollment</button>
                    </form>

                    <div class="back-to-landing">
                        <a href="index.php">Back to Home</a>
                    </div>
                </div>
            </div>

            <!-- Right Side: Blue Animated Background -->
            <div class="enrollment-right">
                <div class="animated-blue-bg"></div>
            </div>
        </div>
    </div>

    <script src="js/script.js"></script>
    <script>
        // Initialize enrollment form
        document.addEventListener('DOMContentLoaded', function() {
            const fileUploadBox = document.getElementById('fileUploadBox');
            const fileInput = document.getElementById('documents');
            const fileList = document.getElementById('fileList');
            const phoneInput = document.getElementById('phone');
            const enrollmentForm = document.getElementById('enrollmentForm');

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

            // Click to upload
            fileUploadBox.addEventListener('click', () => {
                fileInput.click();
            });

            // File input change
            fileInput.addEventListener('change', updateFileList);

            function updateFileList() {
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
                        <button type="button" onclick="removeFile(${i})">Remove</button>
                    `;
                    fileList.appendChild(fileItem);
                }
            }

            // Phone number validation
            phoneInput.addEventListener('input', (e) => {
                e.target.value = e.target.value.replace(/[^0-9]/g, '');
                if (e.target.value.length > 10) {
                    e.target.value = e.target.value.slice(0, 10);
                }
            });

            // Form submission
            enrollmentForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const errorDiv = document.getElementById('enrollmentError');
            errorDiv.classList.remove('show');

            // Validate files
            if (fileInput.files.length === 0) {
                errorDiv.textContent = 'Please upload at least one document.';
                errorDiv.classList.add('show');
            return;
    }

            // Create FormData
            const formData = new FormData();
            formData.append('fullName', document.getElementById('fullName').value);
            formData.append('age', document.getElementById('age').value);
            formData.append('gender', document.getElementById('gender').value);
            formData.append('birthdate', document.getElementById('birthdate').value);
            formData.append('email', document.getElementById('email').value);
            formData.append('phone', document.getElementById('phone').value);   
            for (let file of fileInput.files) {
                formData.append('documents[]', file);
    }

            try {
                const response = await fetch('php/handle_enrollment.php', {
                    method: 'POST',
                    body: formData
        });

            const result = await response.json();

            if (result.success) {
            // Ask user if they want to generate PDF
            const generatePDF = confirm('Enrollment submitted successfully! Would you like to generate a PDF receipt?');
            
            if (generatePDF) {
                window.open(result.pdf_url, '_blank');
            }

            document.getElementById('enrollmentForm').style.display = 'none';
            document.getElementById('enrollmentSuccess').classList.add('show');
            
            // Add link to view PDF later
            const successDiv = document.getElementById('enrollmentSuccess');
            const pdfLink = `<p style="margin-top: 20px;">
                <a href="${result.pdf_url}" target="_blank" style="color: #0a2d63; text-decoration: underline;">
                    📄 Click here to download your enrollment receipt
                </a>
            </p>`;
            successDiv.querySelector('.success-message').innerHTML += pdfLink;
            
            } else {
            errorDiv.textContent = result.message || 'An error occurred.';
            errorDiv.classList.add('show');
            }
            } catch (error) {
            console.error('Error:', error);
            errorDiv.textContent = 'Network error. Please try again.';
            errorDiv.classList.add('show');
        }
    });

        });

        function removeFile(index) {
            const fileInput = document.getElementById('documents');
            const dataTransfer = new DataTransfer();
            
            for (let i = 0; i < fileInput.files.length; i++) {
                if (i !== index) {
                    dataTransfer.items.add(fileInput.files[i]);
                }
            }
            
            fileInput.files = dataTransfer.files;
            document.querySelector('#fileList');
            const fileList = document.getElementById('fileList');
            fileList.innerHTML = '';
            updateFileList();
        }

        function updateFileList() {
            const fileInput = document.getElementById('documents');
            const fileList = document.getElementById('fileList');
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
                    <button type="button" onclick="removeFile(${i})">Remove</button>
                `;
                fileList.appendChild(fileItem);
            }
        }
    </script>
</body>
</html>
