// Lucide icon helper
function icon(name, size) {
  const s = size || 16;
  return `<i data-lucide="${name}" style="width:${s}px;height:${s}px;"></i>`;
}

// Toast Notification System
function initToastContainer() {
    if (!document.getElementById('toastContainer')) {
        const container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'toast-container';
        document.body.appendChild(container);
    }
}

function showToast(message, type = 'info', duration = 3500) {
    initToastContainer();
    const container = document.getElementById('toastContainer');
    const icons = {
        success: icon('circle-check', 20),
        error: icon('circle-x', 20),
        warning: icon('triangle-alert', 20),
        info: icon('circle-info', 20)
    };
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `
        <div class="toast-icon">${icons[type] || icons.info}</div>
        <div class="toast-body">
            <div class="toast-message">${escapeHTML(message)}</div>
        </div>
    `;
    container.appendChild(toast);
    if (window.lucide) lucide.createIcons();
    setTimeout(() => {
        toast.classList.add('toast-exit');
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

function showConfirm(message, onConfirm, onCancel = null, title = 'Tasdiqlash') {
    const overlay = document.createElement('div');
    overlay.className = 'confirm-overlay';
    overlay.innerHTML = `
        <div class="confirm-card">
            <div class="confirm-icon">🤔</div>
            <div class="confirm-title">${escapeHTML(title)}</div>
            <div class="confirm-message">${escapeHTML(message)}</div>
            <div class="confirm-actions">
                <button class="confirm-btn secondary" id="confirmCancel">Bekor qilish</button>
                <button class="confirm-btn primary" id="confirmOk">Tasdiqlash</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);
    overlay.querySelector('#confirmOk').addEventListener('click', () => {
        overlay.remove();
        if (onConfirm) onConfirm();
    });
    overlay.querySelector('#confirmCancel').addEventListener('click', () => {
        overlay.remove();
        if (onCancel) onCancel();
    });
}

// Telegram WebApp Initialization
let tgUser = { id: 123456789, username: "Foydalanuvchi" };
const IS_DEFAULT_USER = () => tgUser.id === 123456789;

(function() {
    const _params = new URLSearchParams(window.location.search);
    const queryUserId = _params.get('telegram_id') || _params.get('user_id');
    const queryUsername = _params.get('username');
    const queryFirstName = _params.get('first_name') || _params.get('name');
    if (queryUserId) tgUser.id = parseInt(queryUserId);
    if (queryUsername) tgUser.username = queryUsername;
    if (queryFirstName) tgUser.first_name = queryFirstName;
})();

if (window.Telegram && window.Telegram.WebApp) {
    const webApp = window.Telegram.WebApp;
    webApp.ready();
    webApp.expand();
    if (webApp.initDataUnsafe && webApp.initDataUnsafe.user) {
        tgUser = webApp.initDataUnsafe.user;
    }
}

document.addEventListener("DOMContentLoaded", () => {
    if (window.lucide) lucide.createIcons();
    updateLoginUI();
    loadSavedGallery();
    document.querySelectorAll('textarea, input[type="text"], input:not([type="radio"]):not([type="checkbox"])').forEach(el => {
        el.style.userSelect = 'text';
        el.style.webkitUserSelect = 'text';
    });
    const nameEl = document.getElementById("userName");
    const usernameEl = document.getElementById("userUsername");
    const avatarEl = document.getElementById("userAvatar");
    if (nameEl) {
        nameEl.innerText = tgUser.first_name || tgUser.username || "Foydalanuvchi";
    }
    if (usernameEl) {
        usernameEl.innerText = tgUser.username ? `@${tgUser.username}` : "";
    }
    if (avatarEl) {
        avatarEl.src = tgUser.photo_url ? tgUser.photo_url : `https://api.dicebear.com/7.x/bottts/svg?seed=${tgUser.id}`;
    }
    const ADMIN_IDS = [799317334];
    const adminBtn = document.getElementById("nav-admin");
    if (adminBtn) {
        adminBtn.style.display = ADMIN_IDS.includes(parseInt(tgUser.id)) ? "flex" : "none";
    }
    setTimeout(() => {
        checkAndClean3DayCache();
        const activeImg = document.querySelector('input[name="imageTariff"]:checked');
        if (activeImg) {
            const card = activeImg.closest('.tariff-card');
            if (card) card.click();
        }
        const activeVid = document.querySelector('input[name="videoTariff"]:checked');
        if (activeVid) {
            const card = activeVid.closest('.tariff-card');
            if (card) card.click();
        }
    }, 100);
});

let currentBalance = null;
let selectedImagePrice = 499;
let selectedVideoPrice = 3333;
const MAX_CODEBLOCKS = 50;

function checkAndClean3DayCache() {
    const THREE_DAYS_MS = 3 * 24 * 60 * 60 * 1000;
    const now = Date.now();
    try {
        for (let i = 0; i < localStorage.length; i++) {
            const key = localStorage.key(i);
            if (key && (key.startsWith('neuroinson_') || key.startsWith('chat_history_'))) {
                try {
                    const itemStr = localStorage.getItem(key);
                    if (itemStr && (itemStr.startsWith('{') || itemStr.startsWith('['))) {
                        const parsed = JSON.parse(itemStr);
                        let timestamp = null;
                        if (Array.isArray(parsed) && parsed.length > 0 && parsed[0].ts) {
                            timestamp = parsed[0].ts;
                        } else if (parsed && parsed.ts) {
                            timestamp = parsed.ts;
                        } else if (parsed && parsed.timestamp) {
                            timestamp = parsed.timestamp;
                        }
                        if (timestamp && (now - timestamp > THREE_DAYS_MS)) {
                            localStorage.removeItem(key);
                        }
                    }
                } catch(e) {}
            }
        }
    } catch (e) {
        console.error("Cache cleanup error:", e);
    }
}

function getSelectedPrice(type) {
    if (type === 'image') {
        const checkedRadio = document.querySelector('input[name="imageTariff"]:checked');
        if (checkedRadio) {
            const card = checkedRadio.closest('.tariff-card');
            if (card && card.getAttribute('onclick')) {
                const match = card.getAttribute('onclick').match(/(\d+)\s*\)/);
                if (match) return parseInt(match[1]);
            }
        }
        return selectedImagePrice || 499;
    } else {
        const checkedRadio = document.querySelector('input[name="videoTariff"]:checked');
        if (checkedRadio) {
            const card = checkedRadio.closest('.tariff-card');
            if (card && card.getAttribute('onclick')) {
                const match = card.getAttribute('onclick').match(/(\d+)\s*\)/);
                if (match) return parseInt(match[1]);
            }
        }
        return selectedVideoPrice || 3333;
    }
}

let otpEmail = '';
let loggedInEmail = '';
let otpResendTimer = null;

function openLoginModal() {
    document.getElementById('loginModal').style.display = 'block';
    document.getElementById('loginModalOverlay').style.display = 'block';
    document.getElementById('loginStep1').style.display = 'block';
    document.getElementById('loginStep2').style.display = 'none';
    document.getElementById('loginEmailInput').value = '';
    document.getElementById('loginEmailError').style.display = 'none';
    document.getElementById('otpError').style.display = 'none';
    document.getElementById('loginEmailInput').focus();
    resetOtpBoxes();
    if (window.lucide) lucide.createIcons();
    renderGoogleSignIn();
}
function closeLoginModal() {
    document.getElementById('loginModal').style.display = 'none';
    document.getElementById('loginModalOverlay').style.display = 'none';
    if (otpResendTimer) clearInterval(otpResendTimer);
}
function backToEmail() {
    document.getElementById('loginStep1').style.display = 'block';
    document.getElementById('loginStep2').style.display = 'none';
    document.getElementById('loginEmailInput').focus();
    if (otpResendTimer) clearInterval(otpResendTimer);
}

function showError(id, msg) {
    const el = document.getElementById(id);
    el.textContent = msg;
    el.style.display = 'block';
}

function resetOtpBoxes() {
    for (let i = 1; i <= 6; i++) {
        const box = document.getElementById('otp' + i);
        box.value = '';
        box.className = 'otp-box';
        box.disabled = false;
    }
}

function otpInput(el, index) {
    el.value = el.value.replace(/\D/g, '');
    el.className = 'otp-box';
    document.getElementById('otpError').style.display = 'none';
    if (el.value && index < 6) {
        document.getElementById('otp' + (index + 1)).focus();
    }
    if (index === 6 && el.value) {
        verifyOtp();
    }
}

function sendOtp() {
    const email = document.getElementById('loginEmailInput').value.trim();
    document.getElementById('loginEmailError').style.display = 'none';

    if (!email || !email.includes('@')) {
        showError('loginEmailError', "Iltimos, to'g'ri email kiriting");
        return;
    }

    const btn = document.getElementById('sendOtpBtn');
    btn.disabled = true;
    btn.innerHTML = '<i data-lucide="loader" style="width:16px;height:16px;animation:spin 1s linear infinite;"></i><span>Yuborilmoqda...</span>';
    if (window.lucide) lucide.createIcons();

    fetch('api/send-otp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: email })
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="mail" style="width:16px;height:16px;"></i><span>6-xonali kodni yuborish</span>';
        if (window.lucide) lucide.createIcons();

        if (data.error) {
            showError('loginEmailError', data.error);
            return;
        }

        otpEmail = email;
        document.getElementById('loginStep1').style.display = 'none';
        document.getElementById('loginStep2').style.display = 'block';
        document.getElementById('otpEmailDisplay').textContent = email;
        resetOtpBoxes();
        document.getElementById('otp1').focus();
        startResendCountdown();

        if (!data.smtp_configured) {
            showToast("Kod yuborildi (SMTP sozlanmagan — konsol yoki DB dan kodni oling)", "info");
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="mail" style="width:16px;height:16px;"></i><span>6-xonali kodni yuborish</span>';
        if (window.lucide) lucide.createIcons();
        showError('loginEmailError', "Serverga ulanishda xatolik");
    });
}

