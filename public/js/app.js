// Global theme prevention & initial state
(function() {
    const savedTheme = localStorage.getItem('color-scheme') || 'dark';
    document.documentElement.setAttribute('data-theme', savedTheme);
})();

// Global loading progress bar controller
let globalProgressTimer = null;
function showGlobalProgress() {
    const bar = document.getElementById('globalProgressBar');
    if (!bar) return;
    bar.classList.add('active');
    bar.style.width = '30%';
    if (globalProgressTimer) clearInterval(globalProgressTimer);
    globalProgressTimer = setInterval(() => {
        const current = parseFloat(bar.style.width) || 30;
        if (current < 85) {
            bar.style.width = (current + Math.random() * 15) + '%';
        }
    }, 200);
}

function hideGlobalProgress() {
    const bar = document.getElementById('globalProgressBar');
    if (!bar) return;
    if (globalProgressTimer) clearInterval(globalProgressTimer);
    bar.style.width = '100%';
    setTimeout(() => {
        bar.classList.remove('active');
        setTimeout(() => { bar.style.width = '0%'; }, 200);
    }, 250);
}

function toggleTheme() {
    const currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';
    const nextTheme = currentTheme === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', nextTheme);
    localStorage.setItem('color-scheme', nextTheme);
    updateThemeToggleButtons(nextTheme);
}

function updateThemeToggleButtons(theme) {
    const btns = document.querySelectorAll('.theme-toggle-btn');
    btns.forEach(btn => {
        btn.innerHTML = theme === 'dark' ? '<span class="material-symbols-outlined">light_mode</span> Light Mode' : '<span class="material-symbols-outlined">dark_mode</span> Dark Mode';
    });
}

// Mandatory Disclaimer Verification & Acceptance
async function checkDisclaimerStatus() {
    try {
        const res = await fetch('/api/disclaimer/status');
        const data = await res.json();
        const modal = document.getElementById('disclaimer-modal-overlay');
        const checkbox = document.getElementById('disclaimer-agree-checkbox');
        const acceptBtn = document.getElementById('disclaimer-accept-btn');
        const acceptBtnText = document.getElementById('disclaimer-accept-btn-text');

        if (!data.accepted) {
            if (modal) {
                modal.style.display = 'flex';
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden'; // Lock background scrolling
            }

            if (checkbox && acceptBtn) {
                checkbox.checked = false;
                acceptBtn.disabled = true;

                checkbox.addEventListener('change', () => {
                    acceptBtn.disabled = !checkbox.checked;
                });

                acceptBtn.onclick = async () => {
                    if (!checkbox.checked) return;
                    acceptBtn.disabled = true;
                    if (acceptBtnText) acceptBtnText.innerText = 'Persisting Authorization...';

                    try {
                        const postRes = await fetch('/api/disclaimer/accept', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' }
                        });
                        const postData = await postRes.json();
                        if (postData.status === 'success') {
                            if (modal) {
                                modal.style.display = 'none';
                                modal.setAttribute('aria-hidden', 'true');
                            }
                            document.body.style.overflow = '';
                        } else {
                            alert('Unable to persist acceptance. Please try again.');
                            acceptBtn.disabled = false;
                            if (acceptBtnText) acceptBtnText.innerText = 'I Understand & Accept Terms';
                        }
                    } catch (err) {
                        console.error('Disclaimer accept error:', err);
                        acceptBtn.disabled = false;
                        if (acceptBtnText) acceptBtnText.innerText = 'I Understand & Accept Terms';
                    }
                };
            }
        }
    } catch (err) {
        console.error('Failed to verify legal disclaimer status:', err);
    }
}

// Global Fetch Interceptor to automatically show progress for all asynchronous requests
const originalFetch = window.fetch;
let activeFetchCount = 0;
window.fetch = async function(...args) {
    activeFetchCount++;
    showGlobalProgress();
    try {
        const response = await originalFetch.apply(this, args);
        return response;
    } finally {
        activeFetchCount = Math.max(0, activeFetchCount - 1);
        if (activeFetchCount === 0) {
            hideGlobalProgress();
        }
    }
};

// Automatic Page Navigation Progress Feedback
document.addEventListener('DOMContentLoaded', () => {
    const currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';
    updateThemeToggleButtons(currentTheme);
    checkDisclaimerStatus();

    // Show prominent progress on internal link clicks and form submissions
    document.addEventListener('click', (e) => {
        const link = e.target.closest('a');
        if (link && link.href && !link.target && !link.hasAttribute('download') && !link.href.startsWith('javascript:') && !link.href.includes('#')) {
            const destUrl = new URL(link.href, window.location.origin);
            if (destUrl.origin === window.location.origin && destUrl.pathname !== window.location.pathname) {
                showGlobalProgress();
            }
        }
    });

    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (form && (!form.target || form.target === '_self')) {
            showGlobalProgress();
        }
    });

    window.addEventListener('beforeunload', () => {
        showGlobalProgress();
    });
});
