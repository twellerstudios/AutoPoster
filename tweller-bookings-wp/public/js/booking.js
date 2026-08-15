// tweller-flow-2/public/js/booking.js

const tfBooking = {
    step: 1,
    selectedSessionTypeKey: null,
    selectedSessionData: null,
    selectedPackageKey: null,
    selectedPackageData: null,
    selectedDate: null,
    selectedTimeStr: null,
    selectedTimeDisplay: null,

    init() {
        if (!window.twellerBooking) return;
        this.renderSessionTypes();

        document.getElementById('tf2-booking-date').addEventListener('change', (e) => {
            this.selectedDate = e.target.value;
            this.selectedTimeStr = null;
            this.selectedTimeDisplay = null;
            this.fetchAvailability();
            this.saveState();
        });

        this.wireWhyModal();
        this.wirePersistence();
        this.syncConsentSelection();
        this.restoreState();
    },

    // ── "Why is there a privacy fee?" ──────────────────
    // Read on the page. Sending someone to another tab mid-booking is how
    // you lose the booking.

    wireWhyModal() {
        const modal = document.getElementById('tf2-why-modal');
        const open  = document.getElementById('tf2-why-open');
        if (!modal || !open) return;

        const show = () => {
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            const x = document.getElementById('tf2-why-close');
            if (x) x.focus();
        };
        const hide = () => {
            modal.style.display = 'none';
            document.body.style.overflow = '';
            open.focus();
        };

        open.addEventListener('click', show);
        ['tf2-why-close', 'tf2-why-done', 'tf2-why-close-bg'].forEach((id) => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('click', hide);
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.style.display !== 'none') hide();
        });
    },

    /*
     * Mirror the checked radio onto a class. The card styling uses :has(),
     * which older Safari and Firefox ESR do not support — without this the
     * chosen option would give no visual feedback at all on those browsers.
     */
    syncConsentSelection() {
        const opts = document.querySelectorAll('.tf2-consent-opt');
        opts.forEach((opt) => {
            const input = opt.querySelector('input[type="radio"]');
            opt.classList.toggle('is-selected', !!(input && input.checked));
        });
    },

    // ── Resume an interrupted booking ──────────────────
    // Everything except the moment of submission is recoverable, so a phone
    // call, a closed tab or a stray back-button no longer costs the visitor
    // their whole booking.

    storeKey() {
        return 'tf_booking_draft_v1';
    },

    saveState() {
        try {
            const el = (id) => document.getElementById(id);
            const consent = document.querySelector('input[name="tf2_image_consent"]:checked');
            localStorage.setItem(this.storeKey(), JSON.stringify({
                v: 1,
                savedAt: Date.now(),
                step: this.step,
                sessionTypeKey: this.selectedSessionTypeKey,
                packageKey: this.selectedPackageKey,
                date: this.selectedDate,
                timeStr: this.selectedTimeStr,
                timeDisplay: this.selectedTimeDisplay,
                consent: consent ? consent.value : 'limited',
                name:  el('tf2-client-name')  ? el('tf2-client-name').value  : '',
                email: el('tf2-client-email') ? el('tf2-client-email').value : '',
                phone: el('tf2-client-phone') ? el('tf2-client-phone').value : '',
                notes: el('tf2-client-notes') ? el('tf2-client-notes').value : ''
            }));
        } catch (e) { /* private mode / quota — never block the booking */ }
    },

    clearState() {
        try { localStorage.removeItem(this.storeKey()); } catch (e) {}
    },

    readState() {
        try {
            const raw = localStorage.getItem(this.storeKey());
            if (!raw) return null;
            const s = JSON.parse(raw);
            if (!s || s.v !== 1) return null;
            // A fortnight-old draft is a stale price, not a convenience.
            if (Date.now() - (s.savedAt || 0) > 14 * 24 * 60 * 60 * 1000) return null;
            return s;
        } catch (e) { return null; }
    },

    // Save on every input, not just on step changes, so a half-typed form
    // survives too.
    wirePersistence() {
        ['tf2-client-name', 'tf2-client-email', 'tf2-client-phone', 'tf2-client-notes'].forEach((id) => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('input', () => this.saveState());
        });
        document.querySelectorAll('input[name="tf2_image_consent"]').forEach((el) => {
            el.addEventListener('change', () => { this.syncConsentSelection(); this.saveState(); });
        });
    },

    restoreState() {
        const s = this.readState();
        if (!s || !s.sessionTypeKey) return;

        const types = window.twellerBooking.sessionTypes || {};
        const pkgs  = window.twellerBooking.packages || {};
        const type  = types[s.sessionTypeKey];
        if (!type) { this.clearState(); return; }

        // Rebuild the chain step by step, stopping wherever the saved data no
        // longer holds up — a package that was withdrawn, a date now in the
        // past. Better to land them on the last valid step than to restore a
        // booking that cannot be completed.
        this.selectedSessionTypeKey = s.sessionTypeKey;
        this.selectedSessionData = type;
        this.renderPackages();
        let target = 2;

        const pkg = s.packageKey ? pkgs[s.packageKey] : null;
        if (pkg) {
            this.selectedPackageKey = s.packageKey;
            this.selectedPackageData = pkg;
            target = 3;
        }

        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const stillFuture = s.date && new Date(s.date + 'T00:00:00') >= today;

        if (pkg && stillFuture) {
            this.selectedDate = s.date;
            const dateEl = document.getElementById('tf2-booking-date');
            if (dateEl) dateEl.value = s.date;

            if (s.timeStr && s.timeDisplay) {
                this.selectedTimeStr = s.timeStr;
                this.selectedTimeDisplay = s.timeDisplay;
                this.renderSummary();
                target = 4;
            }
            // Re-check the slot is genuinely still free; the grid re-renders
            // from the server's answer, not from what we saved.
            this.fetchAvailability();
        }

        // Restore the details form regardless of which step we land on.
        const set = (id, val) => { const el = document.getElementById(id); if (el && val) el.value = val; };
        set('tf2-client-name',  s.name);
        set('tf2-client-email', s.email);
        set('tf2-client-phone', s.phone);
        set('tf2-client-notes', s.notes);

        if (s.consent) {
            const radio = document.querySelector('input[name="tf2_image_consent"][value="' + s.consent + '"]');
            if (radio) radio.checked = true;
            this.syncConsentSelection();
        }

        this.goToStep(target);
        if (target === 4) this.renderSummary();
        this.showResumeNotice(target < 4 && s.step === 4);
    },

    showResumeNotice(downgraded) {
        const host = document.getElementById('tf2-resume-notice');
        if (!host) return;
        host.querySelector('.tf2-resume__text').textContent = downgraded
            ? 'We saved your booking, but that date or time is no longer available — please pick another.'
            : 'We picked up where you left off.';
        host.style.display = 'flex';

        const startOver = document.getElementById('tf2-resume-reset');
        if (startOver && !startOver.dataset.wired) {
            startOver.dataset.wired = '1';
            startOver.addEventListener('click', () => {
                this.clearState();
                window.location.reload();
            });
        }
    },

    renderSessionTypes() {
        const container = document.getElementById('tf2-sessions-container');
        if (!container) return;
        
        container.innerHTML = '';
        const types = window.twellerBooking.sessionTypes;

        for (const [key, type] of Object.entries(types)) {
            const el = document.createElement('div');
            el.className = 'tf2-package-card tf2-session-card';
            if (key === 'weddings') {
                el.innerHTML = `
                    <div class="tf2-pkg-title">${type.name}</div>
                    <div class="tf2-pkg-detail" style="margin-bottom:15px; color:var(--tf2-text);">${type.description}</div>
                    <a href="mailto:hello@twellerstudios.com?subject=Wedding Inquiry" class="tf2-btn-inquiry" onclick="event.stopPropagation()">Inquire Now</a>
                `;
                el.onclick = () => { window.location.href = 'mailto:hello@twellerstudios.com?subject=Wedding Inquiry'; };
            } else {
                el.innerHTML = `
                    <div class="tf2-pkg-title">${type.name}</div>
                    <div class="tf2-pkg-detail" style="color:var(--tf2-text);">${type.description}</div>
                `;
                el.onclick = () => this.selectSessionType(key, type);
            }
            container.appendChild(el);
        }
    },

    selectSessionType(key, type) {
        if (key === 'weddings') return;
        this.selectedSessionTypeKey = key;
        this.selectedSessionData = type;
        this.selectedPackageKey = null;
        this.selectedPackageData = null;
        this.renderPackages();
        this.goToStep(2);
        this.saveState();
    },

    renderPackages() {
        const container = document.getElementById('tf2-packages-container');
        if (!container) return;
        
        container.innerHTML = '';
        const pkgs = window.twellerBooking.packages;
        const allowed = this.selectedSessionData.allowed_packages || [];

        for (const [key, pkg] of Object.entries(pkgs)) {
            if (!allowed.includes(key) && allowed.length > 0) continue; // Show all if allowed empty/not set (fallback), else filter

            const el = document.createElement('div');
            el.className = 'tf2-package-card';
            
            let topBooked = key === 'mini' ? '<div class="tf2-top-booked">Top Booked</div>' : '';
            
            let html = `
                ${topBooked}
                <div class="tf2-pkg-title">${pkg.name}</div>
            `;
            
            if (pkg.old_price) {
                html += `<div class="tf2-pkg-price" style="display:flex; align-items:center; gap:8px;"><span style="text-decoration:line-through; font-size:16px; color:#A3A3A3; font-weight:500;">TTD ${pkg.old_price}</span> <span>TTD ${pkg.price}</span></div>`;
            } else {
                html += `<div class="tf2-pkg-price">TTD ${pkg.price}</div>`;
            }
            
            if (pkg.features && Array.isArray(pkg.features)) {
                html += `<ul class="tf2-pkg-features">`;
                pkg.features.forEach(f => {
                    html += `<li><span class="tf2-checkmark"></span> ${f}</li>`;
                });
                html += `</ul>`;
            } else {
                html += `
                    <div class="tf2-pkg-detail">🕒 ${pkg.duration} Minutes</div>
                    <div class="tf2-pkg-detail">📸 ${pkg.images} Edited Images</div>
                    <div class="tf2-pkg-detail">👥 Up to ${pkg.members} People</div>
                `;
            }
            
            el.innerHTML = html;
            el.onclick = () => this.selectPackage(key, pkg);
            container.appendChild(el);
        }
    },

    selectPackage(key, pkg) {
        this.selectedPackageKey = key;
        this.selectedPackageData = pkg;
        this.goToStep(3);
        this.saveState();

        // if date already selected, refetch to apply correct duration
        if (this.selectedDate) {
            this.fetchAvailability();
        }
    },

    async fetchAvailability() {
        if (!this.selectedDate || !this.selectedPackageData) return;

        const slotsContainer = document.getElementById('tf2-slots-container');
        slotsContainer.innerHTML = '<p class="tf2-slots-empty">Checking availability...</p>';

        try {
            const res = await fetch(`${window.twellerBooking.restUrl}availability?date=${this.selectedDate}&duration=${this.selectedPackageData.duration}`);
            const data = await res.json();

            if (data.available_slots && data.available_slots.length > 0) {
                slotsContainer.innerHTML = '';
                data.available_slots.forEach(slot => {
                    const btn = document.createElement('div');
                    btn.className = 'tf2-slot-btn';
                    btn.innerText = slot.display;
                    btn.onclick = () => this.selectTime(slot.time, slot.display);
                    slotsContainer.appendChild(btn);
                });
            } else {
                slotsContainer.innerHTML = '<p class="tf2-slots-empty">No slots available for this date. Please try another date.</p>';
            }
        } catch (err) {
            slotsContainer.innerHTML = '<p class="tf2-slots-empty" style="color:red">Failed to load availability.</p>';
        }
    },

    selectTime(timeStr, displayStr) {
        this.selectedTimeStr = timeStr;
        this.selectedTimeDisplay = displayStr;
        this.renderSummary();
        this.goToStep(4);
        this.saveState();
    },

    renderSummary() {
        const summary = document.getElementById('tf2-summary-card');
        const d = new Date(this.selectedDate + 'T' + this.selectedTimeStr);
        const options = { weekday:'long', year:'numeric', month:'long', day:'numeric' };

        const base = parseFloat(String(this.selectedPackageData.price).replace(/[^0-9.]/g, '')) || 0;
        const cfg = document.getElementById('tf2-consent-cfg');
        const fee = cfg ? (parseFloat(cfg.dataset.fee) || 0) : 0;
        const choiceEl = document.querySelector('input[name="tf2_image_consent"]:checked');
        const choice = choiceEl ? choiceEl.value : 'limited';

        const fmt = (n) => n.toLocaleString(undefined, { maximumFractionDigits: 2 });
        let lines = '';
        let total = base;
        if (choice === 'declined' && fee > 0) {
            total = base + fee;
            lines = `
                <div style="display:flex; justify-content:space-between; opacity:0.9; font-size:14px; margin-top:8px;"><span>Session</span><span>TTD ${fmt(base)}</span></div>
                <div style="display:flex; justify-content:space-between; opacity:0.9; font-size:14px;"><span>Privacy fee</span><span>TTD ${fmt(fee)}</span></div>`;
        } else if (choice === 'unlimited') {
            lines = `<div style="font-size:12.5px; color:#C9A227; margin-top:8px;">✓ You'll earn a discount on your next session.</div>`;
        }

        summary.innerHTML = `
            <h4>${this.selectedPackageData.name}</h4>
            <div class="tf2-summary-detail">${d.toLocaleDateString(undefined, options)} at ${this.selectedTimeDisplay}</div>
            ${lines}
            <div style="margin-top: 10px; opacity: 0.95; font-weight:600;">Total: TTD ${fmt(total)}</div>
        `;
    },

    goToStep(num) {
        document.getElementById(`tf2-step-${this.step}`).style.display = 'none';
        document.querySelector(`.tf2-step[data-step="${this.step}"]`).classList.remove('active');
        document.querySelector(`.tf2-step[data-step="${this.step}"]`).classList.add('completed');
        
        this.step = num;
        
        document.getElementById(`tf2-step-${this.step}`).style.display = 'block';
        document.querySelector(`.tf2-step[data-step="${this.step}"]`).classList.add('active');
        document.querySelector(`.tf2-step[data-step="${this.step}"]`).classList.remove('completed');

        // Reset later steps
        for(let i = this.step + 1; i <= 4; i++) {
            let el = document.querySelector(`.tf2-step[data-step="${i}"]`);
            if(el) {
                el.classList.remove('active');
                el.classList.remove('completed');
            }
        }
    },

    prevStep() {
        if (this.step > 1) {
            this.goToStep(this.step - 1);
        }
    },

    buildInquiryMessage() {
        const type = this.selectedSessionData ? this.selectedSessionData.name : '';
        const pkg = this.selectedPackageData ? this.selectedPackageData.name.split('—')[0].trim() : '';
        const loc = document.getElementById('tf2-client-notes').value || 'Not specified';
        
        let dateStr = this.selectedDate;
        if (this.selectedDate) {
            const d = new Date(this.selectedDate + 'T' + this.selectedTimeStr);
            const options = { weekday:'long', year:'numeric', month:'long', day:'numeric' };
            dateStr = d.toLocaleDateString(undefined, options);
        }

        return `Hi good day, can you confirm availability for an upcoming session?\n\nSession Type: ${type}\nPackage: ${pkg}\nDate: ${dateStr}\nTime: ${this.selectedTimeDisplay || ''}\nLocation: ${loc}`;
    },

    sendWhatsApp() {
        const msg = encodeURIComponent(this.buildInquiryMessage());
        // User requested number: 18683422948
        window.open(`https://wa.me/18683422948?text=${msg}`, '_blank');
    },

    sendEmail() {
        const msg = encodeURIComponent(this.buildInquiryMessage());
        window.open(`mailto:hello@twellerstudios.com?subject=Availability Inquiry&body=${msg}`, '_blank');
    },

    async submitBooking() {
        const name = document.getElementById('tf2-client-name').value;
        const email = document.getElementById('tf2-client-email').value;
        const phone = document.getElementById('tf2-client-phone').value;
        const notes = document.getElementById('tf2-client-notes').value;
        
        const payload = {
            client_name: name,
            client_email: email,
            client_phone: phone,
            location: notes,
            notes: notes,
            session_type: this.selectedSessionTypeKey,
            package_type: this.selectedPackageKey,
            session_date: this.selectedDate,
            session_time: this.selectedTimeStr,
            image_consent: (document.querySelector('input[name="tf2_image_consent"]:checked') || {}).value || 'limited'
        };

        const loading = document.getElementById('tf2-booking-loading');
        const errorMsg = document.getElementById('tf2-booking-error');
        const submitBtn = document.getElementById('tf2-submit-btn');

        // Read the label off the DOM rather than hard-coding it, so the
        // button always restores to whatever the shortcode rendered —
        // "Reserve Booking · Continue to Payment" today, anything later.
        const submitLabel = submitBtn ? submitBtn.textContent : '';
        const restoreSubmit = () => {
            if (!submitBtn) return;
            submitBtn.disabled = false;
            submitBtn.textContent = submitLabel;
        };
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Reserving your session…';
        }

        loading.style.display = 'flex';
        errorMsg.style.display = 'none';

        try {
            const res = await fetch(`${window.twellerBooking.restUrl}book`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const data = await res.json();
            
            if (data.success) {
                this.clearState();
                // Redirect to tracker page!
                let tUrl = window.twellerBooking.trackerUrl;
                let sep = tUrl.includes('?') ? '&' : '?';
                window.location.href = tUrl + sep + 'code=' + data.tracking_code;
            } else {
                loading.style.display = 'none';
                restoreSubmit();
                errorMsg.innerText = data.message || 'An error occurred. Please try again.';
                errorMsg.style.display = 'block';
            }
        } catch (err) {
            loading.style.display = 'none';
            restoreSubmit();
            errorMsg.innerText = 'Network error. Please try again.';
            errorMsg.style.display = 'block';
        }
    }
};

document.addEventListener('DOMContentLoaded', () => {
    tfBooking.init();
});