function startResendCountdown() {
    if (otpResendTimer) clearInterval(otpResendTimer);
    const btn = document.getElementById('resendOtpBtn');
    const cd = document.getElementById('resendCountdown');
    let sec = 60;
    btn.disabled = true;
    cd.textContent = sec;

    otpResendTimer = setInterval(() => {
        sec--;
        cd.textContent = sec;
        if (sec <= 0) {
            clearInterval(otpResendTimer);
            btn.disabled = false;
            cd.textContent = '';
        }
    }, 1000);
}

function resendOtp() {
    document.getElementById('loginEmailInput').value = otpEmail;
    sendOtp();
}

function verifyOtp() {
    let code = '';
    for (let i = 1; i <= 6; i++) {
        const box = document.getElementById('otp' + i);
        if (!box.value) {
            box.className = 'otp-box error';
            box.focus();
            return;
        }
        code += box.value;
    }

    document.getElementById('otpError').style.display = 'none';
    const btn = document.getElementById('verifyOtpBtn');
    btn.disabled = true;
    btn.innerHTML = '<i data-lucide="loader" style="width:16px;height:16px;animation:spin 1s linear infinite;"></i><span>Tekshirilmoqda...</span>';
    if (window.lucide) lucide.createIcons();

    fetch('api/verify-otp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: otpEmail, code: code })
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="log-in" style="width:16px;height:16px;"></i><span>Tasdiqlash</span>';
        if (window.lucide) lucide.createIcons();

        if (data.error) {
            showError('otpError', data.error);
            for (let i = 1; i <= 6; i++) {
                document.getElementById('otp' + i).className = 'otp-box error';
            }
            return;
        }

        // Login success
        tgUser.id = data.telegram_id;
        tgUser.username = data.username;
        tgUser.first_name = data.username;
        loggedInEmail = data.email;
        currentBalance = data.balance;
        updateBalanceDisplay();
        document.getElementById('userName').textContent = data.email;
        document.getElementById('userUsername').textContent = data.username;
        closeLoginModal();
        updateLoginUI();
        fetchUser();
        showToast(`Xush kelibsiz, ${escapeHTML(data.username)}!`, 'success');
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="log-in" style="width:16px;height:16px;"></i><span>Tasdiqlash</span>';
        if (window.lucide) lucide.createIcons();
        showError('otpError', "Serverga ulanishda xatolik");
    });
}

function handleGoogleCredential(response) {
    if (!response || !response.credential) return;
    fetch('api/google-auth.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id_token: response.credential })
    })
    .then(r => r.json())
    .then(data => {
        if (data.error) {
            showToast(data.error, 'error');
            return;
        }
        tgUser.id = data.telegram_id;
        tgUser.username = data.username;
        tgUser.first_name = data.username;
        loggedInEmail = data.email;
        currentBalance = data.balance;
        updateBalanceDisplay();
        document.getElementById('userName').textContent = data.email;
        document.getElementById('userUsername').textContent = data.username;
        closeLoginModal();
        updateLoginUI();
        fetchUser();
        showToast(`Xush kelibsiz, ${escapeHTML(data.username)}!`, 'success');
    })
    .catch(() => showToast("Google kirishda xatolik", 'error'));
}

function updateLoginUI() {
    const isDefault = IS_DEFAULT_USER();
    const balancePill = document.getElementById('topbarBalancePill');
    const historyBtn = document.getElementById('topbarHistoryBtn');
    const topbarEmail = document.getElementById('topbarUserEmail');
    const logoutBtn = document.getElementById('topbarLogoutBtn');
    const mobilePill = document.querySelector('.mobile-balance-pill');
    const sidebarPill = document.querySelector('.sidebar-balance-card');

    if (isDefault) {
        if (balancePill) balancePill.style.display = 'none';
        if (mobilePill) mobilePill.style.display = 'none';
        if (sidebarPill) sidebarPill.style.display = 'none';
        if (historyBtn) historyBtn.style.display = 'none';
        if (topbarEmail) topbarEmail.textContent = 'Log in';
        if (logoutBtn) logoutBtn.style.display = 'none';
        // Make email clickable to login
        if (topbarEmail) { topbarEmail.style.cursor = 'pointer'; topbarEmail.onclick = openLoginModal; }
    } else {
        if (balancePill) balancePill.style.display = 'inline-flex';
        if (mobilePill) mobilePill.style.display = 'flex';
        if (sidebarPill) sidebarPill.style.display = 'flex';
        if (historyBtn) historyBtn.style.display = 'inline-flex';
        if (topbarEmail) {
            topbarEmail.textContent = loggedInEmail || tgUser.username || tgUser.first_name;
            topbarEmail.style.cursor = 'default';
            topbarEmail.onclick = null;
        }
        if (logoutBtn) logoutBtn.style.display = 'inline-flex';
    }

    // Admin check: abdullajanovismoilxon@gmail.com is admin
    const ADMIN_IDS = [799317334];
    const adminBtn = document.getElementById("nav-admin");
    if (adminBtn) {
        const isAdminUser = ADMIN_IDS.includes(parseInt(tgUser.id)) || loggedInEmail === 'abdullajanovismoilxon@gmail.com';
        adminBtn.style.display = isAdminUser ? "flex" : "none";
    }
}

function logoutUser() {
    showConfirm("Hisobingizdan chiqishni xohlaysizmi?", () => {
        tgUser = { id: 123456789, username: "Foydalanuvchi" };
        loggedInEmail = '';
        currentBalance = null;
        document.getElementById('userName').textContent = 'Foydalanuvchi';
        document.getElementById('userUsername').textContent = '';
        updateLoginUI();
        updateBalanceDisplay();
        fetchUser();
        showToast("Chiqildi", 'info');
    });
}

// Helper: Google Sign-In button render
function renderGoogleSignIn() {
    const container = document.getElementById('googleSignInBtn');
    if (!container) return;

    if (typeof google === 'undefined' || !google.accounts) {
        // Retry when GSI script loads
        const checkGSI = setInterval(() => {
            if (typeof google !== 'undefined' && google.accounts) {
                clearInterval(checkGSI);
                renderGoogleSignIn();
            }
        }, 300);
        return;
    }

    // Function to copy text from AI messages (smart: copies code/prompt block if present)
    window.copyMessageText = function(btn, event) {
        if (event) event.stopPropagation();
        const msgContent = btn.closest('.msg-content');
        if (!msgContent) return;

        let textToCopy = "";

        // Check if there are code/prompt blocks (pre code or designated code elements)
        const codeBlocks = msgContent.querySelectorAll('pre code, code.prompt-code, .code-block-content');

        if (codeBlocks.length > 0) {
            // Extract ONLY the code/prompt text, ignoring surrounding intro/outro commentary
            textToCopy = Array.from(codeBlocks)
                .map(el => el.innerText.trim())
                .filter(txt => txt.length > 0)
                .join('\n\n')
                .trim();
        } else {
            // Fallback: Copy the entire message text
            const replyTextEl = msgContent.querySelector('.reply-text');
            if (replyTextEl) {
                textToCopy = replyTextEl.innerText.trim();
            } else {
                const paragraphs = msgContent.querySelectorAll('p');
                if (paragraphs.length > 0) {
                    textToCopy = Array.from(paragraphs).map(p => p.innerText.trim()).filter(Boolean).join('\n').trim();
                } else {
                    textToCopy = msgContent.innerText.trim();
                }
            }
        }

        if (!textToCopy) return;

        const showSuccess = () => {
            const originalHtml = btn.innerHTML;
            btn.innerHTML = `<span style="font-size:12px; display:inline-flex; align-items:center; gap:3px;">${icon('check', 12)} Nusxalandi!</span>`;
            if (window.lucide) lucide.createIcons();
            setTimeout(() => {
                btn.innerHTML = originalHtml;
                if (window.lucide) lucide.createIcons();
            }, 2000);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(textToCopy).then(showSuccess).catch(err => {
                console.error('Nusxalashda xatolik:', err);
                fallbackCopyText(textToCopy, showSuccess);
            });
        } else {
            fallbackCopyText(textToCopy, showSuccess);
        }
    };

    function fallbackCopyText(text, callback) {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        try {
            document.execCommand("copy");
            if (callback) callback();
        } catch (e) {
            console.error(e);
        }
        document.body.removeChild(textArea);
    }

    // Only initialize once
    if (container.dataset.gsiRendered) return;
    container.dataset.gsiRendered = '1';

    const wrapper = document.createElement('div');
    wrapper.id = 'gsiWrapper';
    wrapper.style.cssText = 'display:flex;justify-content:center;';
    container.parentNode.replaceChild(wrapper, container);

    const clientId = localStorage.getItem('neuroinson_google_client_id') || 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com';

    google.accounts.id.initialize({
        client_id: clientId,
        callback: handleGoogleCredential,
        cancel_on_tap_outside: false
    });
    google.accounts.id.renderButton(wrapper, {
        type: 'standard',
        shape: 'pill',
        theme: 'outline',
        text: 'signin_with',
        size: 'large',
        width: Math.min(wrapper.parentNode.offsetWidth || 360, 360)
    });
}

function crc32String(str) {
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
        hash = ((hash << 5) - hash) + str.charCodeAt(i);
        hash |= 0;
    }
    return hash;
}

const GALLERY_IMG_KEY = `neuroinson_img_${tgUser.id}`;
const GALLERY_VID_KEY = `neuroinson_vid_${tgUser.id}`;
const MAX_GALLERY = 3;

function getImgTariffLabel(price) {
    if (price >= 2000) return 'Biznes ⭐';
    if (price >= 666)  return 'Premium 💎';
    return 'Standart';
}

function saveImageToGallery(imgUrl, price) {
    try {
        const label = getImgTariffLabel(price);
        const stored = JSON.parse(localStorage.getItem(GALLERY_IMG_KEY) || '[]');
        stored.unshift({ url: imgUrl, label, price, ts: Date.now() });
        localStorage.setItem(GALLERY_IMG_KEY, JSON.stringify(stored.slice(0, MAX_GALLERY)));
    } catch(e) {}
}

