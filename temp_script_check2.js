// ---- State ----
let _geSubjectId = null, _geSection = null, _geGradeLevel = null, _geSubjectName = '';
let _geSemester = 1;
// activities per semester: { 1:[{type,label,max},...], 2:[...], 3:[...] }
let _geActivities = { 1:[], 2:[], 3:[] };
let _geStudents = []; // [{id, full_name, sem1, sem2, sem3}]
let _geInputCache = {}; // {studentId_actIdx: value} — preserves inputs across re-renders

const SEM_LABELS = { 1:'1st Semester', 2:'2nd Semester', 3:'3rd Semester' };
const ACT_COLORS = { quiz:'blue', essay:'purple', recitation:'green', periodic:'orange' };
const ACT_LABELS = { quiz:'Quiz', essay:'Essay', recitation:'Recitation', periodic:'Periodic Test' };

// XSS-safe HTML escaping
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// ---- Section → Subject filter ----
function filterGradeSubjectsBySection() {
    const sel = document.getElementById('gradeSectionSelect');
    if (!sel) return;
    const section = sel.value;
    const gradeOpt = sel.options[sel.selectedIndex];
    const grade = gradeOpt ? gradeOpt.dataset.grade : '';
    const subjectSel = document.getElementById('gradeSubjectSelect');
    if (!subjectSel) return;
    Array.from(subjectSel.options).forEach(opt => {
        if (!opt.value) { opt.style.display = ''; return; }
        const match = (!section || opt.dataset.section === section) &&
                      (!grade   || opt.dataset.grade   === grade);
        opt.style.display = match ? '' : 'none';
    });
    // reset subject pick
    subjectSel.value = '';
    // auto-select first visible subject
    const first = Array.from(subjectSel.options).find(o => o.value && o.style.display !== 'none');
    if (first) { subjectSel.value = first.value; autoLoadGradeStudents(); }
}

// ---- Auto-load on subject change ----
function autoLoadGradeStudents() {
    const subjectId = document.getElementById('gradeSubjectSelect')?.value;
    if (!subjectId) return;
    loadGradeStudents();
}

// ---- Core loader ----
function loadGradeStudents() {
    const subjectSel = document.getElementById('gradeSubjectSelect');
    const sectionSel = document.getElementById('gradeSectionSelect');
    if (!subjectSel || !sectionSel) return;
    const subjectId = subjectSel.value;
    const sectionOpt = sectionSel.options[sectionSel.selectedIndex];
    const section = sectionSel.value;
    const gradeLevel = sectionOpt ? sectionOpt.dataset.grade : '';
    if (!subjectId || !section || !gradeLevel) return;

    const subjectOpt = subjectSel.options[subjectSel.selectedIndex];
    _geSubjectName = subjectOpt ? subjectOpt.dataset.name : 'Subject';
    _geSubjectId = subjectId;
    _geSection = section;
    _geGradeLevel = gradeLevel;

    const container = document.getElementById('gradeEncodingTableContainer');
    if (container) container.innerHTML = '<p class="text-center py-10 text-gray-400">Loading students…</p>';
    _geInputCache = {}; // clear stale cached inputs

    const fd = new FormData();
    fd.append('action', 'get_grade_students');
    fd.append('subject_id', subjectId);
    fd.append('section', section);
    fd.append('grade_level', gradeLevel);

    fetch('php/teacher_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { if (container) container.innerHTML = `<p class="text-red-500 text-center py-10">${data.message||'Error loading students'}</p>`; return; }
            if (data.debug) console.log('Grade Encoding Debug:', data.debug);
            _geStudents = data.students || [];
            _geSemester = data.current_semester || 1;
            // Try restore session activities
            const saved = loadGradeSession();
            if (saved) { _geActivities = saved.activities || {1:[],2:[],3:[]}; _geSemester = saved.semester || _geSemester; }
            else { _geActivities = {1:[],2:[],3:[]}; }
            // Pre-populate activities from existing DB data for current sem
            if (_geStudents.length && _geActivities[_geSemester].length === 0) {
                _geActivities = _geActivities || {1:[],2:[],3:[]};
                const sample = _geStudents[0]['sem'+_geSemester];
                if (sample) {
                    if (sample.quiz_score !== null)       _geActivities[_geSemester].push({type:'quiz',      label:ACT_LABELS.quiz,      max: sample.quiz_max||100});
                    if (sample.essay_score !== null)      _geActivities[_geSemester].push({type:'essay',     label:ACT_LABELS.essay,     max: sample.essay_max||100});
                    if (sample.recitation_score !== null) _geActivities[_geSemester].push({type:'recitation',label:ACT_LABELS.recitation, max: sample.recitation_max||100});
                    if (sample.periodic_test_score!==null)_geActivities[_geSemester].push({type:'periodic',  label:ACT_LABELS.periodic,  max: sample.periodic_test_max||100});
                }
            }
            // Show UI
            document.getElementById('addActivityBtn')?.classList.remove('hidden');
            document.getElementById('semesterTabsRow')?.classList.remove('hidden');
            document.getElementById('gradeSaveRow')?.classList.remove('hidden');
            document.getElementById('gradeEncodingSubjectLabel').textContent = _geSubjectName + ' — ' + gradeLevel + ' ' + section;
            updateSemTabs();
            renderGradeTable();
        })
        .catch(err => { console.error(err); if (container) container.innerHTML = '<p class="text-red-500 text-center py-10">Network error</p>'; });
}

// ---- Semester tab switch ----
function switchSemester(sem) {
    snapshotInputCache(); // save current inputs before switching
    _geSemester = sem;
    _geInputCache = {}; // clear cache — new semester has different activity indices
    updateSemTabs();
    renderGradeTable();
    saveGradeSession();
}

function updateSemTabs() {
    document.querySelectorAll('.sem-tab').forEach(btn => {
        const active = parseInt(btn.dataset.sem) === _geSemester;
        btn.className = 'sem-tab px-5 py-2 text-sm font-semibold rounded-t-lg border border-b-0 border-gray-200 transition ' +
            (active ? 'bg-[#0a2d63] text-white' : 'bg-white text-gray-600 hover:text-[#0a2d63]');
    });
}

