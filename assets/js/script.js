/*
 * Gully Cricket Scorecard - Main JavaScript File
 * Author: DeepSeek AI Assistant
 * Date: October 2024
 * Description: Contains all interactive functionality for the application
 */

/* ==========================================================================
   1. INITIALIZATION & DOCUMENT READY
   ========================================================================== */

document.addEventListener('DOMContentLoaded', function() {
    console.log('Gully Cricket Scorecard Application Loaded');
    
    // Initialize all modules
    initFormValidations();
    initForgotPassword();
    initModals();
    initAnimations();
    initPasswordVisibility();
    initDashboardFeatures();
    
    // Check for messages in URL
    checkUrlMessages();
});

/* ==========================================================================
   2. FORM VALIDATION FUNCTIONS
   ========================================================================== */

/**
 * Initialize form validation for all forms
 */
function initFormValidations() {
    console.log('Initializing form validations...');
    
    // Registration Form Validation
    const registerForm = document.getElementById('registerForm');
    if (registerForm) {
        registerForm.addEventListener('submit', validateRegistrationForm);
        console.log('Registration form validation initialized');
    }
    
    // Login Form Validation
    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        loginForm.addEventListener('submit', validateLoginForm);
        console.log('Login form validation initialized');
    }
    
    // Forgot Password Form Validation
    const forgotPasswordForm = document.getElementById('forgotPasswordForm');
    if (forgotPasswordForm) {
        forgotPasswordForm.addEventListener('submit', validateForgotPasswordForm);
        console.log('Forgot password form validation initialized');
    }
}

/**
 * Validate registration form before submission
 * @param {Event} e - Form submit event
 */
function validateRegistrationForm(e) {
    console.log('Validating registration form...');
    
    const form = e.target;
    const name = form.querySelector('#name').value.trim();
    const email = form.querySelector('#email').value.trim();
    const password = form.querySelector('#password').value;
    const confirmPassword = form.querySelector('#confirm_password').value;
    
    let isValid = true;
    let errorMessages = [];
    
    // Clear previous error messages
    clearValidationErrors(form);
    
    // Name validation
    if (!name) {
        markFieldInvalid(form.querySelector('#name'), 'Name is required');
        isValid = false;
        errorMessages.push('Name is required');
    } else if (name.length < 2) {
        markFieldInvalid(form.querySelector('#name'), 'Name must be at least 2 characters');
        isValid = false;
        errorMessages.push('Name must be at least 2 characters');
    }
    
    // Email validation
    if (!email) {
        markFieldInvalid(form.querySelector('#email'), 'Email is required');
        isValid = false;
        errorMessages.push('Email is required');
    } else if (!isValidEmail(email)) {
        markFieldInvalid(form.querySelector('#email'), 'Please enter a valid email address');
        isValid = false;
        errorMessages.push('Invalid email format');
    }
    
    // Password validation
    if (!password) {
        markFieldInvalid(form.querySelector('#password'), 'Password is required');
        isValid = false;
        errorMessages.push('Password is required');
    } else if (password.length < 6) {
        markFieldInvalid(form.querySelector('#password'), 'Password must be at least 6 characters');
        isValid = false;
        errorMessages.push('Password must be at least 6 characters');
    }
    
    // Confirm password validation
    if (!confirmPassword) {
        markFieldInvalid(form.querySelector('#confirm_password'), 'Please confirm your password');
        isValid = false;
        errorMessages.push('Please confirm your password');
    } else if (password !== confirmPassword) {
        markFieldInvalid(form.querySelector('#confirm_password'), 'Passwords do not match');
        isValid = false;
        errorMessages.push('Passwords do not match');
    }
    
    // If not valid, prevent form submission and show errors
    if (!isValid) {
        e.preventDefault();
        showFormErrors(form, errorMessages);
        console.log('Registration form validation failed:', errorMessages);
        return false;
    }
    
    console.log('Registration form validation passed');
    return true;
}

/**
 * Validate login form before submission
 * @param {Event} e - Form submit event
 */
