document.addEventListener('DOMContentLoaded', function () {
    const dash = document.getElementById('dashboardPage');
    if (dash) {
        dash.style.display = 'block';
        dash.style.opacity = '1';
        dash.style.transform = 'none';
    }
});

// IMAGE ROTATION FUNCTIONS
let currentImage = 0;
const images = document.querySelectorAll('.rotating-image');
let loginPhotoCurrent = 0;
const loginPhotos = document.querySelectorAll('.login-image');

function rotateImages() {
    if (images.length > 0) {
        images[currentImage].classList.remove('active');
        currentImage = (currentImage + 1) % images.length;
        images[currentImage].classList.add('active');
    }
}

function rotateLoginPhotos() {
    if (loginPhotos.length > 0) {
        loginPhotos[loginPhotoCurrent].classList.remove('active');
        loginPhotoCurrent = (loginPhotoCurrent + 1) % loginPhotos.length;
        loginPhotos[loginPhotoCurrent].classList.add('active');
    }
}

function startLoginPhotoRotation() {
    if (loginPhotos.length > 0) {
        loginPhotos[0].classList.add('active');
        setInterval(rotateLoginPhotos, 2000);
    }
}

// PASSWORD TOGGLE
document.addEventListener('DOMContentLoaded', function() {
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    const eyeIcon = document.getElementById('eyeIcon');
    
    if (togglePassword && passwordInput && eyeIcon) {
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            if (type === 'text') {
                eyeIcon.classList.add('show-password');
                eyeIcon.style.backgroundImage = "url('images/eye_icon2.png')";
            } else {
                eyeIcon.classList.remove('show-password');
                eyeIcon.style.backgroundImage = "url('images/eye_icon1.png')";
            }
        });
    }
    
    // Start image rotation
    if (images.length > 0) {
        images[0].classList.add('active');
        setInterval(rotateImages, 2000);
    }

    if (document.getElementById('landingPage')) {
    showLanding();
}
});

// PAGE NAVIGATION
function showLogin() {
    document.getElementById('landingPage').style.display = 'none';
    document.getElementById('loginPage').style.display = 'block';
    document.getElementById('enrollmentPage').style.display = 'none';
    document.getElementById('dashboardPage').style.display = 'none';
    document.getElementById('errorMessage').classList.remove('show');
    setTimeout(startLoginPhotoRotation, 100);
}

function showLanding() {
    const landingPage = document.getElementById('landingPage');
    const loginPage = document.getElementById('loginPage');
    const enrollmentPage = document.getElementById('enrollmentPage');

    if (landingPage) landingPage.style.display = 'block';
    if (loginPage) loginPage.style.display = 'none';
    if (enrollmentPage) enrollmentPage.style.display = 'none';
}



function showDashboard() {
    document.getElementById('landingPage').style.display = 'none';
    document.getElementById('loginPage').style.display = 'none';
    document.getElementById('enrollmentPage').style.display = 'none';
    document.getElementById('dashboardPage').style.display = 'block';
    document.getElementById('errorMessage').classList.remove('show');
    const dashboardPage = document.getElementById('dashboardPage');
    dashboardPage.style.opacity = '0';
    dashboardPage.style.transform = 'translateY(20px)';
    dashboardPage.style.transition = 'all 0.5s ease-out';
    
    setTimeout(() => {
        dashboardPage.style.opacity = '1';
        dashboardPage.style.transform = 'translateY(0)';
    }, 50);
    
    navigateTo('home');
}

// DATA LOADING FUNCTIONS
async function loadGrades() {
    try {
        const response = await fetch('php/get_grades.php');
        const data = await response.json();
        
        if (data.success && data.grades) {
            const gradeSummary = document.getElementById('gradeSummary');
            if (gradeSummary) {
                gradeSummary.innerHTML = data.grades.map(grade => `
                    <div class="grade-item">
                        <span class="subject">${grade.subject_name}</span>
                        <span class="grade">${grade.grade}</span>
                    </div>
                `).join('');
            }
        }
    } catch (error) {
        console.error('Error loading grades:', error);
        document.getElementById('gradeSummary').innerHTML = '<div class="error">Failed to load grades</div>';
    }
}

