// Live Exam Controller

let currentExam = {
    studentExamId: 0,
    examId: 0,
    durationMinutes: 0,
    timeLeftSeconds: 0,
    questions: [],
    currentIndex: 0,
    answers: {}, // question_id -> answer_text
    tabSwitches: 0,
    copyPastes: 0,
    flagged: new Set(),
    timerInterval: null,
    autosaveInterval: null,
    heartbeatInterval: null
};

// Initialize Exam session
function initExam(studentExamId, examId, durationMinutes, questionsJson, answersJson, tabSwitches, copyPastes) {
    currentExam.studentExamId = studentExamId;
    currentExam.examId = examId;
    currentExam.durationMinutes = durationMinutes;
    currentExam.questions = questionsJson;
    currentExam.tabSwitches = tabSwitches;
    currentExam.copyPastes = copyPastes;
    
    // Parse existing answers from database
    answersJson.forEach(ans => {
        currentExam.answers[ans.question_id] = ans.student_answer;
    });

    // Merge answers from localStorage if present and newer/unsaved
    try {
        const localDraft = localStorage.getItem('exam_draft_' + studentExamId);
        if (localDraft) {
            const parsedDraft = JSON.parse(localDraft);
            Object.keys(parsedDraft).forEach(qId => {
                if (parsedDraft[qId] && (!currentExam.answers[qId] || currentExam.answers[qId].toString().trim() === '')) {
                    currentExam.answers[qId] = parsedDraft[qId];
                }
            });
        }
    } catch (err) {
        console.error("Local draft restore failed:", err);
    }

    // Calculate time left (check if started_at is offset, but for simplicity, we start fresh or count down from duration)
    // We will initialize timer from backend value
    currentExam.timeLeftSeconds = durationMinutes * 60;
    
    // Render question palette sidebar
    renderPalette();
    
    // Show first question
    showQuestion(0);
    
    // Start countdown timer
    startTimer();
    
    // Start periodic autosave (every 30 seconds)
    startAutosaveTimer();
    
    // Start periodic heartbeat (every 10 seconds)
    startHeartbeatTimer();

    // Attach proctoring event listeners
    initProctoring();
}

// Render Sidebar Navigation Palette
function renderPalette() {
    const paletteContainer = document.getElementById('palette-container');
    if (!paletteContainer) return;
    
    paletteContainer.innerHTML = '';
    currentExam.questions.forEach((q, index) => {
        const btn = document.createElement('button');
        btn.className = 'palette-btn';
        btn.id = `palette-btn-${q.id}`;
        btn.innerText = index + 1;
        
        // Apply status class
        updatePaletteButtonStatus(q.id);
        
        btn.addEventListener('click', () => {
            saveCurrentAnswerState();
            showQuestion(index);
        });
        
        paletteContainer.appendChild(btn);
    });
}

// Update specific palette button appearance based on state
function updatePaletteButtonStatus(questionId) {
    const btn = document.getElementById(`palette-btn-${questionId}`);
    if (!btn) return;
    
    // Reset classes
    btn.className = 'palette-btn';
    
    if (currentExam.currentIndex === currentExam.questions.findIndex(q => q.id === questionId)) {
        btn.classList.add('active');
    }
    
    const ans = currentExam.answers[questionId];
    const isAnswered = ans !== undefined && ans.toString().trim() !== '';
    
    if (currentExam.flagged.has(questionId)) {
        btn.classList.add('flagged');
    } else if (isAnswered) {
        btn.classList.add('answered');
    } else {
        btn.classList.add('unanswered');
    }
}