function validateLoginForm(e) {
    console.log('Validating login form...');
    
    const form = e.target;
    const email = form.querySelector('#email').value.trim();
    const password = form.querySelector('#password').value;
    
    let isValid = true;
    let errorMessages = [];
    
    // Clear previous error messages
    clearValidationErrors(form);
    
    // Email validation
    if (!email) {
        markFieldInvalid(form.querySelector('#email'), 'Email is required');
        isValid = false;
        errorMessages.push('Email is required');
    } else if (!isValidEmail(email)) {
        markFieldInvalid(form.querySelector('#email'), 'Please enter a valid email address');
        isValid = false;
        errorMessages.push('Invalid email format');
    }
    
    // Password validation
    if (!password) {
        markFieldInvalid(form.querySelector('#password'), 'Password is required');
        isValid = false;
        errorMessages.push('Password is required');
    }
    
    // If not valid, prevent form submission and show errors
    if (!isValid) {
        e.preventDefault();
        showFormErrors(form, errorMessages);
        console.log('Login form validation failed:', errorMessages);
        return false;
    }
    
    console.log('Login form validation passed');
    return true;
}

/**
 * Validate forgot password form
 * @param {Event} e - Form submit event
 */
function validateForgotPasswordForm(e) {
    console.log('Validating forgot password form...');
    
    const form = e.target;
    const email = form.querySelector('#email').value.trim();
    
    let isValid = true;
    let errorMessages = [];
    
    // Clear previous error messages
    clearValidationErrors(form);
    
    // Email validation
    if (!email) {
        markFieldInvalid(form.querySelector('#email'), 'Email is required');
        isValid = false;
        errorMessages.push('Email is required');
    } else if (!isValidEmail(email)) {
        markFieldInvalid(form.querySelector('#email'), 'Please enter a valid email address');
        isValid = false;
        errorMessages.push('Invalid email format');
    }
    
    // If not valid, prevent form submission and show errors
    if (!isValid) {
        e.preventDefault();
        showFormErrors(form, errorMessages);
        console.log('Forgot password form validation failed:', errorMessages);
        return false;
    }
    
    console.log('Forgot password form validation passed');
    return true;
}

// Add this to your existing script.js or create new

/**
 * Forgot password form handling
 */
document.getElementById('forgotPasswordForm')?.addEventListener('submit', function(e) {
    const emailField = document.getElementById('email');
    const submitBtn = this.querySelector('button[type="submit"]');
    const loadingSpinner = document.getElementById('loadingSpinner');
    
    // Validate email
    const email = emailField.value.trim();
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    
    if (!email) {
        e.preventDefault();
        showToast('Please enter your email address', 'error');
        emailField.focus();
        return false;
    }
    
    if (!emailRegex.test(email)) {
        e.preventDefault();
        showToast('Please enter a valid email address', 'error');
        emailField.focus();
        return false;
    }
    
    // Show loading state
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending...';
    loadingSpinner.classList.remove('d-none');
    
    // Form will submit normally, but we'll handle the response
    return true;
});

/**
 * Handle email field real-time validation
 */
document.getElementById('email')?.addEventListener('blur', function() {
    const email = this.value.trim();
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    
    if (email && !emailRegex.test(email)) {
        this.classList.add('is-invalid');
        const errorDiv = this.parentNode.querySelector('.invalid-feedback') || document.createElement('div');
        errorDiv.className = 'invalid-feedback';
        errorDiv.textContent = 'Please enter a valid email address';
        if (!this.parentNode.querySelector('.invalid-feedback')) {
            this.parentNode.appendChild(errorDiv);
        }
    } else {
        this.classList.remove('is-invalid');
        this.classList.add('is-valid');
        const errorDiv = this.parentNode.querySelector('.invalid-feedback');
        if (errorDiv) errorDiv.remove();
    }
});

/* ==========================================================================
   3. FORM HELPER FUNCTIONS
   ========================================================================== */

/**
 * Mark a form field as invalid with error message
 * @param {HTMLElement} field - Input field element
 * @param {string} message - Error message to display
 */