// ---- Render the grade table ----
function renderGradeTable() {
    const container = document.getElementById('gradeEncodingTableContainer');
    if (!container) return;
    if (!_geStudents.length) {
        container.innerHTML = '<p class="text-center py-14 text-gray-400">No students found for ' + (_geGradeLevel || 'this grade') + ' — ' + (_geSection || 'this section') + '.</p>';
        return;
    }
    const acts = _geActivities[_geSemester] || [];
    const semData = 'sem' + _geSemester;
    const isSubmitted = _geStudents.some(s => s[semData] && s[semData].is_submitted == 1);

    let html = '<div class="overflow-x-auto">';
    if (isSubmitted) html += '<div class="mb-3 px-4 py-2 bg-green-50 border border-green-200 rounded-lg text-green-800 text-sm font-medium">✓ Grades for ' + SEM_LABELS[_geSemester] + ' have been submitted to the Registrar and are locked.</div>';
    html += '<table class="min-w-full border-collapse text-sm">';

    // Header
    html += '<thead><tr class="bg-[#0a2d63] text-white">';
    html += '<th class="p-3 text-left font-semibold sticky left-0 bg-[#0a2d63] z-10 min-w-[180px]">#  Student Name</th>';
    acts.forEach((act, i) => {
        const colorMap = {quiz:'blue',essay:'purple',recitation:'green',periodic:'orange'};
        const c = colorMap[act.type] || 'gray';
        html += `<th class="p-3 text-center font-semibold min-w-[120px] relative">
            ${isSubmitted ? '' : `<button onclick="removeActivityColumn(${i})" class="absolute top-1 right-1 w-4 h-4 rounded-full bg-white bg-opacity-20 hover:bg-opacity-40 text-white text-[10px] leading-none flex items-center justify-center transition" title="Remove this activity">✕</button>`}
            <div class="text-xs font-bold uppercase tracking-wide mt-2">${act.label}</div>
            <div class="mt-1 flex items-center justify-center gap-1 text-xs font-normal opacity-80">
                /<input type="number" min="1" max="999" value="${act.max}" class="w-14 text-center bg-white bg-opacity-20 border border-white border-opacity-30 rounded px-1 py-0.5 text-white"
                    ${isSubmitted?'disabled':''} onchange="updateActMax(${i},this.value)" />pts
            </div>
        </th>`;
    });
    html += '<th class="p-3 text-center font-semibold min-w-[100px]">Grade</th>';
    html += '<th class="p-3 text-center font-semibold min-w-[90px]">Prev Sems</th>';
    html += '</tr></thead><tbody>';

    _geStudents.forEach((s, idx) => {
        const dbRow = s[semData] || {};
        const scoreMap = { quiz: dbRow.quiz_score, essay: dbRow.essay_score, recitation: dbRow.recitation_score, periodic: dbRow.periodic_test_score };
        const calcGrade = dbRow.calculated_grade;
        const rowBg = idx % 2 === 0 ? 'bg-white' : 'bg-gray-50';

        html += `<tr class="${rowBg} border-b border-gray-200 hover:bg-blue-50 transition">`;
        html += `<td class="p-3 font-medium text-gray-800 sticky left-0 ${rowBg} z-10">${idx+1}. ${escapeHtml(s.full_name)}</td>`;
        acts.forEach((act, i) => {
            // Prefer cached user input, then DB data
            const cacheKey = s.id + '_' + i;
            let val = '';
            if (_geInputCache[cacheKey] !== undefined) { val = _geInputCache[cacheKey]; }
            else if (scoreMap[act.type] !== null && scoreMap[act.type] !== undefined) { val = scoreMap[act.type]; }
            html += `<td class="p-2 text-center">
                <input type="number" min="0" max="${act.max}" step="0.5"
                    class="grade-score-input w-20 text-center border border-gray-300 rounded px-2 py-1.5 focus:ring-2 focus:ring-blue-400 outline-none"
                    data-student-id="${s.id}" data-act-idx="${i}" data-act-type="${act.type}"
                    value="${val}" ${isSubmitted?'disabled':''}
                    oninput="clampAndCalc(this, ${act.max}, ${s.id})" />
            </td>`;
        });
        const gradeDisp = calcGrade !== null && calcGrade !== undefined ? parseFloat(calcGrade).toFixed(2) : '—';
        const gradeColor = calcGrade === null ? 'text-gray-400' : (parseFloat(calcGrade) >= 75 ? 'text-green-700' : 'text-red-600');
        html += `<td class="p-3 text-center font-bold ${gradeColor}" id="grade-cell-${s.id}">${gradeDisp}</td>`;

        // Previous semesters
        let prevHtml = '';
        [1,2,3].forEach(ps => {
            if (ps !== _geSemester) {
                const pg = s['sem'+ps]?.calculated_grade;
                if (pg !== null && pg !== undefined) prevHtml += `<div class="text-xs text-gray-500">S${ps}: ${parseFloat(pg).toFixed(1)}</div>`;
            }
        });
        html += `<td class="p-3 text-center">${prevHtml||'<span class="text-xs text-gray-300">—</span>'}</td>`;
        html += '</tr>';
    });
    html += '</tbody></table></div>';
    container.innerHTML = html;
    updateGradeStats();
}

// ---- Activity operations ----
function openAddActivityModal() {
    document.getElementById('addActivityModal').style.display = 'flex';
}

function addActivityColumn(type) {
    document.getElementById('addActivityModal').style.display = 'none';
    // Save current input values before re-render
    snapshotInputCache();
    const acts = _geActivities[_geSemester];
    const count = acts.filter(a => a.type === type).length + 1;
    acts.push({ type, label: ACT_LABELS[type] + ' ' + count, max: 100 });
    renderGradeTable();
    saveGradeSession();
}

function removeActivityColumn(index) {
    if (!confirm('Remove this activity column? This will delete all grades entered for it.')) return;
    snapshotInputCache();
    const acts = _geActivities[_geSemester];
    if (index >= 0 && index < acts.length) {
        acts.splice(index, 1);
        const newCache = {};
        for (const key in _geInputCache) {
            const parts = key.split('_');
            const sId = parts[0];
            const actIdx = parseInt(parts[1]);
            if (actIdx < index) {
                newCache[key] = _geInputCache[key];
            } else if (actIdx > index) {
                newCache[`${sId}_${actIdx - 1}`] = _geInputCache[key];
            }
        }
        _geInputCache = newCache;
        renderGradeTable();
        saveGradeSession();
    }
}