function saveVideoToGallery(videoUrl, price, tier) {
    try {
        const stored = JSON.parse(localStorage.getItem(GALLERY_VID_KEY) || '[]');
        stored.unshift({ url: videoUrl, tier, price, ts: Date.now() });
        localStorage.setItem(GALLERY_VID_KEY, JSON.stringify(stored.slice(0, MAX_GALLERY)));
    } catch(e) {}
}

function loadSavedGallery() {
    try {
        const imgs = JSON.parse(localStorage.getItem(GALLERY_IMG_KEY) || '[]');
        const gallery = document.getElementById('imageGallery');
        if (gallery && imgs.length) {
            imgs.reverse().forEach(item => {
                gallery.insertAdjacentHTML('afterbegin', buildImageCard(item.url, item.label, item.price));
            });
        }
    } catch(e) {}
    try {
        const vids = JSON.parse(localStorage.getItem(GALLERY_VID_KEY) || '[]');
        const vGallery = document.getElementById('videoGallery');
        if (vGallery && vids.length) {
            vids.reverse().forEach(item => {
                vGallery.insertAdjacentHTML('afterbegin', buildVideoCard(item.url));
            });
        }
    } catch(e) {}
}

function buildImageCard(imgUrl, label, price) {
    const safeUrl = escapeHTML(imgUrl);
    const safeLabel = escapeHTML(label);
    const safePrice = parseInt(price) || 0;
    return `<div class="image-card-demo">
        <img src="${safeUrl}" alt="Generated AI" loading="lazy">
        <div class="image-overlay">
            <span>${safeLabel} • ${safePrice} so'm</span>
            <button onclick="downloadMedia('${safeUrl}')">${icon('download', 14)}</button>
        </div>
    </div>`;
}

function buildVideoCard(vUrl) {
    const safeUrl = escapeHTML(vUrl);
    return `<div class="video-card-demo">
        <video src="${safeUrl}" controls autoplay loop style="width:100%;border-radius:12px;margin-bottom:12px;"></video>
        <div style="margin-top:-45px;position:relative;z-index:10;padding:10px;">
            <button onclick="downloadMedia('${safeUrl}')" style="background:rgba(15,23,42,0.8);border:none;color:white;padding:6px 12px;border-radius:8px;cursor:pointer;display:flex;align-items:center;gap:6px;">
                ${icon('download', 14)} Yuklash
            </button>
        </div>
    </div>`;
}

async function fetchUser() {
    try {
        const res = await fetch(`api/get-user.php?telegram_id=${tgUser.id}&username=${encodeURIComponent(tgUser.username || '')}`);
        const data = await res.json();
        if (data && data.balance !== undefined) {
            const newBal = parseInt(data.balance);
            if (currentBalance !== null && newBal > currentBalance) {
                const diff = newBal - currentBalance;
                showToast(`Hisobingiz muvaffaqiyatli to'ldirildi! +${diff.toLocaleString('uz-UZ')} so'm qo'shildi.`, 'success');
                closeTopupModal();
                if (document.getElementById('historyModal').classList.contains('active')) {
                    loadTransactions();
                }
            }
            currentBalance = newBal;
            updateBalanceDisplay();
            if (data.project_chat_remaining !== undefined) {
                const credits = parseInt(data.project_chat_remaining);
                if (credits > 0) {
                    isProjectChatUnlocked = true;
                    const navBtn = document.getElementById('nav-project-chat');
                    if (navBtn) {
                        navBtn.style.opacity = '1';
                        navBtn.classList.remove('locked-tab');
                        const iconEl = navBtn.querySelector('[data-lucide]');
                        if (iconEl) iconEl.setAttribute('data-lucide', 'file-pen');
                        if (window.lucide) lucide.createIcons();
                    }
                    const chatBox = document.getElementById('project-chatChat');
                    if (chatBox) {
                        const welcomeMsgContent = chatBox.querySelector('.bot-message .msg-content');
                        if (welcomeMsgContent && !welcomeMsgContent.querySelector('.credits-badge')) {
                            const badge = `<span class="credits-badge" style="font-size:11px; background:rgba(52,211,153,0.15); color:var(--accent-green); padding:2px 8px; border-radius:10px; margin-top:4px; display:inline-flex;align-items:center;gap:4px;">${icon('gem', 12)} Loyiha limitidan qoldi: ${credits} ta xabar</span>`;
                            welcomeMsgContent.insertAdjacentHTML('beforeend', badge);
                            if (window.lucide) lucide.createIcons();
                        }
                    }
                }
            }
        }
    } catch (e) {
        console.error("Error fetching user data:", e);
    }
}

function animateCounter(el, start, end, duration = 800) {
    if (!el) return;
    let startTime = null;
    function step(timestamp) {
        if (!startTime) startTime = timestamp;
        const progress = Math.min((timestamp - startTime) / duration, 1);
        const easeOut = 1 - Math.pow(1 - progress, 3);
        const current = Math.floor(start + (end - start) * easeOut);
        el.innerText = current.toLocaleString('uz-UZ') + " so'm";
        if (progress < 1) {
            window.requestAnimationFrame(step);
        } else {
            el.innerText = end.toLocaleString('uz-UZ') + " so'm";
        }
    }
    window.requestAnimationFrame(step);
}

function updateBalanceDisplay() {
    if (currentBalance === null) return;
    const targetVal = currentBalance;
    ['userBalance', 'userBalanceMobile', 'userBalanceTopbar'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            const currentValStr = el.innerText.replace(/[^\d]/g, '');
            const startVal = currentValStr ? parseInt(currentValStr) : 0;
            if (startVal !== targetVal && startVal > 0 && Math.abs(startVal - targetVal) > 50) {
                animateCounter(el, startVal, targetVal);
            } else {
                el.innerText = targetVal.toLocaleString('uz-UZ') + " so'm";
            }
        }
    });
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
    }
}

let isProjectChatUnlocked = false;

const TAB_TITLES = {
    'antigravity': 'Neuroinson AI Chatbot',
    'projects': 'Loyihalar Markazi',
    'prompt': 'Prompt & Matn AI',
    'image': 'Rasm Generatsiyasi',
    'video': 'Video Generatsiyasi',
    'project-chat': 'Loyiha Muharriri AI',
    'admin': 'Admin Panel'
};

function switchTab(tabId) {
    if (tabId === 'project-chat' && !isProjectChatUnlocked) {
        showToast("Bu bo'lim yopiq! Faqatgina 'Loyihalar Markazi' bo'limidan biror loyihani sotib olganingizdan keyin ushbu bo'lim faollashadi.", "warning");
        return;
    }
    document.querySelectorAll('.nav-item').forEach(nav => nav.classList.remove('active'));
    document.querySelectorAll('.sidebar-nav-item').forEach(btn => btn.classList.remove('active'));
    const titleEl = document.getElementById('currentChatTitle');
    if (titleEl && TAB_TITLES[tabId]) {
        titleEl.innerText = TAB_TITLES[tabId];
    }
    const currentActive = document.querySelector('.tab-content.active');
    if (currentActive) {
        currentActive.style.opacity = '0';
        currentActive.style.transform = 'translateY(8px)';
        setTimeout(() => {
            currentActive.classList.remove('active');
            currentActive.style.opacity = '';
            currentActive.style.transform = '';
            const selectedTab = document.getElementById('tab-' + tabId);
            if (selectedTab) selectedTab.classList.add('active');
        }, 150);
    } else {
        const selectedTab = document.getElementById('tab-' + tabId);
        if (selectedTab) selectedTab.classList.add('active');
    }
    const navIndex = { 'antigravity': 0, 'projects': 1, 'prompt': 2, 'image': 3, 'video': 4, 'project-chat': 5, 'admin': 6 }[tabId];
    if (navIndex !== undefined) {
        const bottomNavs = document.querySelectorAll('.nav-item');
        if (bottomNavs[navIndex]) bottomNavs[navIndex].classList.add('active');
        const sidebarNavs = document.querySelectorAll('.sidebar-nav-item');
        if (sidebarNavs[navIndex]) sidebarNavs[navIndex].classList.add('active');
    }
    if (tabId === 'admin') {
        loadAdminStats();
        loadAdminUsage();
    }
    if (tabId === 'image' || tabId === 'video') {
        loadSavedGallery();
    }
}