function markFieldInvalid(field, message) {
    if (!field) return;
    
    field.classList.remove('is-valid');
    field.classList.add('is-invalid');
    
    // Create or update error message element
    let errorElement = field.parentNode.querySelector('.validation-error');
    if (!errorElement) {
        errorElement = document.createElement('div');
        errorElement.className = 'validation-error';
        field.parentNode.appendChild(errorElement);
    }
    errorElement.textContent = message;
}

/**
 * Mark a form field as valid
 * @param {HTMLElement} field - Input field element
 */
function markFieldValid(field) {
    if (!field) return;
    
    field.classList.remove('is-invalid');
    field.classList.add('is-valid');
    
    // Remove error message if exists
    const errorElement = field.parentNode.querySelector('.validation-error');
    if (errorElement) {
        errorElement.remove();
    }
}

/**
 * Clear all validation errors from a form
 * @param {HTMLElement} form - Form element
 */
function clearValidationErrors(form) {
    const fields = form.querySelectorAll('.form-control');
    fields.forEach(field => {
        field.classList.remove('is-invalid', 'is-valid');
        
        const errorElement = field.parentNode.querySelector('.validation-error');
        if (errorElement) {
            errorElement.remove();
        }
    });
}

/**
 * Show form-level error messages
 * @param {HTMLElement} form - Form element
 * @param {Array} errors - Array of error messages
 */
function showFormErrors(form, errors) {
    // Remove existing alert
    const existingAlert = form.querySelector('.alert-danger');
    if (existingAlert) {
        existingAlert.remove();
    }
    
    if (errors.length > 0) {
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-danger alert-dismissible fade show';
        alertDiv.role = 'alert';
        
        let html = '<strong>Please fix the following errors:</strong><ul class="mb-0">';
        errors.forEach(error => {
            html += `<li>${error}</li>`;
        });
        html += '</ul>';
        html += '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        
        alertDiv.innerHTML = html;
        form.insertBefore(alertDiv, form.firstChild);
    }
}

/**
 * Validate email format
 * @param {string} email - Email address to validate
 * @returns {boolean} - True if email is valid
 */
function isValidEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
}

/* ==========================================================================
   4. FORGOT PASSWORD FUNCTIONALITY
   ========================================================================== */

/**
 * Initialize forgot password functionality
 */
function initForgotPassword() {
    console.log('Initializing forgot password functionality...');
    
    // Handle forgot password modal form
    const modalForm = document.querySelector('#forgotPasswordModal form');
    if (modalForm) {
        modalForm.addEventListener('submit', handleForgotPasswordSubmit);
    }
    
    // Add real-time email validation
    const emailField = document.querySelector('#resetEmail');
    if (emailField) {
        emailField.addEventListener('blur', function() {
            validateEmailField(this);
        });
    }
}

/**
 * Handle forgot password form submission
 * @param {Event} e - Form submit event
 */
function handleForgotPasswordSubmit(e) {
    e.preventDefault();
    console.log('Handling forgot password submission...');
    
    const email = document.querySelector('#resetEmail').value.trim();
    
    if (!email || !isValidEmail(email)) {
        showToast('Please enter a valid email address', 'error');
        return;
    }
    
    // Show loading state
    const submitBtn = e.target.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending...';
    submitBtn.disabled = true;
    
    // Simulate API call (replace with actual API call)
    setTimeout(() => {
        // Reset button state
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
        
        // Show success message
        showToast('Password reset link has been sent to your email', 'success');
        
        // Close modal
        const modal = bootstrap.Modal.getInstance(document.getElementById('forgotPasswordModal'));
        modal.hide();
        
        console.log('Forgot password email sent to:', email);
    }, 2000);
}

/**
 * Validate email field in real-time
 * @param {HTMLElement} field - Email input field
 */