// Clamp input value to 0–max, then recalculate
function clampAndCalc(input, max, studentId) {
    let v = parseFloat(input.value);
    if (isNaN(v)) { calcRowGrade(studentId); return; }
    if (v < 0)   { input.value = 0;   v = 0; }
    if (v > max) { input.value = max;  v = max; }
    calcRowGrade(studentId);
}

// Snapshot all current input values into cache
function snapshotInputCache() {
    _geInputCache = {};
    document.querySelectorAll('.grade-score-input').forEach(inp => {
        const key = inp.dataset.studentId + '_' + inp.dataset.actIdx;
        _geInputCache[key] = inp.value;
    });
}

function updateActMax(actIdx, val) {
    const acts = _geActivities[_geSemester];
    if (acts[actIdx]) { acts[actIdx].max = parseInt(val) || 100; calcAllGrades(); saveGradeSession(); }
}

// ---- Per-row grade calculation ----
function calcRowGrade(studentId) {
    const acts = _geActivities[_geSemester];
    const groups = { quiz:[], essay:[], recitation:[], periodic:[] };
    acts.forEach((act, i) => {
        const inp = document.querySelector(`.grade-score-input[data-student-id="${studentId}"][data-act-idx="${i}"]`);
        if (inp && inp.value !== '') {
            const score = parseFloat(inp.value);
            const pct = score / (act.max || 100) * 100;
            groups[act.type].push(pct);
        }
    });
    const groupComp = [];
    ['quiz','essay','recitation'].forEach(t => { if (groups[t].length) groupComp.push(groups[t].reduce((a,b)=>a+b,0)/groups[t].length); });
    let ws = 0, tw = 0;
    if (groupComp.length) { ws += (groupComp.reduce((a,b)=>a+b,0)/groupComp.length)*0.30; tw += 0.30; }
    if (groups.periodic.length) { ws += (groups.periodic.reduce((a,b)=>a+b,0)/groups.periodic.length)*0.40; tw += 0.40; }
    const grade = tw > 0 ? (ws/tw).toFixed(2) : null;
    const cell = document.getElementById('grade-cell-' + studentId);
    if (cell) {
        cell.textContent = grade !== null ? grade : '—';
        cell.className = 'p-3 text-center font-bold ' + (grade === null ? 'text-gray-400' : (parseFloat(grade) >= 75 ? 'text-green-700' : 'text-red-600'));
    }
    updateGradeStats();
    saveGradeSession();
}

function calcAllGrades() { _geStudents.forEach(s => calcRowGrade(s.id)); }

// ---- Stats ----
function updateGradeStats() {
    const grades = [];
    _geStudents.forEach(s => {
        const cell = document.getElementById('grade-cell-' + s.id);
        if (cell && cell.textContent !== '—') grades.push(parseFloat(cell.textContent));
    });
    const avg = grades.length ? (grades.reduce((a,b)=>a+b,0)/grades.length).toFixed(2) : '—';
    const pass = grades.length ? Math.round(grades.filter(g=>g>=75).length/grades.length*100)+'%' : '—';
    const high = grades.length ? Math.max(...grades).toFixed(2) : '—';
    const ca = document.getElementById('classAvg'); if(ca) ca.textContent = avg;
    const pr = document.getElementById('passRate'); if(pr) pr.textContent = pass;
    const hg = document.getElementById('highGrade'); if(hg) hg.textContent = high;
}

// ---- Session saver ----
function saveGradeSession() {
    if (!_geSubjectId) return;
    const scores = {};
    document.querySelectorAll('.grade-score-input').forEach(inp => {
        const sid = inp.dataset.studentId;
        const idx = inp.dataset.actIdx;
        if (!scores[sid]) scores[sid] = {};
        scores[sid][idx] = inp.value;
    });
    localStorage.setItem('baa_grade_' + _geSubjectId, JSON.stringify({
        subjectId: _geSubjectId, section: _geSection, gradeLevel: _geGradeLevel,
        semester: _geSemester, activities: _geActivities, scores
    }));
}

function loadGradeSession() {
    if (!_geSubjectId) return null;
    try { return JSON.parse(localStorage.getItem('baa_grade_' + _geSubjectId)); } catch { return null; }
}

// ---- Save All Grades ----
function saveAllGrades() {
    if (!_geSubjectId || !_geStudents.length) { alert('No students loaded.'); return; }
    const acts = _geActivities[_geSemester];
    const gradesPayload = _geStudents.map(s => {
        const groups = { quiz:[], essay:[], recitation:[], periodic:[] };
        const maxGroups = { quiz:[], essay:[], recitation:[], periodic:[] };
        acts.forEach((act, i) => {
            const inp = document.querySelector(`.grade-score-input[data-student-id="${s.id}"][data-act-idx="${i}"]`);
            if (inp && inp.value !== '') {
                groups[act.type].push(parseFloat(inp.value));
                maxGroups[act.type].push(act.max || 100);
            }
        });
        const avg = (arr) => arr.length ? arr.reduce((a,b)=>a+b,0)/arr.length : null;
        return {
            student_id: s.id,
            quiz: avg(groups.quiz), essay: avg(groups.essay),
            recitation: avg(groups.recitation), periodic: avg(groups.periodic)
        };
    });
    const maxPoints = {};
    const types = ['quiz','essay','recitation','periodic'];
    types.forEach(t => {
        const typeActs = acts.filter(a=>a.type===t);
        maxPoints[t] = typeActs.length ? typeActs.reduce((a,b)=>a+b.max,0)/typeActs.length : 100;
    });

    const fd = new FormData();
    fd.append('action', 'save_grades');
    fd.append('data', JSON.stringify({ subject_id: _geSubjectId, semester: _geSemester, grades: gradesPayload, max_points: maxPoints }));

    fetch('php/teacher_actions.php', { method:'POST', body:fd })
        .then(r=>r.json())
        .then(d => {
            if (d.success) {
                showToast('Grades saved successfully!', 'green');
                saveGradeSession();
                // Show submit modal
                setTimeout(() => {
                    document.getElementById('submitModalSubjectName').textContent = _geSubjectName;
                    document.getElementById('submitModalSemName').textContent = SEM_LABELS[_geSemester];
                    document.getElementById('submitGradesModal').style.display = 'flex';
                }, 600);
            } else { showToast('Error: ' + (d.message||'Could not save'), 'red'); }
        })
        .catch(()=>showToast('Network error saving grades','red'));
}