function toggleSidebar() {
    const sidebar = document.getElementById('appSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (sidebar) sidebar.classList.toggle('active');
    if (overlay) overlay.classList.toggle('active');
}

function closeSidebar() {
    const sidebar = document.getElementById('appSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (sidebar) sidebar.classList.remove('active');
    if (overlay) overlay.classList.remove('active');
}

function closeSidebarOnMobile() {
    if (window.innerWidth < 768) closeSidebar();
}

function toggleSidebarCollapse() {
    const layout = document.getElementById('appLayout');
    if (layout) layout.classList.toggle('sidebar-collapsed');
}

function createNewChat() {
    switchTab('antigravity');
    const input = document.getElementById('antigravityInput');
    if (input) {
        input.value = '';
        input.focus();
    }
    showToast('Yangi suhbat boshlandi', 'info');
    closeSidebarOnMobile();
}

function setTheme(mode) {
    const html = document.documentElement;
    html.classList.remove('light-mode', 'dark-mode', 'night-mode');
    html.classList.add(mode + '-mode');
    localStorage.setItem('neuroinson_theme', mode);
    ['light', 'dark', 'night'].forEach(m => {
        const nameCap = m.charAt(0).toUpperCase() + m.slice(1);
        const b1 = document.getElementById('themeBtn' + nameCap);
        if (b1) {
            if (m === mode) b1.classList.add('active');
            else b1.classList.remove('active');
        }
        const b2 = document.getElementById('tbThemeBtn' + nameCap);
        if (b2) {
            if (m === mode) b2.classList.add('active');
            else b2.classList.remove('active');
        }
    });
    const labels = {
        'light': "Kunduzgi rejim (Light Mode)",
        'dark': "Tungi rejim (Dark Mode)",
        'night': "Tungi OLED rejim (Night/OLED)"
    };
    showToast(labels[mode] || "Mavzu o'zgartirildi", "info");
    if (window.lucide) setTimeout(() => lucide.createIcons(), 50);
}

(function() {
    const savedTheme = localStorage.getItem('neuroinson_theme') || 'light';
    setTimeout(() => setTheme(savedTheme), 50);
})();

function orderProject(projectName, price) {
    if (currentBalance < price) {
        showToast(`Balansda mablag' yetarli emas! Ushbu loyiha uchun ${price.toLocaleString('uz-UZ')} so'm kerak.`, 'error');
        openTopupModal();
        return;
    }
    showConfirm(`"${projectName}" loyihasiga buyurtma bera olasizmi? Narxi: ${price.toLocaleString('uz-UZ')} so'm.`, () => {
        isProjectChatUnlocked = true;
        localStorage.setItem(`neuroinson_active_project_name_${tgUser.id}`, projectName);
        const navBtn = document.getElementById('nav-project-chat');
        if (navBtn) {
            navBtn.style.opacity = '1';
            navBtn.classList.remove('locked-tab');
            const iconEl = navBtn.querySelector('[data-lucide]');
            if (iconEl) iconEl.setAttribute('data-lucide', 'file-signature');
            if (window.lucide) lucide.createIcons();
        }
        switchTab('project-chat');
        useQuickPrompt('project-chat', `Men uchun "${projectName}" loyihasini to'liq tayyorlab ber.`, price);
    });
}

function useQuickPrompt(chatType, text, projectPrice = null) {
    const inputEl = document.getElementById(chatType + 'Input');
    if (inputEl) {
        inputEl.value = text;
        sendMessage(chatType, projectPrice);
    }
}

function autoResizeTextarea(textarea) {
    if (!textarea) return;
    textarea.style.height = 'auto';
    const maxHeight = 160;
    if (textarea.scrollHeight > maxHeight) {
        textarea.style.height = maxHeight + 'px';
        textarea.style.overflowY = 'auto';
    } else {
        textarea.style.height = textarea.scrollHeight + 'px';
        textarea.style.overflowY = 'hidden';
    }
}

function handleKeyPress(event, chatType) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        sendMessage(chatType);
    }
}

async function sendMessage(chatType, projectPrice = null) {
    if (IS_DEFAULT_USER()) {
        showToast("Iltimos, ishlatish uchun avval tizimga kiring!", 'error');
        openLoginModal();
        return;
    }
    const inputEl = document.getElementById(chatType + 'Input');
    const chatBox = document.getElementById(chatType + 'Chat');
    const userText = inputEl.value.trim();
    if (!userText) return;
    const history = getChatHistory(chatType);
    const chatCost = projectPrice !== null ? projectPrice : 0;
    if (chatCost > 0 && currentBalance < chatCost) {
        showToast("Balansda mablag' yetarli emas! Iltimos, hisobingizni to'ldiring.", 'error');
        openTopupModal();
        return;
    }
    const userMsgHTML = `
        <div class="message user-message">
            <div class="msg-avatar">${icon('user', 16)}</div>
            <div class="msg-content">
                <p>${escapeHTML(userText)}</p>
                <span class="msg-time">${getCurrentTime()}</span>
            </div>
        </div>
    `;
    chatBox.insertAdjacentHTML('beforeend', userMsgHTML);
    if (window.lucide) lucide.createIcons();
    saveChatHistory(chatType);
    inputEl.value = '';
    autoResizeTextarea(inputEl);
    inputEl.blur();
    chatBox.scrollTop = chatBox.scrollHeight;
    const typingIndicatorHTML = `
        <div class="message bot-message typing-indicator" id="typing-${chatType}">
            <div class="msg-avatar">${icon('bot', 16)}</div>
            <div class="msg-content">
                <div class="typing-dots"><span></span><span></span><span></span></div>
            </div>
        </div>
    `;
    chatBox.insertAdjacentHTML('beforeend', typingIndicatorHTML);
    if (window.lucide) lucide.createIcons();
    chatBox.scrollTop = chatBox.scrollHeight;
    const activeProjectName = (chatType === 'project-chat') ? (localStorage.getItem(`neuroinson_active_project_name_${tgUser.id}`) || "") : "";
    try {
        const res = await fetch("api/chat.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                telegram_id: tgUser.id,
                prompt: userText,
                chat_type: chatType,
                project_price: projectPrice,
                project_name: activeProjectName,
                history: history
            })
        });
        const data = await res.json();
        const typingIndicator = document.getElementById(`typing-${chatType}`);
        if (typingIndicator) typingIndicator.remove();
        if (data.error) {
            showToast("Xatolik: " + data.error, 'error');
            return;
        }
        currentBalance = data.new_balance;
        updateBalanceDisplay();
        let limitBadge = '';
        if (data.daily_remaining !== undefined && data.daily_remaining !== null) {
            if (parseInt(data.daily_remaining) <= 0) {
                limitBadge = `<span style="font-size:11px; background:rgba(255,99,132,0.15); color:var(--accent-start); padding:2px 8px; border-radius:10px; margin-top:4px; display:inline-flex;align-items:center;gap:4px;">${icon('zap', 12)} Har bir keyingi xabar 50 so'm</span>`;
            } else {
                limitBadge = `<span style="font-size:11px; background:rgba(124,111,240,0.15); color:var(--accent-start); padding:2px 8px; border-radius:10px; margin-top:4px; display:inline-flex;align-items:center;gap:4px;">${icon('zap', 12)} Bugun qolgan: ${data.daily_remaining} ta bepul xabar</span>`;
            }
        } else if (data.project_chat_remaining !== undefined && data.project_chat_remaining !== null) {
            if (parseInt(data.project_chat_remaining) <= 0) {
                limitBadge = `<span class="credits-badge" style="font-size:11px; background:rgba(255,99,132,0.15); color:var(--accent-start); padding:2px 8px; border-radius:10px; margin-top:4px; display:inline-flex;align-items:center;gap:4px;">${icon('gem', 12)} Har bir keyingi xabar 200 so'm</span>`;
                document.querySelectorAll('.credits-badge').forEach(el => {
                    el.innerHTML = `${icon('gem', 12)} Har bir keyingi xabar 200 so'm`;
                });
            } else {
                limitBadge = `<span class="credits-badge" style="font-size:11px; background:rgba(52,211,153,0.15); color:var(--accent-green); padding:2px 8px; border-radius:10px; margin-top:4px; display:inline-flex;align-items:center;gap:4px;">${icon('gem', 12)} Loyiha limitidan qoldi: ${data.project_chat_remaining} ta xabar</span>`;
                document.querySelectorAll('.credits-badge').forEach(el => {
                    el.innerHTML = `${icon('gem', 12)} Loyiha limitidan qoldi: ${data.project_chat_remaining} ta xabar`;
                });
            }
        }
        let fileDownloadHTML = "";
        if (data.file_url) {
            fileDownloadHTML = `
                <div class="generated-file-download-box" style="margin-top:14px; padding:12px 16px; background:linear-gradient(135deg, rgba(99,102,241,0.15), rgba(168,85,247,0.15)); border:1px solid rgba(168,85,247,0.3); border-radius:12px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                    <div style="display:flex; align-items:center; gap:8px; color:white; font-size:13px; font-weight:600;">
                        <span style="font-size:18px;">📄</span>
                        <span>Tayyor Loyiha Fayli Generatsiya Qilindi</span>
                    </div>
                    <a href="${data.file_url}" download style="background:linear-gradient(135deg, #6366f1, #a855f7); color:white; font-weight:700; text-decoration:none; padding:8px 16px; border-radius:8px; font-size:12px; display:inline-flex; align-items:center; gap:6px; box-shadow:0 4px 14px rgba(168,85,247,0.4); transition:transform 0.2s;">
                        ${data.file_label || "📥 Faylni Yuklab Olish"}
                    </a>
                </div>
            `;
        }
        if (window.lucide) lucide.createIcons();
        const botMsgHTML = `
            <div class="message bot-message">
                <div class="msg-avatar">${icon('bot', 16)}</div>
                <div class="msg-content">
                    <button class="copy-btn" onclick="copyMessageText(this, event)" title="Nusxalash">${icon('copy', 14)}</button>
                    <div class="reply-text">${formatBotReply(data.reply)}</div>
                    ${fileDownloadHTML}
                    ${limitBadge}
                    <span class="msg-time">${getCurrentTime()}</span>
                </div>
            </div>
        `;
        chatBox.insertAdjacentHTML('beforeend', botMsgHTML);
        if (window.lucide) lucide.createIcons();
        saveChatHistory(chatType);
        chatBox.scrollTop = chatBox.scrollHeight;
    } catch (e) {
        console.error(e);
        const typingIndicator = document.getElementById(`typing-${chatType}`);
        if (typingIndicator) typingIndicator.remove();
        showToast("Sun'iy intellektga ulanib bo'lmadi.", 'error');
    }
}

function updateGenBtnUI() {
    const imgBtn = document.getElementById('generateImgBtn');
    if (imgBtn && !imgBtn.disabled) {
        imgBtn.innerHTML = `${icon('sparkles', 18)} Rasmni Yaratish (${selectedImagePrice.toLocaleString('uz-UZ')} so'm)`;
        if (window.lucide) lucide.createIcons();
    }
    const vidBtn = document.getElementById('generateVidBtn');
    if (vidBtn && !vidBtn.disabled) {
        vidBtn.innerHTML = `${icon('clapperboard', 18)} Videoni Yaratish (${selectedVideoPrice.toLocaleString('uz-UZ')} so'm)`;
        if (window.lucide) lucide.createIcons();
    }
}