function validateEmailField(field) {
    const email = field.value.trim();
    
    if (!email) {
        markFieldInvalid(field, 'Email is required');
        return false;
    }
    
    if (!isValidEmail(email)) {
        markFieldInvalid(field, 'Please enter a valid email address');
        return false;
    }
    
    markFieldValid(field);
    return true;
}

/* ==========================================================================
   5. MODAL MANAGEMENT
   ========================================================================== */

/**
 * Initialize modal functionality
 */
function initModals() {
    console.log('Initializing modals...');
    
    // Add animation to modals
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        modal.addEventListener('show.bs.modal', function() {
            this.style.animation = 'fadeInUp 0.3s ease-out';
        });
        
        modal.addEventListener('hidden.bs.modal', function() {
            // Clear form fields when modal is closed
            const forms = this.querySelectorAll('form');
            forms.forEach(form => {
                form.reset();
                clearValidationErrors(form);
            });
        });
    });
}

/* ==========================================================================
   6. ANIMATIONS & EFFECTS
   ========================================================================== */

/**
 * Initialize animations and effects
 */
function initAnimations() {
    console.log('Initializing animations...');
    
    // Add hover effects to cards
    const cards = document.querySelectorAll('.card-hover');
    cards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-10px)';
            this.style.boxShadow = '0 15px 30px rgba(0, 0, 0, 0.1)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
            this.style.boxShadow = '0 5px 15px rgba(0, 0, 0, 0.05)';
        });
    });
    
    // Add loading animations to buttons
    const buttons = document.querySelectorAll('.btn');
    buttons.forEach(button => {
        button.addEventListener('click', function(e) {
            if (this.classList.contains('btn-loading')) {
                e.preventDefault();
                return;
            }
            
            // Add loading state for buttons with loading class
            if (this.classList.contains('loading')) {
                this.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Loading...';
                this.disabled = true;
            }
        });
    });
}

/* ==========================================================================
   7. PASSWORD VISIBILITY TOGGLE
   ========================================================================== */

/**
 * Initialize password visibility toggle
 */