// Display selected Question
function showQuestion(index) {
    if (index < 0 || index >= currentExam.questions.length) return;
    
    // Update index
    const previousIndex = currentExam.currentIndex;
    currentExam.currentIndex = index;
    
    // Update old question palette button & new question palette button
    if (currentExam.questions[previousIndex]) {
        updatePaletteButtonStatus(currentExam.questions[previousIndex].id);
    }
    
    const q = currentExam.questions[index];
    updatePaletteButtonStatus(q.id);
    
    // Populate Question Details
    document.getElementById('question-number-title').innerText = `Question ${index + 1} of ${currentExam.questions.length}`;
    document.getElementById('question-points-badge').innerText = `${q.points} Point(s)`;
    document.getElementById('question-text-content').innerHTML = q.question_text;
    
    const answerArea = document.getElementById('question-answer-area');
    answerArea.innerHTML = '';
    
    const currentVal = currentExam.answers[q.id] || '';
    
    if (q.type === 'mcq') {
        // Render MCQ Options
        const optionsList = document.createElement('div');
        optionsList.className = 'options-list';
        
        const options = [
            { key: 'A', text: q.option_a },
            { key: 'B', text: q.option_b },
            { key: 'C', text: q.option_c },
            { key: 'D', text: q.option_d }
        ];
        
        options.forEach(opt => {
            const item = document.createElement('div');
            item.className = 'option-item';
            if (currentVal.toUpperCase() === opt.key) {
                item.classList.add('selected');
            }
            
            item.innerHTML = `
                <div class="option-badge">${opt.key}</div>
                <div class="option-label">${opt.text}</div>
            `;
            
            item.addEventListener('click', () => {
                // Remove selected from siblings
                const items = optionsList.querySelectorAll('.option-item');
                items.forEach(i => i.classList.remove('selected'));
                
                item.classList.add('selected');
                currentExam.answers[q.id] = opt.key;
                updatePaletteButtonStatus(q.id);
                // Save state immediately on MCQ selection
                saveDraftAnswers(false);
            });
            
            optionsList.appendChild(item);
        });
        
        answerArea.appendChild(optionsList);
    } else {
        // Render Descriptive Textarea
        const textarea = document.createElement('textarea');
        textarea.className = 'textarea-answer';
        textarea.placeholder = 'Type your descriptive answer here... (Minimum keywords and concepts should match the model answer)';
        textarea.value = currentVal;
        textarea.setAttribute('autocomplete', 'off');
        textarea.setAttribute('autocorrect', 'off');
        textarea.setAttribute('autocapitalize', 'off');
        textarea.setAttribute('spellcheck', 'false');
        
        // Prevent copy-paste inside textarea
        textarea.addEventListener('paste', e => e.preventDefault());
        textarea.addEventListener('copy', e => e.preventDefault());
        textarea.addEventListener('cut', e => e.preventDefault());
        
        textarea.addEventListener('input', (e) => {
            currentExam.answers[q.id] = e.target.value;
        });
        
        textarea.addEventListener('blur', () => {
            updatePaletteButtonStatus(q.id);
            saveDraftAnswers(false);
        });
        
        answerArea.appendChild(textarea);
    }
    
    // Toggle Mark for Review button state
    const reviewBtn = document.getElementById('review-btn');
    if (currentExam.flagged.has(q.id)) {
        reviewBtn.innerText = '🚩 Flagged';
        reviewBtn.classList.add('btn-primary');
    } else {
        reviewBtn.innerText = '🏳️ Mark for Review';
        reviewBtn.classList.remove('btn-primary');
    }
    
    // Toggle prev/next button visibility
    document.getElementById('prev-btn').disabled = (index === 0);
    document.getElementById('next-btn').style.display = (index === currentExam.questions.length - 1) ? 'none' : 'inline-flex';
    document.getElementById('submit-exam-btn').style.display = (index === currentExam.questions.length - 1) ? 'inline-flex' : 'none';
}

// Save text field value if user switches via numbers
function saveCurrentAnswerState() {
    const q = currentExam.questions[currentExam.currentIndex];
    if (q && q.type === 'descriptive') {
        const textarea = document.querySelector('.textarea-answer');
        if (textarea) {
            currentExam.answers[q.id] = textarea.value;
            updatePaletteButtonStatus(q.id);
        }
    }
}

// Navigation Actions
function navigateQuestion(direction) {
    saveCurrentAnswerState();
    showQuestion(currentExam.currentIndex + direction);
}

// Toggle Review/Flag state
function toggleFlagReview() {
    const q = currentExam.questions[currentExam.currentIndex];
    if (!q) return;
    
    if (currentExam.flagged.has(q.id)) {
        currentExam.flagged.delete(q.id);
    } else {
        currentExam.flagged.add(q.id);
    }
    
    // Refresh display
    showQuestion(currentExam.currentIndex);
}