async function loadSubjects() {
    try {
        const response = await fetch('php/get_subject.php');
        const data = await response.json();
        
        if (data.success && data.subjects) {
            const subjectList = document.getElementById('subjectList');
            if (subjectList) {
                subjectList.innerHTML = data.subjects.map(subject => `
                    <div class="subject-item">
                        <h4>${subject.subject_name}</h4>
                        <p>${subject.schedule}</p>
                    </div>
                `).join('');
            }
        }
    } catch (error) {
        console.error('Error loading subjects:', error);
        document.getElementById('subjectList').innerHTML = '<div class="error">Failed to load subjects</div>';
    }
}

async function loadEvents() {
    try {
        const response = await fetch('php/get_events.php');
        const data = await response.json();
        
        if (data.success && data.events) {
            const eventList = document.getElementById('eventList');
            if (eventList) {
                eventList.innerHTML = data.events.map(event => `
                    <div class="event-item">
                        <div class="event-date">${formatDate(event.event_date)}</div>
                        <div class="event-details">
                            <h4>${event.title}</h4>
                        </div>
                    </div>
                `).join('');
            }
        }
    } catch (error) {
        console.error('Error loading events:', error);
        document.getElementById('eventList').innerHTML = '<div class="error">Failed to load events</div>';
    }
}

async function loadPayables() {
    try {
        const response = await fetch('php/get_payables.php');
        const data = await response.json();
        
        if (data.success && data.payables) {
            const payableList = document.getElementById('payableList');
            if (payableList) {
                payableList.innerHTML = data.payables.map(payable => `
                    <div class="payable-item">
                        <div class="payable-details">
                            <h4>${payable.description}</h4>
                            <span class="payable-date">Due: ${formatDate(payable.due_date)}</span>
                        </div>
                        <div class="payable-amount">
                            <span class="payable-total">₱${parseFloat(payable.amount).toLocaleString('en-PH', {minimumFractionDigits: 2})}</span>
                            <span class="payable-status">Pending</span>
                        </div>
                    </div>
                `).join('');
            }
        }
    } catch (error) {
        console.error('Error loading payables:', error);
        document.getElementById('payableList').innerHTML = '<div class="error">Failed to load payables</div>';
    }
}

async function loadProfile() {
    try {
        const response = await fetch('php/get_profile.php');
        const data = await response.json();
        
        if (data.success && data.profile) {
            const profileInfo = document.getElementById('profileInfo');
            if (profileInfo) {
                profileInfo.innerHTML = `
                    <div class="info-item">
                        <span class="label">Full Name:</span>
                        <span class="value">${data.profile.full_name || data.profile.username}</span>
                    </div>
                    <div class="info-item">
                        <span class="label">Email:</span>
                        <span class="value">${data.profile.email || 'N/A'}</span>
                    </div>
                    <div class="info-item">
                        <span class="label">User Type:</span>
                        <span class="value">${data.profile.user_type || 'Student'}</span>
                    </div>
                    <div class="info-item">
                        <span class="label">Grade Level:</span>
                        <span class="value">${data.profile.grade_level || 'Grade 10'}</span>
                    </div>
                    <div class="info-item">
                        <span class="label">Section:</span>
                        <span class="value">${data.profile.section || 'Self-Control'}</span>
                    </div>
                    <div class="info-item">
                        <span class="label">LRN:</span>
                        <span class="value">${data.profile.lrn || '136628120097'}</span>
                    </div>
                `;
            }
        }
    } catch (error) {
        console.error('Error loading profile:', error);
        document.getElementById('profileInfo').innerHTML = '<div class="error">Failed to load profile</div>';
    }
}

