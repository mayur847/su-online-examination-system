// Global Helpers

// Toast Notifications Helper
function showToast(message, type = 'info') {
    // Remove existing toasts first
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());

    const toast = document.createElement('div');
    toast.className = `toast-notification toast-${type}`;
    
    let icon = 'ℹ️';
    if (type === 'success') icon = '✅';
    if (type === 'warning') icon = '⚠️';
    if (type === 'danger') icon = '🚨';
    
    toast.innerHTML = `<span>${icon}</span> <span>${message}</span>`;
    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(10px)';
        toast.style.transition = 'all 0.5s ease-out';
        setTimeout(() => toast.remove(), 500);
    }, 3500);
}

// HTTP API POST request helper
async function apiCall(url, payload = {}) {
    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });
        
        if (!response.ok) {
            throw new Error(`HTTP Error! Status: ${response.status}`);
        }
        
        return await response.json();
    } catch (error) {
        console.error('API Call Error:', error);
        return { success: false, message: error.message };
    }
}

// Global Theme Management Helpers
function initTheme() {
    const savedTheme = localStorage.getItem('theme') || 'light';
    if (savedTheme === 'dark') {
        document.body.classList.add('dark-mode');
        updateThemeToggleIcon('dark');
    } else {
        document.body.classList.remove('dark-mode');
        updateThemeToggleIcon('light');
    }
}

function toggleTheme() {
    const isDark = document.body.classList.toggle('dark-mode');
    const currentTheme = isDark ? 'dark' : 'light';
    localStorage.setItem('theme', currentTheme);
    updateThemeToggleIcon(currentTheme);
}

function updateThemeToggleIcon(theme) {
    const themeBtn = document.getElementById('theme-toggle');
    if (themeBtn) {
        themeBtn.innerHTML = theme === 'dark' ? '☀️' : '🌙';
    }
}

// Run theme setup as early as possible on DOMContentLoaded
document.addEventListener('DOMContentLoaded', () => {
    initTheme();
});
