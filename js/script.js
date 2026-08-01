/**
 * YAKAP GAMOT SYSTEM - JavaScript Enhancements
 * Phase 2: Toasts, AJAX saves, autocomplete, back-to-top, today button, confirmations
 */

'use strict';

// ============================================================
// BACK TO TOP BUTTON
// ============================================================
(function() {
    const btn = document.getElementById('back-to-top');
    if (!btn) return;

    window.addEventListener('scroll', function() {
        if (window.scrollY > 400) {
            btn.classList.add('visible');
        } else {
            btn.classList.remove('visible');
        }
    });

    btn.addEventListener('click', function() {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
})();

// ============================================================
// TOAST NOTIFICATION SYSTEM
// ============================================================
(function() {
    // Create toast container if not exists
    let toastContainer = document.querySelector('.toast-container');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.className = 'toast-container';
        document.body.appendChild(toastContainer);
    }

    window.showToast = function(message, type) {
        type = type || 'success';
        const toast = document.createElement('div');
        toast.className = 'toast toast-' + type;
        toast.textContent = message;
        toastContainer.appendChild(toast);

        // Remove after animation completes
        setTimeout(function() {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 3000);
    };
})();

// ============================================================
// CONFIRM DELETE WRAPPER (for dynamic content)
// ============================================================
(function() {
    document.addEventListener('click', function(e) {
        const target = e.target.closest('[data-confirm]');
        if (target) {
            if (!confirm(target.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
        }
    });
})();

// ============================================================
// TODAY BUTTON ON DATE PICKERS
// ============================================================
(function() {
    document.querySelectorAll('.btn-today').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            const input = this.closest('.date-picker-form').querySelector('input[type="date"]');
            if (input) {
                const today = new Date();
                const yyyy = today.getFullYear();
                const mm = String(today.getMonth() + 1).padStart(2, '0');
                const dd = String(today.getDate()).padStart(2, '0');
                input.value = yyyy + '-' + mm + '-' + dd;
            }
        });
    });
})();

// ============================================================
// AUTO-SUBMIT YEAR SELECTOR (fallback for when JS is on)
// ============================================================
(function() {
    document.querySelectorAll('select[data-auto-submit]').forEach(function(select) {
        select.addEventListener('change', function() {
            this.form.submit();
        });
    });
})();

// ============================================================
// PATIENT AUTOCOMPLETE (using datalist from server)
// ============================================================
(function() {
    // This works with the existing patient_name input and a datalist element
    // The datalist should have id="patient-list" filled by PHP
})();

// ============================================================
// AJAX SAVE FOR INLINE FORMS (optional enhancement)
// ============================================================
(function() {
    document.querySelectorAll('form[data-ajax="true"]').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn ? submitBtn.innerHTML : '';

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = 'Saving...';
            }

            fetch(this.action, {
                method: 'POST',
                body: formData
            })
            .then(function(response) {
                return response.text();
            })
            .then(function(html) {
                // Show toast notification
                if (typeof showToast === 'function') {
                    // Look for flash message in the response
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const flashMsg = doc.querySelector('.flash-message');
                    if (flashMsg) {
                        const cls = flashMsg.className;
                        const type = cls.includes('flash-error') ? 'error' : 'success';
                        showToast(flashMsg.textContent.trim(), type);
                    } else {
                        showToast('Saved successfully!', 'success');
                    }
                }

                // Re-enable button
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }

                // Reload the page to reflect changes
                setTimeout(function() {
                    location.reload();
                }, 500);
            })
            .catch(function(err) {
                console.error('AJAX save error:', err);
                if (typeof showToast === 'function') {
                    showToast('Error saving: ' + err.message, 'error');
                }
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
            });
        });
    });
})();