async function loadAnnouncements() {
    try {
        const response = await fetch('php/get_announcements.php');
        const data = await response.json();
        
        if (data.success && data.announcements) {
            const announcementList = document.getElementById('announcementList');
            if (announcementList) {
                announcementList.innerHTML = data.announcements.map(announcement => `
                    <div class="announcement-item">
                        <div class="announcement-header">
                            <h4>${announcement.title}</h4>
                            <span class="announcement-date">Posted: ${formatDate(announcement.created_at)}</span>
                        </div>
                        <p>${announcement.content}</p>
                    </div>
                `).join('');
            }
        }
    } catch (error) {
        console.error('Error loading announcements:', error);
        document.getElementById('announcementList').innerHTML = '<div class="error">Failed to load announcements</div>';
    }
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
}

// DASHBOARD NAVIGATION WITH CARD SWAPPING
function navigateTo(page) {
    console.log(`Navigating to: ${page}`);
    toggleSidebar();

    const isAdmin = document.querySelector('.grades-card') && 
    !document.querySelector('.subjects-card');

    // Remove active class from all menu items
    const menuItems = document.querySelectorAll('.sidebar ul li a');
    menuItems.forEach(item => {
        item.classList.remove('active');
    });
    
    // Add active class to clicked menu item
    const clickedItem = document.getElementById(`menu-${page}`);
    if (clickedItem) {
        clickedItem.classList.add('active');
    }
    
    if (document.body.classList.contains('admin-mode')) {
    const dashboardLeft = document.querySelector('.dashboard-left');
    const dashboardRight = document.querySelector('.dashboard-right');
    const enrollment = document.querySelector('.grades-card');
    const profile = document.querySelector('.profile-card');
    const users = document.querySelector('.users-card');
    const payables = document.querySelector('.payables-management-card');
    const payments = document.querySelector('.payments-card');

    if (page === 'home') {
        if (dashboardLeft) dashboardLeft.style.display = 'block';
        if (dashboardRight) dashboardRight.style.display = 'block';
        if (enrollment) enrollment.style.display = 'flex';
        if (profile) profile.style.display = 'none';
        if (users) users.style.display = 'none';
        if (payables) payables.style.display = 'none';
        if (payments) payments.style.display = 'none';
        if (typeof loadEnrollments === 'function') {
            loadEnrollments();
        }
    } else {
        if (dashboardLeft) dashboardLeft.style.display = 'none';
        if (dashboardRight) dashboardRight.style.display = 'none';
        if (enrollment) enrollment.style.display = 'none';
        if (profile) profile.style.display = page === 'profile' ? 'flex' : 'none';
        if (users) users.style.display = page === 'users' ? 'flex' : 'none';
        if (payables) payables.style.display = page === 'payables' ? 'flex' : 'none';
        if (payments) payments.style.display = page === 'payments' ? 'flex' : 'none';
        if (page === 'profile') {
            loadProfile();
        }
        if (page === 'users') {
            if (typeof loadUsers === 'function') {
                loadUsers();
            }
        }
        if (page === 'payables') {
            if (typeof loadStudents === 'function') {
                loadStudents();
            }
        }
        if (page === 'payments') {
            if (typeof loadPaymentStudents === 'function') {
                loadPaymentStudents();
            }
        }
    }

    return;
}


    // Get dashboard container
    const dashboardMain = document.querySelector('.dashboard-main');
    if (!dashboardMain) {
        console.error('Dashboard container not found');
        return;
    }
    
    // Remove all layout classes
    const layoutClasses = [
        'layout-home', 'layout-grades', 'layout-subjects',
        'layout-events', 'layout-payables', 'layout-profile',
        'layout-announcements'
    ];
    layoutClasses.forEach(cls => {
        dashboardMain.classList.remove(cls);
    });

    dashboardMain.classList.add(`layout-${page}`);
    
    // Get all cards and columns
    const eventsCard = document.querySelector('.events-card');
    const gradesCard = document.querySelector('.grades-card');
    const subjectsCard = document.querySelector('.subjects-card');
    const leftColumn = document.querySelector('.dashboard-left');
    const rightColumn = document.querySelector('.dashboard-right');
    
    // Determine which card goes where
    let targetLeftCard, targetRightCards;
    
    switch(page) {
        case 'home':
            targetLeftCard = eventsCard;
            targetRightCards = [gradesCard, subjectsCard];
            loadEvents();
            loadGrades();
            loadSubjects();
            break;
            
        case 'grades':
            targetLeftCard = gradesCard;
            targetRightCards = [eventsCard, subjectsCard];
            loadGrades();
            break;
            
        case 'subjects':
            targetLeftCard = subjectsCard;
            targetRightCards = [eventsCard, gradesCard];
            loadSubjects();
            break;
            
        case 'events':
            targetLeftCard = eventsCard;
            targetRightCards = [gradesCard, subjectsCard];
            loadEvents();
            break;
            
        case 'payables':
            console.log('Showing Payables');
            dashboardMain.classList.add('layout-payables');
            const payablesCard = document.querySelector('.payables-card');
            if (payablesCard) {
                payablesCard.style.display = 'flex';
                payablesCard.style.width = '100%';
                payablesCard.style.maxWidth = '900px';
                payablesCard.style.margin = '0 auto';
            }
            [eventsCard, gradesCard, subjectsCard].forEach(card => {
                if (card) card.style.display = 'none';
            });
            // ensure other specialized cards are hidden
            const profileCardEl = document.querySelector('.profile-card');
            const announcementsCardEl = document.querySelector('.announcements-card');
            if (profileCardEl) profileCardEl.style.display = 'none';
            if (announcementsCardEl) announcementsCardEl.style.display = 'none';
            loadPayables();
            return;
            
        case 'profile':
            console.log('Showing Profile');
            dashboardMain.classList.add('layout-profile');
            const profileCard = document.querySelector('.profile-card');
            if (profileCard) {
                profileCard.style.display = 'flex';
                profileCard.style.width = '100%';
                profileCard.style.maxWidth = '900px';
                profileCard.style.margin = '0 auto';
            }
            [eventsCard, gradesCard, subjectsCard].forEach(card => {
                if (card) card.style.display = 'none';
            });
            const payablesCardEl = document.querySelector('.payables-card');
            const announcementsCardEl2 = document.querySelector('.announcements-card');
            if (payablesCardEl) payablesCardEl.style.display = 'none';
            if (announcementsCardEl2) announcementsCardEl2.style.display = 'none';
            loadProfile();
            return;
            
        case 'announcements':
            console.log('Showing Announcements');
            dashboardMain.classList.add('layout-announcements');
            const announcementsCard = document.querySelector('.announcements-card');
            if (announcementsCard) {
                announcementsCard.style.display = 'flex';
                announcementsCard.style.width = '100%';
                announcementsCard.style.maxWidth = '900px';
                announcementsCard.style.margin = '0 auto';
            }
            [eventsCard, gradesCard, subjectsCard].forEach(card => {
                if (card) card.style.display = 'none';
            });
            const payablesCardEl2 = document.querySelector('.payables-card');
            const profileCardEl2 = document.querySelector('.profile-card');
            if (payablesCardEl2) payablesCardEl2.style.display = 'none';
            if (profileCardEl2) profileCardEl2.style.display = 'none';
            loadAnnouncements();
            return;
            
        default:
            return;
    }
    
    [eventsCard, gradesCard, subjectsCard].forEach(card => {
        card.style.display = 'flex';
    });
    // Hide specialized cards
    document.querySelector('.payables-card').style.display = 'none';
    document.querySelector('.profile-card').style.display = 'none';
    document.querySelector('.announcements-card').style.display = 'none';
    const currentLeftCard = leftColumn.querySelector('.dashboard-card');
    const currentRightCards = Array.from(rightColumn.querySelectorAll('.dashboard-card'));
    
    // Add transition classes
    [eventsCard, gradesCard, subjectsCard].forEach(card => {
        card.classList.add('height-transition');
    });
    
    // Function to smoothly move cards
    function performSmoothSwap() {
        leftColumn.innerHTML = '';
        rightColumn.innerHTML = '';
    // Card Animations
        if (currentRightCards.includes(targetLeftCard)) {
            targetLeftCard.classList.add('card-scale-up');
            targetLeftCard.classList.add('card-move-to-left');
        } else if (currentLeftCard === targetLeftCard) {
            // Card is staying in left column
            targetLeftCard.classList.add('card-scale-up');
        } else {
            // Card is moving from left to right
            targetLeftCard.classList.add('card-scale-down');
            targetLeftCard.classList.add('card-move-to-right');
        }
        
        // Add target left card
        leftColumn.appendChild(targetLeftCard);
        
        // Add target right cards with staggered animations
        targetRightCards.forEach((card, index) => {
            // If card is moving from left to right
            if (card === currentLeftCard) {
                card.classList.add('card-scale-down');
                card.classList.add('card-move-to-right');
            } else if (currentRightCards.includes(card)) {
                // Card is staying in right column
                card.classList.add('card-scale-down');
            } else {
                // Card is moving from right to left
                card.classList.add('card-scale-up');
                card.classList.add('card-move-to-left');
            }
            
            rightColumn.appendChild(card);
            setTimeout(() => {
                card.style.animationDelay = `${index * 0.1}s`;
            }, 10);
        });
        
        // Clean up animation classes after animation completes
        setTimeout(() => {
            [eventsCard, gradesCard, subjectsCard].forEach(card => {
                card.classList.remove(
                    'card-scale-up', 
                    'card-scale-down',
                    'card-move-to-left',
                    'card-move-to-right',
                    'height-transition'
                );
                card.style.animationDelay = '';
            });
        }, 600);
        
        console.log(`${page}: ${targetLeftCard.querySelector('h3').textContent} is now big on left`);
    }
    
    // Perform the smooth swap
    performSmoothSwap();
    
    // Ensure right column has proper styling
    rightColumn.style.display = 'flex';
    rightColumn.style.flexDirection = 'column';
    rightColumn.style.gap = '30px';
    leftColumn.style.flex = '2';
    rightColumn.style.flex = '1';
    void dashboardMain.offsetWidth;
}