function selectTariff(firstParam, secondParam, thirdParam) {
    let element, type, price;
    if (firstParam && typeof firstParam === 'object' && firstParam.classList) {
        element = firstParam;
        type = secondParam;
        price = thirdParam;
    } else {
        type = firstParam;
        const tariffLevel = secondParam;
        price = thirdParam;
        const tabId = (type === 'image') ? '#tab-image' : '#tab-video';
        const cards = document.querySelectorAll(`${tabId} .tariff-card`);
        const index = (tariffLevel === 'standard') ? 0 : ((tariffLevel === 'premium') ? 1 : 2);
        element = cards[index];
    }
    if (type === 'image') {
        selectedImagePrice = price;
        document.querySelectorAll('#tab-image .tariff-card').forEach(card => card.classList.remove('active'));
    } else {
        selectedVideoPrice = price;
        document.querySelectorAll('#tab-video .tariff-card').forEach(card => card.classList.remove('active'));
    }
    if (element && element.classList) {
        element.classList.add('active');
        const radio = element.querySelector('input[type="radio"]');
        if (radio) radio.checked = true;
    }
    updateGenBtnUI();

    const textareaId = (type === 'image') ? 'imagePromptInput' : 'videoPromptInput';
    const textarea = document.getElementById(textareaId);
    if (textarea) {
        setTimeout(() => textarea.focus(), 100);
    }
}

async function generateImage() {
    if (IS_DEFAULT_USER()) {
        showToast("Iltimos, ishlatish uchun avval tizimga kiring!", 'error');
        openLoginModal();
        return;
    }
    selectedImagePrice = getSelectedPrice('image');
    const promptText = document.getElementById('imagePromptInput').value.trim();
    if (!promptText) {
        showToast("Iltimos, rasm uchun tasviriy prompt yozing!", 'warning');
        return;
    }
    if (currentBalance < selectedImagePrice) {
        showToast(`Balansda mablag' yetarli emas! Ushbu tarif uchun ${selectedImagePrice} so'm kerak.`, 'error');
        openTopupModal();
        return;
    }
    const btn = document.getElementById('generateImgBtn');
    btn.disabled = true;
    btn.innerHTML = `<span class="loading-text">✦ AI rasm yaratmoqda...</span>`;
    try {
        const aspect = document.getElementById('imageSize').value;
        const res = await fetch("api/generate-image.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                telegram_id: tgUser.id,
                prompt: promptText,
                price: selectedImagePrice,
                aspect_ratio: aspect
            })
        });
        const data = await res.json();
        btn.disabled = false;
        updateGenBtnUI();
        if (data.error) {
            showToast("Xatolik: " + data.error, 'error');
            return;
        }
        currentBalance = data.new_balance;
        updateBalanceDisplay();
        const gallery = document.getElementById('imageGallery');
        const imgLabel = getImgTariffLabel(selectedImagePrice);
        const newImgHTML = buildImageCard(data.image_url, imgLabel, selectedImagePrice);
        gallery.insertAdjacentHTML('afterbegin', newImgHTML);
        if (window.lucide) lucide.createIcons();
        saveImageToGallery(data.image_url, selectedImagePrice);
        document.getElementById('imagePromptInput').value = '';
    } catch (e) {
        console.error(e);
        btn.disabled = false;
        updateGenBtnUI();
        showToast("Rasm yaratish xizmatida xatolik yuz berdi.", 'error');
    }
}

async function generateVideo() {
    if (IS_DEFAULT_USER()) {
        showToast("Iltimos, ishlatish uchun avval tizimga kiring!", 'error');
        openLoginModal();
        return;
    }
    selectedVideoPrice = getSelectedPrice('video');
    const promptText = document.getElementById('videoPromptInput').value.trim();
    if (!promptText) {
        showToast("Iltimos, video uchun tasviriy prompt yozing!", 'warning');
        return;
    }
    if (currentBalance < selectedVideoPrice) {
        showToast(`Balansda mablag' yetarli emas! Ushbu video tarif uchun ${selectedVideoPrice} so'm kerak.`, 'error');
        openTopupModal();
        return;
    }
    const btn = document.getElementById('generateVidBtn');
    btn.disabled = true;
    btn.innerHTML = `<span class="loading-text">✦ AI video yaratmoqda...</span>`;
    try {
        const aspect = document.getElementById('videoSize').value;
        const submitRes = await fetch("api/submit-video.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                telegram_id: tgUser.id,
                prompt: promptText,
                price: selectedVideoPrice,
                aspect_ratio: aspect
            })
        });
        const submitData = await submitRes.json();
        if (submitData.error) {
            btn.disabled = false;
            updateGenBtnUI();
            showToast("Xatolik: " + submitData.error, 'error');
            return;
        }
        const { request_id, status_url, response_url, model_path, tier, price: videoPrice } = submitData;
        let pollCount = 0;
        const maxPolls = 60;
        const msgs = ["✦ AI video yaratmoqda...", "✦ AI render qilmoqda...", "✦ AI: Deyarli tayyor...", "✦ AI: Yakunlanmoqda..."];
        const poll = async () => {
            if (pollCount >= maxPolls) {
                btn.disabled = false;
                updateGenBtnUI();
                showToast("Video yaratish vaqti tugadi. Qayta urinib ko'ring.", 'warning');
                return;
            }
            pollCount++;
            const msgIdx = Math.min(Math.floor(pollCount / 6), msgs.length - 1);
            btn.innerHTML = `<span class="loading-text">${msgs[msgIdx]} (${pollCount * 3}s)</span>`;
            try {
                const checkRes = await fetch("api/check-video.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({
                        request_id, status_url, response_url, model_path,
                        telegram_id: tgUser.id,
                        price: videoPrice || selectedVideoPrice,
                        tier: tier
                    })
                });
                const checkData = await checkRes.json();
                if (checkData.status === "done" && checkData.video_url) {
                    btn.disabled = false;
                    updateGenBtnUI();
                    const gallery = document.getElementById('videoGallery');
                    gallery.insertAdjacentHTML('afterbegin', buildVideoCard(checkData.video_url));
                    saveVideoToGallery(checkData.video_url, selectedVideoPrice, tier);
                    if (checkData.new_balance !== null && checkData.new_balance !== undefined) {
                        currentBalance = checkData.new_balance;
                        updateBalanceDisplay();
                    } else {
                        fetchUser();
                    }
                    document.getElementById('videoPromptInput').value = '';
                } else if (checkData.status === "error") {
                    btn.disabled = false;
                    updateGenBtnUI();
                    showToast("Video xatolik: " + (checkData.message || "Noma'lum xatolik"), 'error');
                } else {
                    if (checkData.message) {
                        btn.innerHTML = `<span class="loading-text">✦ ${checkData.message} (${pollCount * 3}s)</span>`;
                    }
                    setTimeout(poll, 3000);
                }
            } catch (pollErr) {
                setTimeout(poll, 3000);
            }
        };
        setTimeout(poll, 3000);
    } catch (e) {
        console.error("Video submit error:", e);
        btn.disabled = false;
        updateGenBtnUI();
        showToast("Video yuborishda xatolik: " + (e.message || "Noma'lum xatolik"), 'error');
    }
}

const modal = document.getElementById('topupModal');
document.getElementById('openTopupBtn').addEventListener('click', openTopupModal);

function openTopupModal() {
    modal.classList.add('active');
}

function closeTopupModal() {
    modal.classList.remove('active');
    resetTopupModal();
}

function resetTopupModal() {
    document.getElementById('paymentDetails').style.display = "none";
    document.getElementById('topupSelectionBox').style.display = "block";
    document.getElementById('customAmountInput').value = "";
}

function openHistoryModal() {
    document.getElementById('historyModal').classList.add('active');
    loadTransactions();
}

function closeHistoryModal() {
    document.getElementById('historyModal').classList.remove('active');
}