// Proctoring Violations & Protection
function initProctoring() {
    // 1. Prevent Right-Click Context Menu
    document.addEventListener('contextmenu', e => {
        e.preventDefault();
        showToast('Right-click is disabled during the examination!', 'warning');
    });
    
    // 2. Prevent Cheating Keys (Copy, Paste, Cut, PrintScreen, etc.)
    document.addEventListener('keydown', e => {
        const forbiddenKeys = ['c', 'v', 'x', 'a', 'p'];
        if ((e.ctrlKey || e.metaKey) && forbiddenKeys.includes(e.key.toLowerCase())) {
            e.preventDefault();
            currentExam.copyPastes++;
            showToast('Keyboard shortcuts (Copy, Paste, Select All) are disabled!', 'danger');
            saveDraftAnswers(false);
        }
    });

    // 3. Tab switching detection
    window.addEventListener('blur', () => {
        currentExam.tabSwitches++;
        showToast(`Warning! Tab switch detected. Incident logged (${currentExam.tabSwitches}/5)`, 'danger');
        
        saveDraftAnswers(false);
        
        if (currentExam.tabSwitches >= 5) {
            lockAndSubmitExam();
        }
    });
}

// Lock screen and force submit on cheating threshold
function lockAndSubmitExam() {
    // Stop timers
    clearInterval(currentExam.timerInterval);
    clearInterval(currentExam.autosaveInterval);
    clearInterval(currentExam.heartbeatInterval);
    
    // Create lockout screen
    const lockout = document.createElement('div');
    lockout.className = 'cheat-overlay';
    lockout.innerHTML = `
        <div class="cheat-card">
            <h1>🚨 EXAM LOCKED</h1>
            <p>You have exceeded the maximum of 5 allowed tab switches. Your examination session has been terminated, and your answers are being submitted automatically.</p>
            <p style="color: var(--primary-saffron); font-weight: bold; margin: 1.5rem 0;">Reporting back to Proctor Logs...</p>
        </div>
    `;
    document.body.appendChild(lockout);
    
    // Submit answers automatically
    submitExamAnswers(true);
}

// Save current answers payload helper
function compileAnswersPayload() {
    const payload = [];
    Object.keys(currentExam.answers).forEach(qId => {
        payload.push({
            question_id: parseInt(qId),
            student_answer: currentExam.answers[qId]
        });
    });
    return payload;
}

// API Call to Save Draft
async function saveDraftAnswers(showFeedback = false) {
    saveCurrentAnswerState();
    
    // Save to localStorage first (instant local cache)
    try {
        localStorage.setItem('exam_draft_' + currentExam.studentExamId, JSON.stringify(currentExam.answers));
    } catch (err) {
        console.error("Local storage save failed:", err);
    }
    
    const statusEl = document.getElementById('autosave-status');
    if (statusEl) {
        statusEl.style.color = 'var(--warning)';
        statusEl.innerText = '⏳ Saving...';
    }
    
    const payload = {
        action: 'autosave',
        student_exam_id: currentExam.studentExamId,
        tab_switches: currentExam.tabSwitches,
        copy_pastes: currentExam.copyPastes,
        answers: compileAnswersPayload()
    };
    
    try {
        const res = await apiCall('api/submit_exam.php', payload);
        if (res && res.success) {
            if (statusEl) {
                statusEl.style.color = 'var(--success)';
                statusEl.innerText = '✅ Auto-saved';
            }
            if (showFeedback) {
                showToast('Draft auto-saved successfully.', 'success');
            }
        } else {
            if (statusEl) {
                statusEl.style.color = 'var(--danger)';
                statusEl.innerText = '⚠️ Saved (Local)';
            }
        }
    } catch (error) {
        if (statusEl) {
            statusEl.style.color = 'var(--danger)';
            statusEl.innerText = '⚠️ Saved (Local)';
        }
    }
}

// Start Autosave Timer (every 30 seconds)
function startAutosaveTimer() {
    currentExam.autosaveInterval = setInterval(() => {
        saveDraftAnswers(true);
    }, 30000);
}

// Start Heartbeat Timer (every 10 seconds) for Live Active Monitor
function startHeartbeatTimer() {
    currentExam.heartbeatInterval = setInterval(async () => {
        const payload = {
            action: 'heartbeat',
            student_exam_id: currentExam.studentExamId,
            tab_switches: currentExam.tabSwitches,
            copy_pastes: currentExam.copyPastes
        };
        const res = await apiCall('api/submit_exam.php', payload);
        
        // Handle warning broadcast from proctor
        if (res && res.warning_msg) {
            playWarningSound();
            showProctorWarningAlert(res.warning_msg);
        }

        if (res && res.terminated) {
            // Stop all timers
            clearInterval(currentExam.timerInterval);
            clearInterval(currentExam.autosaveInterval);
            clearInterval(currentExam.heartbeatInterval);
            
            // Render block overlay
            const lockout = document.createElement('div');
            lockout.className = 'cheat-overlay';
            lockout.innerHTML = `
                <div class="cheat-card">
                    <h1 style="color: var(--primary-maroon);">🔒 EXAM CLOSED BY PROCTOR</h1>
                    <p style="margin: 1rem 0;">This examination session has been finalized and locked by your supervisor/proctor.</p>
                    <p style="color: var(--primary-saffron); font-weight: bold; margin-top: 1.5rem;">Redirecting to your student dashboard...</p>
                </div>
            `;
            document.body.appendChild(lockout);
            setTimeout(() => {
                window.location.href = 'dashboard.php';
            }, 3000);
        }
    }, 10000);
}