// LOGIN HANDLER
function handleLogin(event) {
    event.preventDefault();
    const studentId = document.getElementById('studentId').value.trim().toLowerCase();
    const password = document.getElementById('password').value;
    const errorMessage = document.getElementById('errorMessage');
    
    if ((studentId === 'student' && password === 'student123') ||
        (studentId === 'teacher' && password === 'teacher123') ||
        (studentId === 'admin' && password === 'admin123')) {
        
        let userName = '';
        if (studentId === 'student') {
            userName = 'John Student';
        } else if (studentId === 'teacher') {
            userName = 'Prof. Jane Smith';
        } else if (studentId === 'admin') {
            userName = 'Administrator';
        }
        
        document.getElementById('userName').textContent = userName;
        errorMessage.classList.remove('show');
        showDashboard();
    } else {
        errorMessage.classList.add('show');
    }
}

// LOGOUT HANDLER
function handleLogout() {
    document.getElementById('studentId').value = '';
    document.getElementById('password').value = '';
    showLanding();
}

// SIDEBAR TOGGLE
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
}

// CLOSE SIDEBAR WHEN CLICKING OUTSIDE
document.addEventListener('click', function(event) {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const menuBtn = document.querySelector('.menu-btn');
    
    if (sidebar && sidebar.classList.contains('open') && 
        !sidebar.contains(event.target) && 
        menuBtn && !menuBtn.contains(event.target)) {
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
    }
});

// MAKE BUTTONS TOUCH-FRIENDLY
document.querySelectorAll('button, a').forEach(element => {
    element.style.cursor = 'pointer';
});

// INITIAL LOAD
document.addEventListener('DOMContentLoaded', function() {
    const dash = document.getElementById('dashboardPage');
    if (dash) {
        loadEvents();
        loadGrades();
        loadSubjects();
    }
});