async function loadTransactions() {
    const list = document.getElementById('transactionList');
    list.innerHTML = '<div style="text-align:center;color:var(--text-tertiary);padding:20px;">✦ Yuklanmoqda...</div>';
    try {
        const res = await fetch(`api/get-transactions.php?telegram_id=${tgUser.id}&limit=30`);
        const data = await res.json();
        if (!data.transactions || data.transactions.length === 0) {
            list.innerHTML = '<div style="text-align:center;color:var(--text-tertiary);padding:30px;"><span style="font-size:24px;display:block;margin-bottom:8px;">📋</span> Hozircha tranzaksiyalar yo\'q</div>';
            return;
        }
        list.innerHTML = data.transactions.map(tx => {
            const isIncome = tx.amount > 0;
            const color = isIncome ? '#10b981' : '#f59e0b';
            const bg = isIncome ? 'rgba(16,185,129,0.08)' : 'rgba(245,158,11,0.08)';
            const border = isIncome ? 'rgba(16,185,129,0.2)' : 'rgba(245,158,11,0.2)';
            const date = new Date(tx.created_at).toLocaleString('uz-UZ', {month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
            return `
              <div style="display:flex;justify-content:space-between;align-items:center;background:${bg};border:1px solid ${border};border-radius:10px;padding:10px 14px;">
                <div style="display:flex;align-items:center;gap:10px;">
                  <span style="font-size:20px;">${escapeHTML(tx.icon)}</span>
                  <div>
                    <div style="font-size:13px;font-weight:600;color:white;">${escapeHTML(tx.description || tx.type)} <span style="font-size:11px;color:#94a3b8;font-weight:normal;margin-left:4px;">#${tx.id}</span></div>
                    <div style="font-size:11px;color:#64748b;">${escapeHTML(date)}</div>
                  </div>
                </div>
                <span style="font-size:14px;font-weight:700;color:${color};">${escapeHTML(tx.amount_fmt)}</span>
              </div>`;
        }).join('');
    } catch(e) {
        list.innerHTML = '<div style="color:#ef4444;text-align:center;padding:20px;">Xatolik yuz berdi</div>';
    }
}

async function generateInvoice(baseAmount) {
    try {
        const res = await fetch("api/topup.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ telegram_id: tgUser.id, amount: baseAmount })
        });
        const data = await res.json();
        if (data.status === "success") {
            document.getElementById('uniqueAmount').innerText = data.unique_amount.toLocaleString('uz-UZ') + " so'm";
            document.getElementById('uniqueMsg').innerText = data.message;
            if (data.card_number) {
                document.getElementById('cardNumber').innerText = data.card_number;
            }
            if (data.card_owner) {
                document.querySelector('.card-owner').innerText = "Ega: " + data.card_owner;
            }
            document.getElementById('topupSelectionBox').style.display = "none";
            document.getElementById('paymentDetails').style.display = "block";
        }
    } catch (e) {
        showToast("To'lov hisob-kitobini olishda xatolik yuz berdi.", 'error');
    }
}

function submitCustomInvoice() {
    const val = parseInt(document.getElementById('customAmountInput').value);
    if (!val || val < 1000) {
        showToast("Iltimos, kamida 1000 so'm kiriting!", 'warning');
        return;
    }
    generateInvoice(val);
}

function copyCardNumber() {
    const cardNum = document.getElementById('cardNumber').innerText;
    navigator.clipboard.writeText(cardNum.replace(/\s/g, ''));
    showToast("Karta raqami nusxalandi ✓", 'success');
}

function copyAmountNumber() {
    const amtText = document.getElementById('uniqueAmount').innerText;
    const onlyDigits = amtText.replace(/[^\d]/g, '');
    navigator.clipboard.writeText(onlyDigits);
    showToast("To'lov summasi nusxalandi ✓", 'success');
}

function escapeHTML(str) {
    if (typeof str !== 'string') return String(str || '');
    return str.replace(/[&<>'"]/g, tag => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[tag] || tag));
}

function getCurrentTime() {
    const now = new Date();
    return now.getHours().toString().padStart(2, '0') + ':' + now.getMinutes().toString().padStart(2, '0');
}

function downloadMedia(url) {
    window.open(url, '_blank');
}

window.codeBlocks = {};

function formatBotReply(text) {
    if (!text) return "";
    let html = escapeHTML(text);
    html = html.replace(/```(\w*)\n([\s\S]*?)```/g, function(match, lang, code) {
        const codeId = 'code-' + Math.random().toString(36).substr(2, 9);
        const decodedCode = code.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#39;/g, "'");
        // Limit codeblocks to prevent memory leak
        const keys = Object.keys(window.codeBlocks);
        if (keys.length >= MAX_CODEBLOCKS) {
            delete window.codeBlocks[keys[0]];
        }
        window.codeBlocks[codeId] = decodedCode;
        const cleanLang = (lang.trim() || 'code').toLowerCase();
        const isHtml = cleanLang === 'html' || decodedCode.includes('<html') || decodedCode.includes('<!DOCTYPE html>') || decodedCode.includes('</div>') || decodedCode.includes('</style>');
        const previewBtn = isHtml ? `<button onclick="previewHTMLBlock('${codeId}')" style="background:#3b82f6; color:white; border:none; padding:4px 10px; border-radius:6px; font-size:11px; cursor:pointer; font-weight:600; margin-right:4px;">${icon('eye', 12)} Slaydni Ko'rish</button>` : '';
        return `
            <div class="code-block-wrapper" style="background:var(--bg-elevated); border-radius:12px; border:1px solid var(--border-color); margin:15px 0; overflow:hidden; font-family:Consolas, Monaco, monospace; font-size:13px; max-width:100%;">
                <div class="code-block-header" style="display:flex; justify-content:space-between; align-items:center; background:rgba(255,255,255,0.04); padding:8px 16px; border-bottom:1px solid rgba(255,255,255,0.06);">
                    <span style="color:var(--text-tertiary); font-size:12px; font-weight:600; text-transform:uppercase;">${escapeHTML(cleanLang)}</span>
                    <div style="display:flex; gap:8px;">
                        ${previewBtn}
                        <button onclick="copyCodeBlock('${codeId}', this)" style="background:var(--bg-surface); color:var(--text-secondary); border:1px solid var(--border-color); padding:4px 10px; border-radius:6px; font-size:11px; cursor:pointer; font-weight:600; display:flex; align-items:center; gap:4px;">${icon('copy', 12)} Nusxalash</button>
                        <button onclick="openDeployModal('${codeId}')" style="background:var(--accent-green); color:white; border:none; padding:4px 10px; border-radius:6px; font-size:11px; cursor:pointer; font-weight:600; display:flex; align-items:center; gap:4px;">${icon('cloud-upload', 12)} Serverga joylash</button>
                    </div>
                </div>
                <pre style="margin:0; padding:16px; overflow-x:auto; white-space:pre;"><code style="color:var(--text-primary); display:block;">${code}</code></pre>
            </div>`;
    });
    html = html.replace(/`([^`\n]+)`/g, '<code style="background:#F1F5F9; color:#6366F1; padding:2px 6px; border-radius:4px; font-family:monospace; font-size:13px;">$1</code>');
    html = html.replace(/\*\*([\s\S]*?)\*\*/g, '<strong>$1</strong>');
    const parts = html.split('<div class="code-block-wrapper"');
    for (let i = 0; i < parts.length; i++) {
        if (i === 0) {
            parts[i] = parts[i].replace(/\n/g, '<br>');
        } else {
            const endIdx = parts[i].indexOf('</div>\n            </div>');
            if (endIdx !== -1) {
                const wrapperContent = parts[i].substring(0, endIdx + 22);
                const remainingText = parts[i].substring(endIdx + 22);
                parts[i] = wrapperContent + remainingText.replace(/\n/g, '<br>');
            } else {
                parts[i] = parts[i].replace(/\n/g, '<br>');
            }
        }
    }
    html = parts.join('<div class="code-block-wrapper"');
    return html;
}

function copyCodeBlock(codeId, btn) {
    const codeText = window.codeBlocks[codeId];
    if (navigator.clipboard) {
        navigator.clipboard.writeText(codeText).then(() => {
            btn.innerHTML = `${icon('check', 12)} Nusxalandi!`;
            if (window.lucide) lucide.createIcons();
            setTimeout(() => {
                btn.innerHTML = `${icon('copy', 12)} Nusxalash`;
                if (window.lucide) lucide.createIcons();
            }, 2000);
        });
    } else {
        const textArea = document.createElement("textarea");
        textArea.value = codeText;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand("copy");
        document.body.removeChild(textArea);
        btn.innerHTML = `${icon('check', 12)} Nusxalandi!`;
        if (window.lucide) lucide.createIcons();
        setTimeout(() => {
            btn.innerHTML = `${icon('copy', 12)} Nusxalash`;
            if (window.lucide) lucide.createIcons();
        }, 2000);
    }
}

function previewHTMLBlock(codeId) {
    const codeText = window.codeBlocks[codeId];
    if (!codeText) return;
    const win = window.open();
    if (win) {
        win.document.write(codeText);
        win.document.close();
    } else {
        showToast("Pop-up taqiqlangan! Iltimos, brauzer sozlamalaridan ushbu sahifaga pop-up oynalar ochishga ruxsat bering (Allow Popups).", 'warning');
    }
}

function getChatHistory(chatType) {
    const chatBox = document.getElementById(chatType + 'Chat');
    if (!chatBox) return [];
    const messages = [];
    const msgEls = Array.from(chatBox.querySelectorAll('.message')).slice(-4);
    msgEls.forEach(el => {
        if (el.classList.contains('typing-indicator')) return;
        const role = el.classList.contains('user-message') ? 'user' : 'model';
        const pEl = el.querySelector('.msg-content p') || el.querySelector('.reply-text');
        if (pEl) {
            messages.push({ role: role, text: pEl.innerText || pEl.textContent });
        }
    });
    return messages;
}

function saveChatHistory(chatType) {
    const chatBox = document.getElementById(chatType + 'Chat');
    if (chatBox) {
        localStorage.setItem(`neuroinson_chat_${chatType}_${tgUser.id}`, chatBox.innerHTML);
    }
}

function loadChatHistory(chatType) {
    const chatBox = document.getElementById(chatType + 'Chat');
    if (chatBox) {
        const saved = localStorage.getItem(`neuroinson_chat_${chatType}_${tgUser.id}`);
        if (saved) {
            const temp = document.createElement('div');
            temp.innerHTML = saved;
            if (!temp.querySelector('script')) {
                chatBox.innerHTML = saved;
                chatBox.scrollTop = chatBox.scrollHeight;
            }
        }
    }
}

function openDeployModal(codeId) {
    const code = window.codeBlocks[codeId];
    document.getElementById('deployCodeInput').value = code;
    const deployType = document.getElementById('deployType');
    if (code.includes('import telebot') || code.includes('import telegram') || code.includes('updater') || code.includes('BOT_TOKEN')) {
        deployType.value = 'python-bot';
    } else {
        deployType.value = 'html-site';
    }
    const statusDiv = document.getElementById('deployStatus');
    statusDiv.style.display = 'none';
    statusDiv.innerText = '';
    document.getElementById('deployModal').classList.add('active');
}

function closeDeployModal() {
    document.getElementById('deployModal').classList.remove('active');
}

async function submitDeployment() {
    const code = document.getElementById('deployCodeInput').value;
    const type = document.getElementById('deployType').value;
    const ip = document.getElementById('deployIp').value.trim();
    const password = document.getElementById('deployPassword').value.trim();
    if (!ip || !password) {
        showToast("Server IP va SSH paroli kiritilishi shart!", 'warning');
        return;
    }
    const submitBtn = document.getElementById('deploySubmitBtn');
    const statusDiv = document.getElementById('deployStatus');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="loading-text">✦ Joylashtirilmoqda...</span>';
    statusDiv.style.display = 'block';
    statusDiv.style.background = 'rgba(59, 130, 246, 0.1)';
    statusDiv.style.border = '1px solid rgba(59, 130, 246, 0.2)';
    statusDiv.style.color = '#3b82f6';
    statusDiv.innerText = "Serverga ulanish o'rnatilmoqda va dasturlar o'rnatilmoqda, bu 15-30 soniya vaqt olishi mumkin. Iltimos kuting...";
    try {
        const res = await fetch("api/deploy-to-vps.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ ip, password, code, type })
        });
        const data = await res.json();
        if (data.status === "success") {
            statusDiv.style.background = 'rgba(16, 185, 129, 0.1)';
            statusDiv.style.border = '1px solid rgba(16, 185, 129, 0.2)';
            statusDiv.style.color = '#10b981';
            statusDiv.innerText = data.message;
            submitBtn.innerHTML = '<span>✓ Muaffaqiyatli yakunlandi!</span>';
            setTimeout(closeDeployModal, 5000);
        } else {
            statusDiv.style.background = 'rgba(239, 68, 68, 0.1)';
            statusDiv.style.border = '1px solid rgba(239, 68, 68, 0.2)';
            statusDiv.style.color = '#ef4444';
            statusDiv.innerText = "Xatolik: " + data.message;
            submitBtn.disabled = false;
            submitBtn.innerHTML = `${icon('cloud-upload', 16)} Serverga Joylash (Deploy)`;
            if (window.lucide) lucide.createIcons();
        }
    } catch (e) {
        statusDiv.style.background = 'rgba(239, 68, 68, 0.1)';
        statusDiv.style.border = '1px solid rgba(239, 68, 68, 0.2)';
        statusDiv.style.color = '#ef4444';
        statusDiv.innerText = "Tarmoq ulanish xatoligi. Qayta urinib ko'ring.";
        submitBtn.disabled = false;
        submitBtn.innerHTML = `${icon('cloud-upload', 16)} Serverga Joylash (Deploy)`;
        if (window.lucide) lucide.createIcons();
    }
}

let selectedAdminUserId = null;
let currentAdminPeriod = 'today';

function setAdminPeriod(period) {
    currentAdminPeriod = period;
    ['today','week','month','year','all'].forEach(p => {
        const btn = document.getElementById('filter-' + p);
        if (!btn) return;
        if (p === period) {
            btn.style.border = '1px solid #3b82f6';
            btn.style.color = '#3b82f6';
        } else {
            btn.style.border = '1px solid #334155';
            btn.style.color = '#94a3b8';
        }
    });
    loadAdminStats();
}

async function loadAdminStats() {
    const searchVal = document.getElementById("adminSearchInput")?.value || '';
    const loadIndicator = document.getElementById("admin-total-users");
    if (loadIndicator) loadIndicator.innerText = '...';
    try {
        const res = await fetch(`api/admin-stats.php?telegram_id=${tgUser.id}&period=${currentAdminPeriod}&search=${encodeURIComponent(searchVal)}`);
        const data = await res.json();
        if (data.status === "success") {
            const stats = data.stats;
            const el = (id) => document.getElementById(id);
            if (el("admin-total-users")) el("admin-total-users").innerText = stats.total_users;
            if (el("admin-new-users")) el("admin-new-users").innerText = stats.new_users;
            if (el("admin-total-balance")) el("admin-total-balance").innerText = (stats.total_balance || 0).toLocaleString('uz-UZ') + " so'm";
            if (el("admin-total-topups")) el("admin-total-topups").innerText = ((stats.period_topups ?? stats.total_topups) || 0).toLocaleString('uz-UZ') + " so'm";
            if (el("admin-total-spent")) el("admin-total-spent").innerText = ((stats.period_spent ?? stats.total_spent) || 0).toLocaleString('uz-UZ') + " so'm";
            if (el("admin-mrr")) el("admin-mrr").innerText = (stats.mrr || 0).toLocaleString('uz-UZ') + " so'm";
            if (el("admin-period-expenses")) el("admin-period-expenses").innerText = (stats.period_expenses || 0).toLocaleString('uz-UZ') + " so'm";
            if (el("admin-net-profit")) el("admin-net-profit").innerText = (stats.net_profit || 0).toLocaleString('uz-UZ') + " so'm";
            const userBody = el("adminUserTableBody");
            if (userBody) {
                if (!data.users || data.users.length === 0) {
                    userBody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:20px;color:#64748b;">Foydalanuvchilar topilmadi.</td></tr>`;
                } else {
                    window.adminUsersMap = {};
                    userBody.innerHTML = data.users.map(u => {
                        window.adminUsersMap[u.telegram_id] = u;
                        const date = new Date(u.created_at).toLocaleDateString('uz-UZ');
                        const safeUser = escapeHTML(u.username || 'Noma\'lum');
                        const safeEmail = escapeHTML(u.email || '-');
                        const safeId = u.telegram_id;
                        
                        const authBadge = u.auth_method === 'google'
                            ? `<span style="font-size:10px; background:rgba(234,67,53,0.15); color:#ea4335; padding:2px 6px; border-radius:6px; font-weight:600;">Google</span>`
                            : `<span style="font-size:10px; background:rgba(36,161,222,0.15); color:#24a1de; padding:2px 6px; border-radius:6px; font-weight:600;">Telegram</span>`;
                            
                        const statusBadge = u.status === 'blocked'
                            ? `<span style="font-size:10px; background:rgba(239,68,68,0.15); color:#ef4444; padding:2px 6px; border-radius:6px; font-weight:600;">Bloklangan</span>`
                            : u.status === 'warned'
                            ? `<span style="font-size:10px; background:rgba(245,158,11,0.15); color:#f59e0b; padding:2px 6px; border-radius:6px; font-weight:600;">Ogohlantirilgan</span>`
                            : `<span style="font-size:10px; background:rgba(16,185,129,0.15); color:#10b981; padding:2px 6px; border-radius:6px; font-weight:600;">Faol</span>`;

                        return `<tr style="border-bottom:1px solid #1e293b;">
                            <td style="padding:10px 12px;font-size:11px;color:#64748b;">${safeId}</td>
                            <td style="padding:10px 12px;font-weight:600;color:white;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${safeUser}">${safeUser}</td>
                            <td style="padding:10px 12px;color:#94a3b8;font-size:12px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${safeEmail}">${safeEmail}</td>
                            <td style="padding:10px 12px;">${authBadge}</td>
                            <td style="padding:10px 12px;color:#10b981;font-weight:600;">${(u.balance || 0).toLocaleString('uz-UZ')} so'm</td>
                            <td style="padding:10px 12px;color:#64748b;font-size:11px;">${escapeHTML(date)}</td>
                            <td style="padding:10px 12px;">${statusBadge}</td>
                            <td style="padding:10px 12px;text-align:right;white-space:nowrap;">
                                <button onclick="openAdminEditUserModalById(${safeId})" style="background:var(--accent-gradient);color:white;border:none;padding:5px 10px;border-radius:6px;cursor:pointer;font-size:11px;font-weight:600;display:inline-flex;align-items:center;gap:4px;">${icon('user-cog', 12)} Tahrirlash</button>
                                <button onclick="deleteUser(${safeId})" style="background:rgba(239,68,68,0.15);color:#ef4444;border:none;padding:5px 8px;border-radius:6px;cursor:pointer;font-size:11px;font-weight:600;margin-left:4px;">${icon('trash-2', 12)}</button>
                            </td>
                        </tr>`;
                    }).join('');
                }
            }
            const topupList = el("adminRecentTopupsList");
            if (topupList) {
                if (data.recent_topups.length === 0) {
                    topupList.innerHTML = `<div style="text-align:center;padding:20px;color:#64748b;">Kirimlar mavjud emas.</div>`;
                } else {
                    topupList.innerHTML = data.recent_topups.map(tx => {
                        const date = new Date(tx.created_at).toLocaleString('uz-UZ');
                        return `<div style="display:flex;justify-content:space-between;align-items:center;background:rgba(16,185,129,0.05);border:1px solid rgba(16,185,129,0.15);border-radius:8px;padding:8px 12px;font-size:12px;">
                            <div><strong style="color:white;">${escapeHTML(tx.username || tx.telegram_id)}</strong><div style="color:#64748b;font-size:10px;">${escapeHTML(tx.description || '')} • ${escapeHTML(date)}</div></div>
                            <strong style="color:#10b981;">+${(tx.amount || 0).toLocaleString('uz-UZ')} so'm</strong>
                        </div>`;
                    }).join('');
                }
            }
            const expensesListEl = el("adminExpensesList");
            if (expensesListEl) {
                if (!data.expenses || data.expenses.length === 0) {
                    expensesListEl.innerHTML = `<div style="text-align:center;padding:20px;color:#64748b;">Hozircha xarajatlar kiritilmagan.</div>`;
                } else {
                    expensesListEl.innerHTML = data.expenses.map(exp => {
                        const date = new Date(exp.created_at).toLocaleString('uz-UZ');
                        return `<div style="display:flex;justify-content:space-between;align-items:center;background:rgba(239,68,68,0.05);border:1px solid rgba(239,68,68,0.15);border-radius:8px;padding:8px 12px;font-size:12px;">
                            <div><strong style="color:white;">${escapeHTML(exp.description)}</strong><div style="color:#64748b;font-size:10px;">ID: #${exp.id} • ${escapeHTML(date)}</div></div>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <strong style="color:#ef4444;">-${(exp.amount || 0).toLocaleString('uz-UZ')} so'm</strong>
                                <button onclick="deletePlatformExpense(${exp.id})" style="background:rgba(239,68,68,0.2);border:none;color:#ef4444;border-radius:4px;padding:3px 6px;cursor:pointer;">${icon('trash-2', 12)}</button>
                            </div>
                        </div>`;
                    }).join('');
                }
            }
        }
    } catch (e) {
        console.error(e);
    }
}

