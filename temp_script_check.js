    // ==================== STUDENT SEARCH FUNCTIONS ====================
    let currentStudentSearchPage = 1;
    let currentStudentSearchFilters = {};

    function openTeacherStudentSearchModal() {
        document.getElementById('teacherStudentSearchModal').style.display = 'flex';
        document.getElementById('teacherStudentSearchInput').value = '';
        document.getElementById('teacherStudentFilterGrade').value = '';
        document.getElementById('teacherStudentFilterSection').value = '';
        document.getElementById('teacherStudentFilterSection').innerHTML = '<option value="">All Sections</option>';
        currentStudentSearchPage = 1;
        currentStudentSearchFilters = {};
        searchTeacherStudents();
    }

    function closeTeacherStudentSearchModal() {
        document.getElementById('teacherStudentSearchModal').style.display = 'none';
    }

    function searchTeacherStudents() {
        const search = document.getElementById('teacherStudentSearchInput').value;
        const grade = document.getElementById('teacherStudentFilterGrade').value;
        const section = document.getElementById('teacherStudentFilterSection').value;
        
        currentStudentSearchFilters = { search, grade, section };
        currentStudentSearchPage = 1;
        performTeacherStudentSearch();
    }

    function performTeacherStudentSearch() {
        const resultsDiv = document.getElementById('teacherStudentSearchResults');
        resultsDiv.innerHTML = '<div class="text-center p-10 text-gray-500">Searching...</div>';
        
        const formData = new FormData();
        formData.append('action', 'search_students');
        formData.append('search', currentStudentSearchFilters.search || '');
        formData.append('grade_filter', currentStudentSearchFilters.grade || '');
        formData.append('section_filter', currentStudentSearchFilters.section || '');
        formData.append('per_page', '10');
        formData.append('page', currentStudentSearchPage.toString());
        
        fetch(window.location.href, { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayTeacherStudentResults(data);
                } else {
                    const msg = data && data.message ? String(data.message) : 'Error loading students';
                    resultsDiv.innerHTML = '<div class="text-center p-10 text-red-500">Error loading students<br><span class="text-xs text-red-400 break-words">' + msg.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span></div>';
                }
            })
            .catch(error => {
                console.error(error);
                resultsDiv.innerHTML = '<div class="text-center p-10 text-red-500">Error loading students<br><span class="text-xs text-red-400 break-words">' + (error?.message ? String(error.message) : 'Network error') + '</span></div>';
            });
    }

    function displayTeacherStudentResults(data) {
        const resultsDiv = document.getElementById('teacherStudentSearchResults');
        const paginationDiv = document.getElementById('teacherStudentSearchPagination');
        
        // Fixed Filtering: Filter results down to students only present in the teacher's localized list
        const localStudentIds = teacherHomeStudents.map(s => s.id.toString());
        const filteredStudents = data.students.filter(s => localStudentIds.includes(s.id.toString()));
        
        if (filteredStudents.length === 0) {
            resultsDiv.innerHTML = '<div class="text-center p-10 text-gray-500">No students found assigned to you</div>';
            paginationDiv.classList.add('hidden');
            return;
        }
        
        let html = '';
        filteredStudents.forEach(student => {
            html += `
                <div class="student-item p-4 border-b border-gray-200 hover:bg-gray-50 cursor-pointer flex flex-col sm:flex-row sm:justify-between sm:items-center" onclick="selectTeacherStudent(${student.id}, '${student.full_name.replace(/'/g, "\\'")}')">
                    <div>
                        <div class="font-semibold text-[#0a2d63]">${student.full_name}</div>
                        <div class="text-sm text-gray-600 break-all">${student.email}</div>
                    </div>
                    <div class="text-sm text-gray-500 mt-1 sm:mt-0 font-medium">Grade ${student.grade_level} - ${student.section}</div>
                </div>
            `;
        });
        resultsDiv.innerHTML = html;
        
        // Pagination logic (simplified for local filtering, though ideally API should be scoped to teacher)
        if (data.total_pages > 1) {
            let paginationHtml = '<div class="flex flex-wrap justify-center gap-1">';
            for (let i = 1; i <= data.total_pages; i++) {
                const activeClass = i === data.page ? 'bg-[#0a2d63] text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300';
                paginationHtml += `<button class="px-3 py-1 rounded min-w-[32px] ${activeClass}" onclick="changeTeacherStudentPage(${i})">${i}</button>`;
            }
            paginationHtml += '</div>';
            document.getElementById('teacherStudentSearchButtons').innerHTML = paginationHtml;
            document.getElementById('teacherStudentSearchInfo').innerHTML = `Page ${data.page} of ${data.total_pages}`;
            paginationDiv.classList.remove('hidden');
        } else {
            paginationDiv.classList.add('hidden');
        }
    }

    function changeTeacherStudentPage(page) {
        currentStudentSearchPage = page;
        performTeacherStudentSearch();
    }

    function selectTeacherStudent(id, name) {
        const tbody = document.getElementById('teacherPerformanceTableBody');
        tbody.innerHTML = '<tr><td colspan="2" class="p-3 text-center">Loading...</td></tr>';

        fetch(`php/teacher_actions.php?action=get_student_grades&student_id=${id}`)
            .then(response => parseJsonResponse(response))
            .then(data => {
                tbody.innerHTML = '';
                if (data.length > 0) {
                    data.forEach(rec => {
                        tbody.innerHTML += `
                            <tr class="border-b">
                                <td class="p-3">${escapeHtml(rec.subject_name)}</td>
                                <td class="p-3 font-bold text-center">${rec.grade}</td>
                            </tr>`;
                    });
                } else {
                    tbody.innerHTML = '<tr><td colspan="2" class="p-3 text-center">No grades recorded for this student.</td></tr>';
                }
            })
            .catch(error => {
                tbody.innerHTML = '<tr><td colspan="2" class="p-3 text-center text-red-500">Error loading grades.</td></tr>';
            });

        closeTeacherStudentSearchModal();
    }

    function clearTeacherStudentFilter() {
        document.getElementById('clearTeacherStudentFilterBtn').classList.add('hidden');
        document.getElementById('teacherStudentFilterLabel').textContent = '';
        filterTeacherPerformanceByStudent(null, null);
    }

    function filterTeacherPerformanceByStudent(studentId, studentName) {
        const table = document.getElementById('teacherPerformanceTable');
        const rows = table.querySelectorAll('tbody tr');
        const clearBtn = document.getElementById('clearTeacherStudentFilterBtn');
        const filterLabel = document.getElementById('teacherStudentFilterLabel');
        const cards = document.querySelectorAll('.teacher-student-card');
        
        if (!studentId) {
            // Show all rows and hide all single-student cards
            rows.forEach(row => row.style.display = '');
            cards.forEach(card => card.style.display = 'none');
            clearBtn.classList.add('hidden');
            filterLabel.textContent = '';
            teacherSelectedStudentId = null;
            return;
        }
        
        teacherSelectedStudentId = studentId;
        rows.forEach(row => {
            const studentCell = row.cells[0];
            if (studentCell && studentCell.textContent.trim() === studentName) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
        
        // Show corresponding extracurricular and disciplinary cards
        cards.forEach(card => {
            if (card.getAttribute('data-student-id') === String(studentId)) {
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        });
        
        clearBtn.classList.remove('hidden');
        filterLabel.textContent = `Showing: ${studentName}`;
    }

    function updateTeacherStudentFilterSections() {
        const grade = document.getElementById('teacherStudentFilterGrade').value;
        const sectionSelect = document.getElementById('teacherStudentFilterSection');
        
        if (!grade) {
            sectionSelect.innerHTML = '<option value="">All Sections</option>';
            return;
        }
        
        const gradeSections = {
            'Grade 7': ['Love', 'Joy'],
            'Grade 8': ['Patience', 'Peace'],
            'Grade 9': ['Goodness', 'Kindness'],
            'Grade 10': ['Gentleness', 'Faithfulness'],
            'Grade 11': ['Self-Control', 'Honesty'],
            'Grade 12': ['Humility', 'Meekness']
        };
        const sections = gradeSections[grade] || [];
        let html = '<option value="">All Sections</option>';
        sections.forEach(section => {
            html += `<option value="${section}">${section}</option>`;
        });
        sectionSelect.innerHTML = html;
    }

    // ==================== STUDENT SELECT MODAL FUNCTIONS ====================
    function openStudentSelectModal() {
        document.getElementById('studentSelectModal').style.display = 'flex';
        filterStudentsForSelect();
    }

    function closeStudentSelectModal() {
        document.getElementById('studentSelectModal').style.display = 'none';
    }

    function filterStudentsForSelect() {
        const search = document.getElementById('studentSearchInput').value;
        const grade = document.getElementById('studentFilterGrade').value;
        const section = document.getElementById('studentFilterSection').value;
        const perPageSelection = document.getElementById('studentResultsPerPage').value;
        const customPerPage = parseInt(document.getElementById('studentCustomPerPage')?.value || '', 10);
        let perPage = parseInt(perPageSelection, 10);
        if (perPageSelection === 'custom') {
            perPage = Number.isFinite(customPerPage) && customPerPage > 0 ? customPerPage : 10;
        }
        perPage = Math.min(100, Math.max(1, perPage || 10));
        
        const resultsDiv = document.getElementById('studentSelectResults');
        resultsDiv.innerHTML = '<div class="text-center p-10 text-gray-500">Loading students...</div>';
        
        const formData = new FormData();
        formData.append('action', 'search_students');
        formData.append('search', search);
        formData.append('grade_filter', grade);
        formData.append('section_filter', section);
        formData.append('per_page', String(perPage));
        formData.append('page', '1');
        
        fetch(window.location.href, { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayStudentSelectResults(data);
                } else {
                    const msg = data && data.message ? String(data.message) : 'Error loading students';
                    resultsDiv.innerHTML = '<div class="text-center p-10 text-red-500">Error loading students<br><span class="text-xs text-red-400 break-words">' + msg.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span></div>';
                }
            })
            .catch(error => {
                console.error(error);
                resultsDiv.innerHTML = '<div class="text-center p-10 text-red-500">Error loading students<br><span class="text-xs text-red-400 break-words">' + (error?.message ? String(error.message) : 'Network error') + '</span></div>';
            });
    }

    function displayStudentSelectResults(data) {
        const resultsDiv = document.getElementById('studentSelectResults');
        if (data.students.length === 0) {
            resultsDiv.innerHTML = '<div class="text-center p-10 text-gray-500">No students found</div>';
            return;
        }
        
        let html = '';
        data.students.forEach(student => {
            html += `
                <div class="student-item p-4 border-b border-gray-200 hover:bg-gray-50 cursor-pointer flex flex-col sm:flex-row sm:justify-between sm:items-center" onclick="selectStudent(${student.id}, '${student.full_name.replace(/'/g, "\\'")}')">
                    <div>
                        <div class="font-semibold text-[#0a2d63]">${student.full_name}</div>
                        <div class="text-sm text-gray-600 break-all">${student.email}</div>
                    </div>
                    <div class="text-sm text-gray-500 mt-1 sm:mt-0 font-medium">Grade ${student.grade_level} - ${student.section}</div>
                </div>
            `;
        });
        resultsDiv.innerHTML = html;
    }

    function selectStudent(id, name) {
        document.getElementById('studentSelect').value = id;
        document.getElementById('selectedStudentName').value = name;
        closeStudentSelectModal();

        // Auto-fill fee totals in Payables Calculator (if present)
        const tuitionFeeInput = document.getElementById('tuitionFee');
        const downPaymentInput = document.getElementById('downPayment');
        if (tuitionFeeInput || downPaymentInput) {
            fetch('php/get_student_payables.php?student_id=' + id)
                .then(parseJsonResponse)
                .then(data => {
                    const t = data?.totals || null;
                    if (!t) return;
                    if (tuitionFeeInput) {
                        tuitionFeeInput.value = (parseFloat(t.fee_total || 0) || 0).toFixed(2);
                    }
                    if (downPaymentInput) {
                        downPaymentInput.value = (parseFloat(t.downpayment_total || 0) || 0).toFixed(2);
                    }
                })
                .catch(err => console.error('Auto-fill fee totals failed:', err));
        }
    }

    function updateStudentFilterSections() {
        const grade = document.getElementById('studentFilterGrade').value;
        const sectionSelect = document.getElementById('studentFilterSection');
        
        if (!grade) {
            sectionSelect.innerHTML = '<option value="">All Sections</option>';
            return;
        }
        
        // Get sections for this grade
        const gradeSections = {
            'Grade 7': ['Love', 'Joy'],
            'Grade 8': ['Patience', 'Peace'],
            'Grade 9': ['Goodness', 'Kindness'],
            'Grade 10': ['Gentleness', 'Faithfulness'],
            'Grade 11': ['Self-Control', 'Honesty'],
            'Grade 12': ['Humility', 'Meekness']
        };
        const sections = gradeSections[grade] || [];
        let html = '<option value="">All Sections</option>';
        sections.forEach(section => {
            html += `<option value="${section}">${section}</option>`;
        });
        sectionSelect.innerHTML = html;
    }

    function toggleStudentCustomPerPage() {
        const select = document.getElementById('studentResultsPerPage');
        const customContainer = document.getElementById('studentCustomPerPageContainer');
        
        if (select.value === 'custom') {
            customContainer.classList.remove('hidden');
        } else {
            customContainer.classList.add('hidden');
        }
    }

    function applyStudentCustomPerPage() {
        const customInput = document.getElementById('studentCustomPerPage');
        const select = document.getElementById('studentResultsPerPage');
        
        if (customInput.value && customInput.value > 0) {
            select.value = 'custom';
            filterStudentsForSelect();
        }
    }

    // ==================== PAYMENT ENROLLEE BROWSE MODAL ====================
    function openPaymentEnrolleeBrowseModal() {
        const modal = document.getElementById('paymentEnrolleeSelectModal');
        if (!modal) return;
        modal.style.display = 'flex';
        const input = document.getElementById('paymentEnrolleeSearchInput');
        if (input) input.value = '';
        const results = document.getElementById('paymentEnrolleeSelectResults');
        if (results) results.innerHTML = '<div class="text-center p-10 text-gray-500">Loading enrollees...</div>';

        fetch('php/get_pending_enrollees.php')
            .then(r => r.json())
            .then(data => {
                if (data.success && Array.isArray(data.enrollments)) {
                    window.paymentEnrolleesCache = data.enrollments.map(e => ({
                        id: String(e.id),
                        name: String(e.full_name || '').trim(),
                        grade: String(e.grade_level || ''),
                        downpayment_total: parseFloat(e.downpayment_total || 0)
                    })).filter(e => e.name !== '');
                    filterPaymentEnrolleesInModal();
                } else {
                    window.paymentEnrolleesCache = [];
                    if (results) results.innerHTML = '<div class="text-center p-10 text-gray-500">No enrollees found.</div>';
                }
            })
            .catch(err => {
                console.error(err);
                window.paymentEnrolleesCache = [];
                if (results) results.innerHTML = '<div class="text-center p-10 text-red-500">Error loading enrollees</div>';
            });
    }

    function closePaymentEnrolleeBrowseModal() {
        const modal = document.getElementById('paymentEnrolleeSelectModal');
        if (modal) modal.style.display = 'none';
    }

    function filterPaymentEnrolleesInModal() {
        const query = (document.getElementById('paymentEnrolleeSearchInput')?.value || '').trim().toLowerCase();
        const grade = document.getElementById('paymentEnrolleeFilterGrade')?.value || '';
        const results = document.getElementById('paymentEnrolleeSelectResults');
        if (!results) return;
        
        let filtered = window.paymentEnrolleesCache || [];
        if (query) {
            filtered = filtered.filter(e => e.name.toLowerCase().includes(query) || e.email.toLowerCase().includes(query));
        }
        if (grade) {
            filtered = filtered.filter(e => e.grade === grade);
        }

        if (filtered.length === 0) {
            results.innerHTML = '<div class="text-center p-10 text-gray-500">No enrollees found.</div>';
            return;
        }

        let html = '';
        filtered.forEach(e => {
            const dp = e.downpayment_total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            html += `
                <div class="student-item p-4 border-b border-gray-200 hover:bg-gray-50 cursor-pointer flex flex-col sm:flex-row sm:justify-between sm:items-center" onclick="selectPaymentEnrollee('${e.id}', '${e.name.replace(/'/g, "\\'")}')">
                    <div>
                        <div class="font-semibold text-[#0a2d63]">${e.name}</div>
                        <div class="text-sm text-gray-600">Grade: ${e.grade || '-'}</div>
                    </div>
                    <div class="text-sm text-gray-500 mt-1 sm:mt-0 font-medium">Downpayment: ₱${dp}</div>
                </div>
            `;
        });
        results.innerHTML = html;
    }

    function selectPaymentEnrollee(id, name) {
        setPaymentEnrolleeSelection({ id, name });
        closePaymentEnrolleeBrowseModal();
    }

    // ==================== PAYMENT STUDENT BROWSE MODAL ====================
    function openPaymentStudentBrowseModal() {
        const modal = document.getElementById('paymentStudentSelectModal');
        if (!modal) return;
        modal.style.display = 'flex';
        const input = document.getElementById('paymentStudentSearchInput');
        if (input) input.value = '';
        const results = document.getElementById('paymentStudentSelectResults');
        if (results) results.innerHTML = '<div class="text-center p-10 text-gray-500">Loading students...</div>';

        fetch('php/get_users.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({})
        })
            .then(r => r.json())
            .then(data => {
                if (data.success && Array.isArray(data.users)) {
                    window.paymentStudentsCache = data.users
                        .filter(u => u.role === 'student')
                        .map(u => ({
                            id: String(u.id),
                            name: String(u.full_name || '').trim(),
                            email: String(u.email || ''),
                            grade_level: String(u.grade_level || ''),
                            section: String(u.section || ''),
                        }))
                        .filter(s => s.name !== '');
                    filterPaymentStudentsInModal();
                } else {
                    window.paymentStudentsCache = [];
                    if (results) results.innerHTML = '<div class="text-center p-10 text-gray-500">No students found.</div>';
                }
            })
            .catch(err => {
                console.error(err);
                window.paymentStudentsCache = [];
                if (results) results.innerHTML = '<div class="text-center p-10 text-red-500">Error loading students</div>';
            });
    }

    function closePaymentStudentBrowseModal() {
        const modal = document.getElementById('paymentStudentSelectModal');
        if (modal) modal.style.display = 'none';
    }

    function updatePaymentStudentSections() {
        const grade = document.getElementById('paymentStudentFilterGrade')?.value;
        const sectionSelect = document.getElementById('paymentStudentFilterSection');
        if (!sectionSelect) return;
        
        if (!grade) {
            sectionSelect.innerHTML = '<option value="">All Sections</option>';
            return;
        }
        
        const gradeSections = {
            'Grade 7': ['Love', 'Joy'],
            'Grade 8': ['Patience', 'Peace'],
            'Grade 9': ['Goodness', 'Kindness'],
            'Grade 10': ['Gentleness', 'Faithfulness'],
            'Grade 11': ['Self-Control', 'Honesty'],
            'Grade 12': ['Humility', 'Meekness']
        };
        const sections = gradeSections[grade] || [];
        let html = '<option value="">All Sections</option>';
        sections.forEach(section => {
            html += `<option value="${section}">${section}</option>`;
        });
        sectionSelect.innerHTML = html;
    }

    function filterPaymentStudentsInModal() {
        const query = (document.getElementById('paymentStudentSearchInput')?.value || '').trim().toLowerCase();
        const grade = document.getElementById('paymentStudentFilterGrade')?.value || '';
        const section = document.getElementById('paymentStudentFilterSection')?.value || '';
        const results = document.getElementById('paymentStudentSelectResults');
        if (!results) return;
        
        let filtered = window.paymentStudentsCache || [];
        if (query) {
            filtered = filtered.filter(s => s.name.toLowerCase().includes(query) || s.email.toLowerCase().includes(query));
        }
        if (grade) {
            filtered = filtered.filter(s => s.grade_level === grade);
        }
        if (section) {
            filtered = filtered.filter(s => s.section === section);
        }

        if (filtered.length === 0) {
            results.innerHTML = '<div class="text-center p-10 text-gray-500">No students found.</div>';
            return;
        }

        let html = '';
        filtered.forEach(s => {
            html += `
                <div class="student-item p-4 border-b border-gray-200 hover:bg-gray-50 cursor-pointer flex flex-col sm:flex-row sm:justify-between sm:items-center" onclick="selectPaymentStudent('${s.id}', '${s.name.replace(/'/g, "\\'")}')">
                    <div>
                        <div class="font-semibold text-[#0a2d63]">${s.name}</div>
                        <div class="text-sm text-gray-600 break-all">${s.email}</div>
                    </div>
                    <div class="text-sm text-gray-500 mt-1 sm:mt-0 font-medium">Grade ${s.grade_level} - ${s.section}</div>
                </div>
            `;
        });
        results.innerHTML = html;
    }

    function selectPaymentStudent(id, name) {
        setPaymentStudentSelection({ id, name });
        closePaymentStudentBrowseModal();
    }
