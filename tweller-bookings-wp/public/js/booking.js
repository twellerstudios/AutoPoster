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
            this.fetchAvailability();
        });
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
        this.renderPackages();
        this.goToStep(2);
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
    },

    renderSummary() {
        const summary = document.getElementById('tf2-summary-card');
        const d = new Date(this.selectedDate + 'T' + this.selectedTimeStr);
        const options = { weekday:'long', year:'numeric', month:'long', day:'numeric' };
        
        summary.innerHTML = `
            <h4>${this.selectedPackageData.name}</h4>
            <div class="tf2-summary-detail">${d.toLocaleDateString(undefined, options)} at ${this.selectedTimeDisplay}</div>
            <div style="margin-top: 10px; opacity: 0.9;">Total: TTD ${this.selectedPackageData.price}</div>
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
            session_time: this.selectedTimeStr
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