let currentEditUserObj = null;

function openAdminEditUserModalById(id) {
    const uObj = (window.adminUsersMap && window.adminUsersMap[id]) ? window.adminUsersMap[id] : { telegram_id: id };
    openAdminEditUserModal(uObj);
}

function openAdminEditUserModal(userObj) {
    if (typeof userObj === 'string') {
        try { userObj = JSON.parse(userObj); } catch(e) {}
    }
    currentEditUserObj = userObj;
    
    document.getElementById("adminEditUserId").innerText = userObj.telegram_id || '--';
    document.getElementById("adminEditAuthBadge").innerHTML = userObj.auth_method === 'google'
        ? `<span style="font-size:10px; background:rgba(234,67,53,0.15); color:#ea4335; padding:2px 6px; border-radius:6px; font-weight:600;">Google</span>`
        : `<span style="font-size:10px; background:rgba(36,161,222,0.15); color:#24a1de; padding:2px 6px; border-radius:6px; font-weight:600;">Telegram</span>`;
        
    document.getElementById("adminEditUsernameInput").value = userObj.username || '';
    document.getElementById("adminEditEmailInput").value = userObj.email || '';
    document.getElementById("adminEditBalanceInput").value = userObj.balance !== undefined ? userObj.balance : 0;
    document.getElementById("adminEditStatusSelect").value = userObj.status || 'active';
    
    const errEl = document.getElementById("adminEditErrorMsg");
    if (errEl) { errEl.style.display = 'none'; errEl.innerText = ''; }

    document.getElementById("adminEditUserModal").classList.add("active");
}