function initPasswordVisibility() {
    console.log('Initializing password visibility toggle...');
    
    // Create toggle buttons for password fields
    const passwordFields = document.querySelectorAll('input[type="password"]');
    passwordFields.forEach(field => {
        // Skip if this field already has a custom toggle
        if (field.parentNode.querySelector('.password-toggle')) return;
        // Create toggle button
        const toggleBtn = document.createElement('button');
        toggleBtn.type = 'button';
        toggleBtn.className = 'btn btn-sm btn-outline-secondary password-toggle';
        toggleBtn.style.position = 'absolute';
        toggleBtn.style.right = '10px';
        toggleBtn.style.top = '50%';
        toggleBtn.style.transform = 'translateY(-50%)';
        toggleBtn.innerHTML = '<i class="fas fa-eye"></i>';
        
        // Add to parent if it has relative positioning
        const parent = field.parentNode;
        if (getComputedStyle(parent).position === 'relative') {
            parent.style.position = 'relative';
            parent.appendChild(toggleBtn);
        } else {
            // Create wrapper
            const wrapper = document.createElement('div');
            wrapper.style.position = 'relative';
            parent.replaceChild(wrapper, field);
            wrapper.appendChild(field);
            wrapper.appendChild(toggleBtn);
        }
        
        // Add click event
        toggleBtn.addEventListener('click', function() {
            const icon = this.querySelector('i');
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
    });
}

/* ==========================================================================
   8. DASHBOARD FUNCTIONALITY
   ========================================================================== */

/**
 * Initialize dashboard features
 */
function initDashboardFeatures() {
    console.log('Initializing dashboard features...');
    
    // Update live match scores (simulated)
    updateLiveScores();
    
    // Initialize dashboard widgets
    initDashboardWidgets();
    
    // Add real-time clock to dashboard
    updateDashboardClock();
    setInterval(updateDashboardClock, 60000); // Update every minute
}

/**
 * Update live cricket scores (simulated data)
 */
function updateLiveScores() {
    const liveMatchCard = document.querySelector('.live-match-card');
    if (!liveMatchCard) return;
    
    // Simulate score updates every 30 seconds
    setInterval(() => {
        const scores = liveMatchCard.querySelectorAll('.team-score h3');
        const overInfo = liveMatchCard.querySelector('.current-over p');
        
        if (scores.length >= 2 && overInfo) {
            // Simulate random score changes
            const currentScore1 = scores[0].textContent.split('/')[0];
            const currentScore2 = scores[1].textContent.split('/')[0];
            
            const newScore1 = parseInt(currentScore1) + Math.floor(Math.random() * 3);
            const newWickets1 = Math.min(10, Math.floor(Math.random() * 2));
            
            const newScore2 = parseInt(currentScore2) + Math.floor(Math.random() * 4);
            const newWickets2 = Math.min(10, Math.floor(Math.random() * 3));
            
            scores[0].textContent = `${newScore1}/${newWickets1}`;
            scores[1].textContent = `${newScore2}/${newWickets2}`;
            
            // Update over information
            const outcomes = ['Single', 'Dot Ball', 'Four!', 'Six!', 'Wicket!'];
            const randomOutcome = outcomes[Math.floor(Math.random() * outcomes.length)];
            overInfo.textContent = `Last Ball: ${randomOutcome}`;
            
            console.log('Live scores updated:', newScore1, newScore2);
        }
    }, 30000); // Update every 30 seconds
}

/**
 * Initialize dashboard widgets
 */
function initDashboardWidgets() {
    // Add click handlers to dashboard cards
    const dashboardCards = document.querySelectorAll('.dashboard-card');
    dashboardCards.forEach(card => {
        card.addEventListener('click', function() {
            this.classList.add('clicked');
            setTimeout(() => {
                this.classList.remove('clicked');
            }, 300);
        });
    });
}

/**
 * Update dashboard clock
 */
function updateDashboardClock() {
    const clockElements = document.querySelectorAll('.dashboard-clock');
    if (clockElements.length > 0) {
        const now = new Date();
        const timeString = now.toLocaleTimeString([], { 
            hour: '2-digit', 
            minute: '2-digit' 
        });
        const dateString = now.toLocaleDateString('en-US', { 
            weekday: 'long', 
            year: 'numeric', 
            month: 'long', 
            day: 'numeric' 
        });
        
        clockElements.forEach(element => {
            element.innerHTML = `
                <i class="fas fa-clock me-2"></i>
                ${timeString} | ${dateString}
            `;
        });
    }
}

/* ==========================================================================
   9. NOTIFICATION & TOAST SYSTEM
   ========================================================================== */

/**
 * Show toast notification
 * @param {string} message - Message to display
 * @param {string} type - Type of toast (success, error, info, warning)
 */
function showToast(message, type = 'info') {
    console.log(`Showing ${type} toast:`, message);
    
    // Create toast container if it doesn't exist
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            max-width: 350px;
        `;
        document.body.appendChild(container);
    }
    
    // Create toast element
    const toast = document.createElement('div');
    toast.className = `toast show align-items-center text-bg-${type} border-0`;
    toast.role = 'alert';
    toast.style.marginBottom = '10px';
    
    // Toast content
    const icons = {
        'success': 'fas fa-check-circle',
        'error': 'fas fa-times-circle',
        'warning': 'fas fa-exclamation-triangle',
        'info': 'fas fa-info-circle'
    };
    
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">
                <i class="${icons[type] || icons.info} me-2"></i>
                ${message}
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    `;
    
    container.appendChild(toast);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => {
            toast.remove();
        }, 300);
    }, 5000);
    
    // Add click handler to close button
    toast.querySelector('.btn-close').addEventListener('click', function() {
        toast.classList.remove('show');
        setTimeout(() => {
            toast.remove();
        }, 300);
    });
}

/* ==========================================================================
   10. URL MESSAGE HANDLING
   ========================================================================== */

/**
 * Check for messages in URL parameters
 */