// ---- Submit to Registrar ----
function submitGradesToRegistrar() {
    document.getElementById('submitGradesModal').style.display = 'none';
    const fd = new FormData();
    fd.append('action', 'submit_grades_to_registrar');
    fd.append('subject_id', _geSubjectId);
    fd.append('semester', _geSemester);
    fetch('php/teacher_actions.php', { method:'POST', body:fd })
        .then(r=>r.json())
        .then(d => {
            if (d.success) { showToast('Grades submitted to Registrar!', 'green'); loadGradeStudents(); }
            else { showToast('Error: '+(d.message||'Could not submit'), 'red'); }
        })
        .catch(()=>showToast('Network error submitting grades','red'));
}

// ---- Toast helper ----
function showToast(msg, color='green') {
    const toast = document.createElement('div');
    toast.className = `fixed bottom-5 right-5 z-[9999] px-6 py-3 rounded-lg shadow-lg text-white font-semibold text-sm transition-all ${color==='green'?'bg-green-600':'bg-red-600'}`;
    toast.textContent = msg;
    document.body.appendChild(toast);
    setTimeout(()=>{ toast.style.opacity='0'; setTimeout(()=>toast.remove(),400); },3000);
}

// ---- Admin/Registrar: Load Grade Submissions ----
async function loadGradeSubmissions() {
    const container = document.getElementById('gradeSubmissionsTableContainer');
    if (!container) return;
    container.innerHTML = '<div class="text-center py-10 text-gray-400">Loading submissions…</div>';
    const semester = document.getElementById('gsFilterSemester')?.value || '';
    const grade    = document.getElementById('gsFilterGrade')?.value    || '';
    let url = 'php/get_grade_submissions.php?';
    if (semester) url += 'semester='+encodeURIComponent(semester)+'&';
    if (grade)    url += 'grade_level='+encodeURIComponent(grade)+'&';
    try {
        const r = await fetch(url);
        const data = await r.json();
        if (!data.success) { container.innerHTML = `<div class="text-red-500 text-center py-10">${data.message||'Error'}</div>`; return; }
        if (data.debug) console.log('Grade Submissions Debug:', data.debug);
        const rows = data.submissions || [];
        if (!rows.length) { 
            container.innerHTML = `<div class="text-gray-400 text-center py-10">No submitted grades found${grade?' for Grade '+grade:''}${semester?' for '+{1:'1st',2:'2nd',3:'3rd'}[semester]+' Semester':''}.</div>`; 
            return; 
        }
        let html = '<div class="overflow-x-auto"><table class="min-w-full border-collapse"><thead class="bg-[#0a2d63] text-white"><tr>';
        ['Teacher','Subject','Grade Level','Section','Semester','Students','Actions'].forEach(h => {
            html += `<th class="p-3 text-left text-sm font-semibold whitespace-nowrap">${h}</th>`;
        });
        html += '</tr></thead><tbody>';
        rows.forEach((row,i) => {
            const semName = {1:'1st',2:'2nd',3:'3rd'}[row.semester]||row.semester;
            html += `<tr class="${i%2?'bg-gray-50':'bg-white'} border-b border-gray-200 hover:bg-blue-50 transition">
                <td class="p-3 text-gray-800 font-medium whitespace-nowrap">${escapeHtml(row.teacher_name||'—')}</td>
                <td class="p-3 text-gray-700">${escapeHtml(row.subject_name||'—')}</td>
                <td class="p-3 text-gray-700 whitespace-nowrap">${escapeHtml(row.grade_level||'—')}</td>
                <td class="p-3 text-gray-700">${escapeHtml(row.section||'—')}</td>
                <td class="p-3"><span class="px-2 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-800 whitespace-nowrap">${semName} Semester</span></td>
                <td class="p-3 text-center text-gray-700">${row.student_count||0}</td>
                <td class="p-3">
                    <div class="flex gap-2 items-center">
                        <button onclick="viewGradeSubmissionDetails(${row.subject_id},${row.semester})"
                            class="px-3 py-1.5 rounded bg-[#0a2d63] text-white text-xs font-medium hover:bg-[#08306b] transition whitespace-nowrap">
                            View Details
                        </button>
                        <button onclick="unlockGradeSubmission(${row.subject_id},${row.semester})"
                            class="px-3 py-1.5 rounded bg-[#0a2d63] text-white text-xs font-medium hover:bg-[#08306b] transition whitespace-nowrap"
                            title="Allow teacher to re-edit this submission">
                            Edit
                        </button>
                    </div>
                </td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        container.innerHTML = html;
    } catch(e) { container.innerHTML = '<div class="text-red-500 text-center py-10">Network error</div>'; }
}

async function unlockGradeSubmission(subjectId, semester) {
    const semName = {1:'1st',2:'2nd',3:'3rd'}[semester]||semester;
    if (!confirm(`Allow the teacher to re-edit the ${semName} Semester grades for this subject? The submission will be unlocked until they save again.`)) return;
    try {
        const fd = new FormData();
        fd.append('action', 'unlock_submission');
        fd.append('subject_id', subjectId);
        fd.append('semester', semester);
        const r = await fetch('php/get_grade_submissions.php', { method: 'POST', body: fd });
        const data = await r.json();
        if (data.success) {
            alert('✓ Submission unlocked. The teacher can now re-edit and re-submit.');
            loadGradeSubmissions();
        } else {
            alert('Error: ' + (data.message || 'Could not unlock submission'));
        }
    } catch(e) { alert('Network error'); }
}

// ---- Admin/Registrar: View submission details ----
async function viewGradeSubmissionDetails(subjectId, semester) {
    const modal = document.getElementById('gradeSubmissionDetailsModal');
    const content = document.getElementById('gradeSubmissionDetailsContent');
    if (!modal || !content) return;
    modal.style.display = 'flex';
    content.innerHTML = '<div class="text-center py-10 text-gray-400">Loading grade sheet…</div>';
    try {
        const r = await fetch(`php/get_grade_submission_details.php?subject_id=${subjectId}&semester=${semester}`);
        const data = await r.json();
        if (!data.success) { content.innerHTML = `<div class="text-red-500 text-center py-6">${data.message||'Error'}</div>`; return; }
        const subj = data.subject || {};
        const grades = data.grades || [];
        const semName = {1:'1st',2:'2nd',3:'3rd'}[semester]||semester;
        document.getElementById('gsdModalTitle').textContent = `${subj.subject_name||'Subject'} — ${semName} Semester`;
        document.getElementById('gsdModalSubtitle').textContent = `${subj.grade_level||''} ${subj.section||''} | ${grades.length} student(s)`;
        let html = '<div class="overflow-x-auto"><table class="min-w-full border-collapse text-sm"><thead class="bg-gray-100"><tr>';
        ['#','Student Name','LRN','Quiz','Essay','Recitation','Periodic Test','Final Grade'].forEach(h=>{
            html+=`<th class="p-3 text-left font-semibold text-gray-700 border-b border-gray-200">${h}</th>`;
        });
        html+='</tr></thead><tbody>';
        grades.forEach((g,i)=>{
            const grade = g.calculated_grade !== null ? parseFloat(g.calculated_grade).toFixed(2) : '—';
            const gradeColor = g.calculated_grade===null?'text-gray-400':(parseFloat(g.calculated_grade)>=75?'text-green-700 font-bold':'text-red-600 font-bold');
            const fmt = v => v!==null&&v!==undefined&&v!=='' ? parseFloat(v).toFixed(1) : '—';
            html+=`<tr class="${i%2?'bg-gray-50':'bg-white'} border-b border-gray-100">
                <td class="p-3 text-gray-500">${i+1}</td>
                <td class="p-3 font-medium text-gray-800">${escapeHtml(g.student_name||'')}</td>
                <td class="p-3 text-gray-600 font-mono text-xs">${escapeHtml(g.lrn||'—')}</td>
                <td class="p-3 text-center">${fmt(g.quiz_score)}</td>
                <td class="p-3 text-center">${fmt(g.essay_score)}</td>
                <td class="p-3 text-center">${fmt(g.recitation_score)}</td>
                <td class="p-3 text-center">${fmt(g.periodic_test_score)}</td>
                <td class="p-3 text-center ${gradeColor}">${grade}</td>
            </tr>`;
        });
        html+='</tbody></table></div>';
        content.innerHTML = html;
    } catch(e) { content.innerHTML = '<div class="text-red-500 text-center py-6">Network error loading details</div>'; }
}

// ========== BOOK MANAGER FUNCTIONS ==========
async function loadBooks() {
    const container = document.getElementById('bookManagerTableContainer');
    if (!container) return;
    container.innerHTML = '<div class="text-center py-10 text-gray-400">Loading books…</div>';
    try {
        const r = await fetch('php/books.php?action=list');
        const data = await r.json();
        if (!data.success) { container.innerHTML = `<div class="text-red-500 text-center py-10">${data.message||'Error'}</div>`; return; }
        const books = data.books || [];
        if (!books.length) { container.innerHTML = '<div class="text-gray-400 text-center py-10">No books added yet. Click "Add Book" to get started.</div>'; return; }
        let html = '<div class="overflow-x-auto"><table class="min-w-full border-collapse"><thead class="bg-[#0a2d63] text-white"><tr>';
        ['Title','Price','Assigned Grades','Actions'].forEach(h => {
            html += `<th class="p-3 text-left text-sm font-semibold whitespace-nowrap">${h}</th>`;
        });
        html += '</tr></thead><tbody>';
        books.forEach((book, i) => {
            const grades = (book.grade_levels || []).join(', ') || '<span class="text-gray-400 italic">None</span>';
            html += `<tr class="${i%2?'bg-gray-50':'bg-white'} border-b border-gray-200 hover:bg-blue-50 transition">
                <td class="p-3 text-gray-800 font-medium">${escapeHtml(book.title)}</td>
                <td class="p-3 text-gray-700">₱${parseFloat(book.price).toLocaleString('en-PH', {minimumFractionDigits:2})}</td>
                <td class="p-3 text-gray-700 text-sm">${grades}</td>
                <td class="p-3">
                    <div class="flex gap-2 items-center">
                        <button onclick="editBook(${book.id})" class="px-3 py-1.5 rounded bg-[#0a2d63] text-white text-xs font-medium hover:bg-[#08306b] transition whitespace-nowrap">Edit</button>
                        <button onclick="deleteBook(${book.id}, '${escapeHtml(book.title).replace(/'/g, "\\'")}')" class="px-3 py-1.5 rounded bg-red-500 text-white text-xs font-medium hover:bg-red-600 transition whitespace-nowrap">Delete</button>
                    </div>
                </td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        container.innerHTML = html;
    } catch(e) { container.innerHTML = '<div class="text-red-500 text-center py-10">Network error</div>'; }
}

function openAddBookModal(editData = null) {
    const modal = document.getElementById('addBookModal');
    const title = document.getElementById('bookModalTitle');
    const form = document.getElementById('bookForm');
    document.getElementById('bookFormError').textContent = '';
    document.getElementById('bookEditId').value = '0';
    document.getElementById('bookTitleInput').value = '';
    document.getElementById('bookPriceInput').value = '';
    document.querySelectorAll('.book-grade-cb').forEach(cb => cb.checked = false);

    if (editData) {
        title.textContent = 'Edit Book';
        document.getElementById('bookEditId').value = editData.id;
        document.getElementById('bookTitleInput').value = editData.title;
        document.getElementById('bookPriceInput').value = editData.price;
        (editData.grade_levels || []).forEach(gl => {
            const cb = document.querySelector(`.book-grade-cb[value="${gl}"]`);
            if (cb) cb.checked = true;
        });
    } else {
        title.textContent = 'Add Book';
    }
    modal.style.display = 'flex';
}

function closeAddBookModal() {
    const modal = document.getElementById('addBookModal');
    if (modal) modal.style.display = 'none';
}

async function editBook(id) {
    try {
        const r = await fetch('php/books.php?action=list');
        const data = await r.json();
        if (!data.success) return;
        const book = (data.books || []).find(b => b.id == id);
        if (book) openAddBookModal(book);
    } catch(e) { alert('Error loading book details'); }
}

async function deleteBook(id, title) {
    if (!confirm(`Delete the book "${title}"? This cannot be undone.`)) return;
    try {
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', id);
        const r = await fetch('php/books.php', { method: 'POST', body: fd });
        const data = await r.json();
        if (data.success) {
            loadBooks();
        } else {
            alert('Error: ' + (data.message || 'Could not delete book'));
        }
    } catch(e) { alert('Network error'); }
}

async function submitBookForm() {
    const errorEl = document.getElementById('bookFormError');
    errorEl.textContent = '';
    const id = document.getElementById('bookEditId').value;
    const title = document.getElementById('bookTitleInput').value.trim();
    const price = document.getElementById('bookPriceInput').value;
    const gradeLevels = [];
    document.querySelectorAll('.book-grade-cb:checked').forEach(cb => gradeLevels.push(cb.value));

    if (!title) { errorEl.textContent = 'Book title is required.'; return; }
    if (!price || parseFloat(price) < 0) { errorEl.textContent = 'Valid price is required.'; return; }

    try {
        const fd = new FormData();
        fd.append('action', 'save');
        fd.append('id', id);
        fd.append('title', title);
        fd.append('price', price);
        fd.append('grade_levels', JSON.stringify(gradeLevels));
        const r = await fetch('php/books.php', { method: 'POST', body: fd });
        const data = await r.json();
        if (data.success) {
            closeAddBookModal();
            loadBooks();
        } else {
            errorEl.textContent = data.message || 'Error saving book.';
        }
    } catch(e) { errorEl.textContent = 'Network error.'; }
}

// ========== TEACHER CLASS LIST TAB JAVASCRIPT ==========
let teacherClassListSort = 'name';
let teacherClassListPageSize = 10;
let openTeacherClassDetails = new Set();

function loadTeacherClassList() {
    initTeacherClassListFilters();
    applyTeacherClassListFilters();
}

function initTeacherClassListFilters() {
    const gradeCheckboxes = document.getElementById('teacherClassListGradeCheckboxes');
    if (!gradeCheckboxes) return;

    if (gradeCheckboxes.children.length > 0) return; // already initialized

    if (!Array.isArray(window.teacherSections)) return;

    const grades = [...new Set(window.teacherSections.map(sec => sec.grade_level).filter(Boolean))].sort();
    
    gradeCheckboxes.innerHTML = '';
    grades.forEach(grade => {
        const label = document.createElement('label');
        label.className = 'flex items-center gap-2 text-sm text-gray-700 cursor-pointer';
        label.innerHTML = `<input type="checkbox" class="teacher-class-filter-grade-checkbox w-4 h-4" value="${grade}" onchange="updateTeacherClassListFilterSections(); applyTeacherClassListFilters()"> ${grade}`;
        gradeCheckboxes.appendChild(label);
    });

    updateTeacherClassListFilterSections();
}

function updateTeacherClassListFilterSections() {
    const selectedGrades = Array.from(document.querySelectorAll('.teacher-class-filter-grade-checkbox:checked')).map(el => el.value);
    const filterSectionContainer = document.getElementById('teacherClassListSectionContainer');
    const sectionCheckboxes = document.getElementById('teacherClassListSectionCheckboxes');
    if (!filterSectionContainer || !sectionCheckboxes) return;

    if (selectedGrades.length > 0 && Array.isArray(window.teacherSections)) {
        const matchingSections = window.teacherSections.filter(sec => selectedGrades.includes(sec.grade_level));
        const sectionSet = new Set(matchingSections.map(sec => sec.section_name));
        
        if (sectionSet.size > 0) {
            filterSectionContainer.classList.remove('hidden');
            sectionCheckboxes.innerHTML = '';
            Array.from(sectionSet).sort().forEach(section => {
                const label = document.createElement('label');
                label.className = 'flex items-center gap-2 text-sm text-gray-700 cursor-pointer';
                label.innerHTML = `<input type="checkbox" class="teacher-class-filter-section-checkbox w-4 h-4" value="${section}" onchange="applyTeacherClassListFilters()"> ${section}`;
                sectionCheckboxes.appendChild(label);
            });
        } else {
            filterSectionContainer.classList.add('hidden');
            sectionCheckboxes.innerHTML = '';
        }
    } else {
        filterSectionContainer.classList.add('hidden');
        sectionCheckboxes.innerHTML = '';
    }
}

function setTeacherClassListSort(sortBy) {
    teacherClassListSort = sortBy;
    document.querySelectorAll('.teacher-class-sort-option').forEach(opt => opt.classList.remove('active'));
    const sortEl = document.getElementById('teacher-class-sort-' + sortBy);
    if (sortEl) sortEl.classList.add('active');
    applyTeacherClassListFilters();
}

function setTeacherClassListPageSize(value) {
    const size = parseInt(value, 10);
    if (!isNaN(size) && size > 0) {
        teacherClassListPageSize = size;
        applyTeacherClassListFilters();
    }
}

function toggleTeacherClassListDetails(id) {
    const detailsDiv = document.getElementById('teacher-class-details-' + id);
    if (!detailsDiv) return;
    if (detailsDiv.classList.contains('hidden')) {
        detailsDiv.classList.remove('hidden');
        openTeacherClassDetails.add(id);
    } else {
        detailsDiv.classList.add('hidden');
        openTeacherClassDetails.delete(id);
    }
}

function applyTeacherClassListFilters() {
    const listEl = document.getElementById('teacherClassListContainer');
    if (!listEl) return;

    const searchInput = document.getElementById('teacherClassListSearch');
    const searchTerm = (searchInput && searchInput.value ? searchInput.value : '').toLowerCase().trim();

    const filterGrades = Array.from(document.querySelectorAll('.teacher-class-filter-grade-checkbox:checked')).map(el => el.value);
    const filterSections = Array.from(document.querySelectorAll('.teacher-class-filter-section-checkbox:checked')).map(el => el.value);

    let filtered = (teacherHomeStudents || []).filter(student => {
        const nameParts = [student.first_name, student.middle_name, student.last_name, student.suffix].map(p => (p && p.toUpperCase() === 'N/A') ? '' : p).filter(Boolean);
        const fullName = (student.full_name ? student.full_name.replace(/\bN\/A\b/gi, '').replace(/\s+/g, ' ').trim() : nameParts.join(' ')) || 'N/A';
        
        const matchesSearch = searchTerm === '' ||
            fullName.toLowerCase().includes(searchTerm) ||
            (student.username && student.username.toLowerCase().includes(searchTerm)) ||
            (student.email && student.email.toLowerCase().includes(searchTerm)) ||
            (student.lrn && student.lrn.toLowerCase().includes(searchTerm)) ||
            (student.grade_level && student.grade_level.toLowerCase().includes(searchTerm)) ||
            (student.section && student.section.toLowerCase().includes(searchTerm));
        
        if (!matchesSearch) return false;

        if (filterGrades.length > 0 && !filterGrades.includes(student.grade_level)) return false;
        if (filterSections.length > 0 && !filterSections.includes(student.section)) return false;

        return true;
    });

    filtered.sort((a, b) => {
        if (teacherClassListSort === 'name') {
            const nameAParts = [a.first_name, a.middle_name, a.last_name, a.suffix].map(p => (p && p.toUpperCase() === 'N/A') ? '' : p).filter(Boolean);
            const nameA = (a.full_name ? a.full_name.replace(/\bN\/A\b/gi, '').replace(/\s+/g, ' ').trim() : nameAParts.join(' ')) || 'N/A';
            const nameBParts = [b.first_name, b.middle_name, b.last_name, b.suffix].map(p => (p && p.toUpperCase() === 'N/A') ? '' : p).filter(Boolean);
            const nameB = (b.full_name ? b.full_name.replace(/\bN\/A\b/gi, '').replace(/\s+/g, ' ').trim() : nameBParts.join(' ')) || 'N/A';
            return nameA.localeCompare(nameB);
        } else if (teacherClassListSort === 'grade') {
            const gA = a.grade_level || '';
            const gB = b.grade_level || '';
            if (gA !== gB) return gA.localeCompare(gB);
            const sA = a.section || '';
            const sB = b.section || '';
            if (sA !== sB) return sA.localeCompare(sB);
            const nameAParts = [a.first_name, a.middle_name, a.last_name, a.suffix].map(p => (p && p.toUpperCase() === 'N/A') ? '' : p).filter(Boolean);
            const nameA = (a.full_name ? a.full_name.replace(/\bN\/A\b/gi, '').replace(/\s+/g, ' ').trim() : nameAParts.join(' ')) || 'N/A';
            const nameBParts = [b.first_name, b.middle_name, b.last_name, b.suffix].map(p => (p && p.toUpperCase() === 'N/A') ? '' : p).filter(Boolean);
            const nameB = (b.full_name ? b.full_name.replace(/\bN\/A\b/gi, '').replace(/\s+/g, ' ').trim() : nameBParts.join(' ')) || 'N/A';
            return nameA.localeCompare(nameB);
        } else if (teacherClassListSort === 'lrn') {
            const lA = a.lrn || '';
            const lB = b.lrn || '';
            return lA.localeCompare(lB);
        }
        return 0;
    });

    renderTeacherClassList(filtered);
}

function renderTeacherClassList(students) {
    const resultsDiv = document.getElementById('teacherClassListContainer');
    if (!resultsDiv) return;

    if (students.length === 0) {
        resultsDiv.innerHTML = '<div class="text-center text-gray-500 py-10">No students match your filters.</div>';
        return;
    }

    const displayed = students.slice(0, teacherClassListPageSize);
    let html = '';

    displayed.forEach(student => {
        const nameParts = [student.first_name, student.middle_name, student.last_name, student.suffix].map(p => (p && p.toUpperCase() === 'N/A') ? '' : p).filter(Boolean);
        const fullName = (student.full_name ? student.full_name.replace(/\bN\/A\b/gi, '').replace(/\s+/g, ' ').trim() : nameParts.join(' ')) || 'N/A';
        const gs = student.grade_level ? (student.grade_level + (student.section ? ' - ' + student.section : '')) : 'N/A';
        
        const phoneDisp = student.phone
            ? (String(student.phone).startsWith('+63') ? student.phone : '+63' + student.phone)
            : '—';
        
        const isDetailOpen = openTeacherClassDetails.has(student.id);

        html += `
            <div class="border-b border-gray-200 last:border-b-0">
                <div class="p-3 md:p-4 hover:bg-gray-50">
                    <div class="flex items-start gap-2 min-w-0">
                        <button type="button" class="text-[#0a2d63] font-bold px-1 shrink-0" title="Show details" onclick="event.stopPropagation(); toggleTeacherClassListDetails(${student.id})">▾</button>
                        <div class="cursor-pointer flex-1 min-w-0" onclick="toggleTeacherClassListDetails(${student.id})">
                            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="font-semibold text-[#0a2d63] truncate">${fullName}</div>
                                    <div class="text-sm text-gray-600 mt-0.5 truncate">${gs}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div id="teacher-class-details-${student.id}" class="${isDetailOpen ? '' : 'hidden'} border-t border-gray-100 bg-gray-50 px-4 py-3 pl-10 text-sm text-gray-700">
                    <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 flex-1">
                            <div><span class="font-semibold">LRN:</span> ${student.lrn || '—'}</div>
                            <div><span class="font-semibold">Username:</span> ${student.username || '—'}</div>
                            <div><span class="font-semibold">Email:</span> ${student.email || '—'}</div>
                            <div><span class="font-semibold">Gender:</span> ${student.gender || '—'}</div>
                            <div><span class="font-semibold">Birthdate:</span> ${student.birthdate || '—'}</div>
                            <div><span class="font-semibold">Phone:</span> ${phoneDisp}</div>
                            <div><span class="font-semibold">Status:</span> ${(student.status == 1) ? 'Active' : 'Inactive'}</div>
                            <div><span class="font-semibold">Joined:</span> ${student.created_at || '—'}</div>
                        </div>
                        <div class="flex justify-end mt-2 md:mt-0">
                            <button onclick="triggerExcelExport(${student.id})" class="bg-green-600 text-white px-3 py-1.5 rounded-lg font-medium text-xs flex items-center gap-1 hover:bg-green-700 transition shadow-sm">
                                <i class="fas fa-file-excel"></i> Export Grades
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    });

    const summary = `<div class="text-sm text-gray-600 p-3">Showing ${displayed.length} of ${students.length} student${students.length === 1 ? '' : 's'}.</div>`;
    resultsDiv.innerHTML = html + summary;
}

    window.open('php/generate_class_list_pdf.php' + query, '_blank');
}

// ---------- Notifications Module ----------
let notifications = [];
let unreadCount = 0;

async function pollNotifications() {
    try {
        const resp = await fetch('php/get_notifications.php');
        const data = await resp.json();
        if (data.success) {
            notifications = data.notifications;
            unreadCount = data.unread_count;
            updateNotificationBadge();
            if (document.getElementById('notifDropdown').classList.contains('active')) {
                renderNotifications();
            }
        }
    } catch (e) {
        console.error('Notification polling error', e);
    }
}

function updateNotificationBadge() {
    const badge = document.getElementById('notifCount');
    if (unreadCount > 0) {
        badge.innerText = unreadCount > 99 ? '99+' : unreadCount;
        badge.classList.remove('hidden');
    } else {
        badge.classList.add('hidden');
    }
}

function toggleNotifDropdown(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    const dropdown = document.getElementById('notifDropdown');
    const isActive = dropdown.classList.contains('active');
    
    if (!isActive) {
        renderNotifications();
        dropdown.classList.add('active');
        // Close on click outside
        const closeOnOutside = (event) => {
            if (!event.target.closest('#notifBell')) {
                dropdown.classList.remove('active');
                document.removeEventListener('click', closeOnOutside);
            }
        };
        setTimeout(() => document.addEventListener('click', closeOnOutside), 10);
    } else {
        dropdown.classList.remove('active');
    }
}

    list.innerHTML = notifications.map(n => {
        // Ensure link is either a valid string or null (not the string 'null')
        const linkVal = (n.link === 'null' || !n.link) ? null : n.link;
        const linkAttr = linkVal ? `'${linkVal}'` : 'null';
        
        return `
            <div class="notification-item ${n.status === 'unread' ? 'unread' : ''}" onclick="handleNotifClick(${n.id}, ${linkAttr})">
                <div class="flex items-start gap-3">
                    <div class="mt-1">
                        ${getNotifIcon(n.type)}
                    </div>
                    <div class="flex-1">
                        <p class="text-sm font-medium ${n.status === 'unread' ? 'text-gray-900' : 'text-gray-600'}">${n.message}</p>
                        <p class="text-xs text-gray-400 mt-1">${formatTimeAgo(n.created_at)}</p>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

function getNotifIcon(type) {
    switch(type) {
        case 'enrollment': return '<i class="fas fa-user-plus text-blue-500"></i>';
        case 'document': return '<i class="fas fa-file-alt text-orange-500"></i>';
        case 'grade': return '<i class="fas fa-graduation-cap text-green-500"></i>';
        case 'photo': return '<i class="fas fa-camera text-purple-500"></i>';
        default: return '<i class="fas fa-bell text-gray-500"></i>';
    }
}

function formatTimeAgo(dateStr) {
    const date = new Date(dateStr);
    const now = new Date();
    const diff = Math.floor((now - date) / 1000);
    
    if (diff < 60) return 'Just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
    return date.toLocaleDateString();
}

async function handleNotifClick(id, link) {
    try {
        await fetch('php/mark_notification_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=' + id
        });
        
        pollNotifications(); // Refresh
        
        if (link) {
            navigateTo(link);
        }
    } catch (e) {
        console.error('Mark read error', e);
    }
}

async function markAllRead(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    
    if (!confirm('Mark all notifications as read?')) return;
    
    try {
        await fetch('php/mark_notification_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=all'
        });
        pollNotifications();
    } catch (e) {
        console.error('Mark all read error', e);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    pollNotifications();
    setInterval(pollNotifications, 30000); // Check every 30s
    
    document.getElementById('notifBell').addEventListener('click', toggleNotifDropdown);
});

// ---------- Student Export Module ----------
function openExportStudentModal() {
    document.getElementById('exportStudentModal').classList.remove('hidden');
    document.getElementById('exportStudentModal').classList.add('flex');
}

function closeExportStudentModal() {
    document.getElementById('exportStudentModal').classList.add('hidden');
    document.getElementById('exportStudentModal').classList.remove('flex');
    document.getElementById('exportStudentSearch').value = '';
    document.getElementById('exportStudentResults').innerHTML = '<div class="p-4 text-center text-gray-500">Type above to search students...</div>';
}

let exportSearchTimeout;
function searchExportStudents(query) {
    clearTimeout(exportSearchTimeout);
    if (query.trim().length < 2) return;

    exportSearchTimeout = setTimeout(() => {
        const formData = new FormData();
        formData.append('action', 'search_students');
        formData.append('search', query);
        formData.append('per_page', '10');

        fetch('dashboard.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                renderExportResults(data.students);
            }
        })
        .catch(err => console.error(err));
    }, 300);
}

function renderExportResults(students) {
    const container = document.getElementById('exportStudentResults');
    if (students.length === 0) {
        container.innerHTML = '<div class="p-4 text-center text-gray-500">No students found.</div>';
        return;
    }

    container.innerHTML = students.map(s => `
        <div class="p-4 border-b border-gray-100 flex justify-between items-center hover:bg-gray-50 transition">
            <div>
                <div class="font-semibold text-gray-800">${s.full_name}</div>
                <div class="text-xs text-gray-500">${s.grade_level} ${s.section ? ' - ' + s.section : ''} | LRN: ${s.lrn || 'N/A'}</div>
            </div>
            <button onclick="triggerExcelExport(${s.id})" class="text-green-600 hover:text-green-800 font-medium text-sm flex items-center gap-1">
                <i class="fas fa-download"></i> Export
            </button>
        </div>
    `).join('');
}

function triggerExcelExport(studentId) {
    window.open(`php/export_grades_spreadsheet.php?student_id=${studentId}`, '_blank');
}

</script>