function closeAdminEditUserModal() {
    document.getElementById("adminEditUserModal").classList.remove("active");
}

async function saveAdminUserEdit() {
    if (!currentEditUserObj) return;

    const username = document.getElementById("adminEditUsernameInput").value.trim();
    const email = document.getElementById("adminEditEmailInput").value.trim();
    const balance = parseInt(document.getElementById("adminEditBalanceInput").value);
    const status = document.getElementById("adminEditStatusSelect").value;
    const errEl = document.getElementById("adminEditErrorMsg");

    if (errEl) { errEl.style.display = 'none'; errEl.innerText = ''; }

    if (!username) {
        if (errEl) { errEl.innerText = "Foydalanuvchi nomi bo'sh bo'lishi mumkin emas!"; errEl.style.display = 'block'; }
        return;
    }

    if (email && (!email.includes('@') || !email.includes('.'))) {
        if (errEl) { errEl.innerText = "Email manzili formati noto'g'ri!"; errEl.style.display = 'block'; }
        return;
    }

    if (isNaN(balance) || balance < 0) {
        if (errEl) { errEl.innerText = "Balans to'g'ri (manfiy bo'lmagan) son bo'lishi kerak!"; errEl.style.display = 'block'; }
        return;
    }

    try {
        const res = await fetch("api/admin-action.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                admin_id: tgUser.id,
                action: "update_user",
                target_id: currentEditUserObj.telegram_id,
                username: username,
                email: email,
                balance: balance,
                status: status
            })
        });
        const data = await res.json();
        if (data.status === "success") {
            showToast(data.message || "Muvaffaqiyatli saqlandi!", 'success');
            closeAdminEditUserModal();
            loadAdminStats();
            if (currentEditUserObj.telegram_id === tgUser.id) fetchUser();
        } else {
            const errorMsg = data.error || data.message || "Saqlashda xatolik yuz berdi";
            if (errEl) { errEl.innerText = errorMsg; errEl.style.display = 'block'; }
            showToast(errorMsg, 'error');
        }
    } catch (e) {
        if (errEl) { errEl.innerText = "Tarmoq xatoligi yuz berdi."; errEl.style.display = 'block'; }
        showToast("Tarmoq xatoligi yuz berdi.", 'error');
    }
}

async function loadAdminUsage() {
    const days = document.getElementById('usageDays')?.value || 7;
    try {
        const res = await fetch(`api/admin-usage.php?telegram_id=${tgUser.id}&days=${days}`);
        const data = await res.json();
        if (data.status !== "success") return;
        document.getElementById('adminTotalCalls').textContent = data.total_calls;

        // Build API usage list
        const listEl = document.getElementById('adminUsageList');
        if (data.by_api.length === 0) {
            listEl.innerHTML = '<div class="empty-state">Hali API chaqiruvlari mavjud emas.</div>';
        } else {
            listEl.innerHTML = data.by_api.map(a => {
                const ms = a.total_ms ? ` • ${(a.total_ms / a.count).toFixed(0)} ms` : '';
                return `<div class="admin-usage-item"><span class="api-name">${escapeHTML(a.name)}</span><span><span class="api-count">${a.count}</span><span class="api-ms">${ms}</span></span></div>`;
            }).join('');
        }

        // Destroy existing charts
        if (window._apiPieChart) { window._apiPieChart.destroy(); window._apiPieChart = null; }
        if (window._apiBarChart) { window._apiBarChart.destroy(); window._apiBarChart = null; }

        if (typeof Chart === 'undefined') return;

        // Pie chart — by API
        const pieCtx = document.getElementById('apiUsagePieChart');
        if (pieCtx && data.by_api.length > 0) {
            const colors = ['#7C6FF0', '#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#ec4899'];
            window._apiPieChart = new Chart(pieCtx, {
                type: 'doughnut',
                data: {
                    labels: data.by_api.map(a => a.name),
                    datasets: [{
                        data: data.by_api.map(a => a.count),
                        backgroundColor: colors.slice(0, data.by_api.length),
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'bottom', labels: { color: '#94a3b8', font: { size: 11 } } }
                    }
                }
            });
        }

        // Bar chart — by day
        const barCtx = document.getElementById('apiUsageBarChart');
        if (barCtx && data.by_day.length > 0) {
            window._apiBarChart = new Chart(barCtx, {
                type: 'bar',
                data: {
                    labels: data.by_day.map(d => d.day.slice(5)),
                    datasets: [{
                        label: 'API chaqiruvlari',
                        data: data.by_day.map(d => d.count),
                        backgroundColor: '#7C6FF0',
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        x: { ticks: { color: '#64748b', font: { size: 10 } }, grid: { display: false } },
                        y: { ticks: { color: '#64748b', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,0.05)' } }
                    }
                }
            });
        }
    } catch (e) {
        console.error('Usage stats error:', e);
    }
}

async function deleteUser(userId) {
    showConfirm(`Foydalanuvchi #${userId} ni o'chirishni xohlaysizmi?`, async () => {
        try {
            const res = await fetch("api/admin-action.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ admin_id: tgUser.id, action: "delete_user", target_id: userId })
            });
            const data = await res.json();
            if (data.status === "success") {
                showToast(data.message, 'success');
                loadAdminStats();
            } else {
                showToast(data.error, 'error');
            }
        } catch (e) {
            showToast("Tarmoq xatoligi", 'error');
        }
    });
}

async function addPlatformExpense() {
    const name = document.getElementById('expenseNameInput').value.trim();
    const amount = parseInt(document.getElementById('expenseAmountInput').value);
    if (!name || isNaN(amount) || amount <= 0) {
        showToast("Xarajat nomi va summasini to'g'ri kiriting", 'warning');
        return;
    }
    try {
        const res = await fetch("api/admin-action.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ admin_id: tgUser.id, action: "add_expense", amount: amount, description: name })
        });
        const data = await res.json();
        if (data.status === "success") {
            showToast(data.message, 'success');
            document.getElementById('expenseNameInput').value = '';
            document.getElementById('expenseAmountInput').value = '';
            loadAdminStats();
        } else {
            showToast(data.error, 'error');
        }
    } catch (e) {
        showToast("Tarmoq xatoligi", 'error');
    }
}

async function deletePlatformExpense(expId) {
    showConfirm("Xarajatni o'chirishni xohlaysizmi?", async () => {
        try {
            const res = await fetch("api/admin-action.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ admin_id: tgUser.id, action: "delete_expense", expense_id: expId })
            });
            const data = await res.json();
            if (data.status === "success") {
                showToast(data.message, 'success');
                loadAdminStats();
            } else {
                showToast(data.error, 'error');
            }
        } catch (e) {
            showToast("Tarmoq xatoligi", 'error');
        }
    });
}

async function sendBroadcast() {
    const textVal = document.getElementById("broadcastInput").value.trim();
    if (textVal === "") {
        showToast("Iltimos, xabar matnini kiriting!", 'warning');
        return;
    }
    if (!confirm("Haqiqatan ham barcha foydalanuvchilarga ushbu xabarni tarqatmoqchimisiz?")) return;
    const btn = document.querySelector("button[onclick='sendBroadcast()']");
    btn.disabled = true;
    btn.innerHTML = `<span class="loading-text">✦ Tarqatilmoqda...</span>`;
    try {
        const res = await fetch("api/admin-action.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ admin_id: tgUser.id, action: "broadcast", message: textVal })
        });
        const data = await res.json();
        if (data.status === "success") {
            showToast(`Xabar ${data.count} ta foydalanuvchiga yuborildi!`, 'success');
        } else {
            showToast("Xatolik: " + data.error, 'error');
        }
        btn.disabled = false;
        btn.innerHTML = `${icon('megaphone', 16)} Xabar Tarqatish`;
        if (window.lucide) lucide.createIcons();
    } catch (e) {
        showToast("Tarmoq xatoligi yuz berdi.", 'error');
        btn.disabled = false;
        btn.innerHTML = `${icon('megaphone', 16)} Xabar Tarqatish`;
        if (window.lucide) lucide.createIcons();
    }
}

async function loadSavedGallery() {
    if (IS_DEFAULT_USER()) return;
    const gallery = document.getElementById('imageGallery');
    if (!gallery) return;

    try {
        const res = await fetch(`api/get-my-gallery.php?telegram_id=${tgUser.id}`);
        const data = await res.json();
        if (data.status === "success" && Array.isArray(data.media)) {
            if (data.media.length === 0) {
                gallery.innerHTML = '<div style="grid-column: 1 / -1; text-align:center; color:var(--text-tertiary); padding:30px;"><span style="font-size:32px;display:block;margin-bottom:8px;">🎨</span> Hozircha siz yaratgan rasmlar yoki videolar yo\'q</div>';
                return;
            }
            gallery.innerHTML = data.media.map(m => {
                const label = m.tariff_label || (m.media_type === 'video' ? 'Video' : 'Rasm');
                return buildImageCard(m.file_url, label, m.price, m.media_type);
            }).join('');
            if (window.lucide) lucide.createIcons();
        }
    } catch (e) {
        console.error("Gallery loading error:", e);
    }
}

// Initialize user on load
document.addEventListener("DOMContentLoaded", () => {
    fetchUser();
    loadChatHistory('antigravity');
    loadChatHistory('prompt');
    loadChatHistory('project-chat');
    loadSavedGallery();
});

/* ── Pause aurora animation when tab hidden (battery saving) ── */
document.addEventListener('visibilitychange', () => {
  const bg = document.querySelector('.aurora-bg');
  if (!bg) return;
  bg.style.animationPlayState = document.hidden ? 'paused' : '';
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(modal => {
            modal.classList.remove('active');
        });
    }
});