// Countdown Timer Loop
function startTimer() {
    const timerWidget = document.getElementById('timer-widget');
    if (!timerWidget) return;
    
    currentExam.timerInterval = setInterval(() => {
        currentExam.timeLeftSeconds--;
        
        if (currentExam.timeLeftSeconds <= 0) {
            clearInterval(currentExam.timerInterval);
            clearInterval(currentExam.autosaveInterval);
            showToast('Time is up! Submitting exam...', 'warning');
            submitExamAnswers(true);
            return;
        }
        
        // Format Time
        const minutes = Math.floor(currentExam.timeLeftSeconds / 60);
        const seconds = currentExam.timeLeftSeconds % 60;
        
        timerWidget.innerHTML = `⏱️ ${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        
        // Visual indicator when less than 2 minutes left
        if (currentExam.timeLeftSeconds < 120) {
            timerWidget.classList.add('timer-danger');
        }
    }, 1000);
}

// Submit Button Handler
function confirmSubmitExam() {
    saveCurrentAnswerState();
    const unansweredCount = currentExam.questions.length - Object.keys(currentExam.answers).filter(k => currentExam.answers[k].trim() !== '').length;
    
    let confirmMsg = 'Are you sure you want to submit your exam?';
    if (unansweredCount > 0) {
        confirmMsg = `You have ${unansweredCount} unanswered question(s). Are you sure you want to submit?`;
    }
    
    if (confirm(confirmMsg)) {
        // Stop timers
        clearInterval(currentExam.timerInterval);
        clearInterval(currentExam.autosaveInterval);
        clearInterval(currentExam.heartbeatInterval);
        
        // Submit
        submitExamAnswers(false);
    }
}

// API Call to Finalize Exam Submission
async function submitExamAnswers(isForced = false) {
    // Show submitting spinner / overlay
    const submitBtn = document.getElementById('submit-exam-btn');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerText = 'Submitting...';
    }
    
    const payload = {
        action: 'submit',
        student_exam_id: currentExam.studentExamId,
        tab_switches: currentExam.tabSwitches,
        copy_pastes: currentExam.copyPastes,
        answers: compileAnswersPayload()
    };
    
    const res = await apiCall('api/submit_exam.php', payload);
    if (res.success) {
        // Clear local storage cache
        try {
            localStorage.removeItem('exam_draft_' + currentExam.studentExamId);
        } catch (err) {}
        alert('Exam submitted successfully! The auto-grader is evaluating your answers.');
        window.location.href = 'dashboard.php';
    } else {
        showToast('Submission error: ' + res.message, 'danger');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerText = 'Submit Exam';
        }
    }
}

// Audio alert warning beep using Web Audio API
function playWarningSound() {
    try {
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        
        const playBeep = (timeOffset) => {
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(987.77, audioCtx.currentTime + timeOffset);
            gain.gain.setValueAtTime(0.08, audioCtx.currentTime + timeOffset);
            gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + timeOffset + 0.3);
            
            osc.start(audioCtx.currentTime + timeOffset);
            osc.stop(audioCtx.currentTime + timeOffset + 0.35);
        };
        
        playBeep(0);
        playBeep(0.2);
    } catch (err) {
        console.warn("Audio Context blocked:", err);
    }
}

// Render Supervisor Warning Modal
function showProctorWarningAlert(message) {
    const modal = document.getElementById('proctor-warning-modal');
    const text = document.getElementById('proctor-warning-text');
    if (modal && text) {
        text.innerText = message;
        modal.style.display = 'flex';
    }
}

// Dismiss Warning Modal
function dismissProctorWarning() {
    const modal = document.getElementById('proctor-warning-modal');
    if (modal) {
        modal.style.display = 'none';
    }
}