function checkUrlMessages() {
    const urlParams = new URLSearchParams(window.location.search);
    const message = urlParams.get('message');
    const type = urlParams.get('type');
    
    if (message) {
        showToast(decodeURIComponent(message), type || 'info');
        
        // Clean URL (remove message parameters)
        const cleanUrl = window.location.pathname;
        window.history.replaceState({}, document.title, cleanUrl);
    }
}

/* ==========================================================================
   11. RESPONSIVE MENU HANDLING
   ========================================================================== */

/**
 * Handle mobile menu interactions
 */
function initMobileMenu() {
    const navbarToggler = document.querySelector('.navbar-toggler');
    if (navbarToggler) {
        navbarToggler.addEventListener('click', function() {
            const navbarCollapse = document.querySelector('#navbarNav');
            navbarCollapse.classList.toggle('show');
        });
    }
    
    // Close mobile menu when clicking outside
    document.addEventListener('click', function(e) {
        const navbarCollapse = document.querySelector('#navbarNav');
        const navbarToggler = document.querySelector('.navbar-toggler');
        
        // Add null check for navbarCollapse to prevent TypeError
        if (navbarCollapse && navbarCollapse.classList.contains('show') && 
            !navbarCollapse.contains(e.target) && 
            !navbarToggler.contains(e.target)) {
            navbarCollapse.classList.remove('show');
        }
    });
}

/* ==========================================================================
   12. FORM AUTO-SAVE FUNCTIONALITY
   ========================================================================== */

/**
 * Initialize form auto-save functionality
 */
function initAutoSave() {
    const forms = document.querySelectorAll('form[data-autosave]');
    forms.forEach(form => {
        const inputs = form.querySelectorAll('input, textarea, select');
        
        inputs.forEach(input => {
            // Load saved data
            const savedValue = localStorage.getItem(`autosave_${input.name}`);
            if (savedValue) {
                input.value = savedValue;
            }
            
            // Save on input
            input.addEventListener('input', function() {
                localStorage.setItem(`autosave_${this.name}`, this.value);
            });
        });
        
        // Clear saved data on form submit
        form.addEventListener('submit', function() {
            inputs.forEach(input => {
                localStorage.removeItem(`autosave_${input.name}`);
            });
        });
    });
}

/* ==========================================================================
   13. PERFORMANCE OPTIMIZATION
   ========================================================================== */

/**
 * Lazy load images
 */
function initLazyLoading() {
    const images = document.querySelectorAll('img[data-src]');
    
    const imageObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                img.src = img.dataset.src;
                img.removeAttribute('data-src');
                observer.unobserve(img);
            }
        });
    });
    
    images.forEach(img => imageObserver.observe(img));
}

/* ==========================================================================
   14. ERROR HANDLING
   ========================================================================== */

/**
 * Global error handler
 */
window.addEventListener('error', function(e) {
    console.error('Global error caught:', e.error);
    
    if (!e.error || !e.filename) return;
    
    const isIgnorableError = e.filename.includes('favicon') || 
                             e.filename.includes('default-player') ||
                             e.error.name === 'TypeError' ||
                             e.error.message.includes('null');
    
    if (!isIgnorableError) {
        showToast('An unexpected error occurred. Please try again.', 'error');
    }
    
    if (typeof ga !== 'undefined') {
        ga('send', 'exception', {
            exDescription: e.error.toString(),
            exFatal: false
        });
    }
});

/* ==========================================================================
   15. OFFLINE DETECTION
   ========================================================================== */

/**
 * Handle online/offline status
 */
function initOfflineDetection() {
    window.addEventListener('online', function() {
        showToast('You are back online!', 'success');
    });
    
    window.addEventListener('offline', function() {
        showToast('You are offline. Some features may not work.', 'warning');
    });
}

/* ==========================================================================
   16. INITIALIZATION COMPLETE
   ========================================================================== */

// Initialize remaining features
initMobileMenu();
initAutoSave();
initLazyLoading();
initOfflineDetection();

console.log('Gully Cricket Scorecard JavaScript initialization complete!');

/* ==========================================================================
   END OF JAVASCRIPT FILE
   ========================================================================== */