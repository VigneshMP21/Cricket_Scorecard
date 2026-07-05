<?php
require_once __DIR__ . '/includes/header.php';
?>

<style>
/* Privacy Policy Page Styles */
.privacy-page {
    background: linear-gradient(135deg, #f5f7fa 0%, #ffffff 100%);
    min-height: calc(100vh - 200px);
    padding-top: 1rem !important;
}

.privacy-page .btn-link {
    color: #1e3a8a;
    font-weight: 600;
    text-decoration: none;
    padding: 0.5rem 1rem;
    border-radius: 8px;
    background: rgba(30, 58, 138, 0.1);
    transition: all 0.3s ease;
}

.privacy-page .btn-link:hover {
    background: rgba(30, 58, 138, 0.2);
    transform: translateX(-3px);
}

.privacy-page h1 {
    color: #1e3a8a;
    font-weight: 800;
    font-size: 2rem;
    border-bottom: 3px solid #ffd700;
    padding-bottom: 0.5rem;
    display: inline-block;
}

.privacy-page h2 {
    color: #2e8b57;
    font-weight: 700;
    font-size: 1.25rem;
    margin-top: 1.5rem;
    margin-bottom: 0.75rem;
    background: linear-gradient(135deg, #2e8b57 0%, #1e3a8a 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.privacy-page p {
    color: #4b5563;
    line-height: 1.8;
    font-size: 0.95rem;
}

.privacy-page ul {
    padding-left: 1.25rem;
}

.privacy-page li {
    color: #4b5563;
    margin-bottom: 0.5rem;
    line-height: 1.6;
}

.privacy-page li strong {
    color: #1e3a8a;
}

.privacy-page a {
    color: #2563eb;
    text-decoration: none;
    font-weight: 500;
    transition: all 0.2s ease;
}

.privacy-page a:hover {
    color: #1d4ed8;
    text-decoration: underline;
}

.privacy-page .text-muted {
    color: #6b7280 !important;
    font-size: 0.85rem;
    font-style: italic;
}

.privacy-page section {
    background: white;
    padding: 1.5rem;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    margin-bottom: 1rem;
    transition: all 0.3s ease;
}

.privacy-page section:hover {
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
    transform: translateY(-2px);
}

@media (max-width: 768px) {
    .privacy-page {
        padding-top: 0.5rem !important;
        padding-left: 0.75rem !important;
        padding-right: 0.75rem !important;
    }
    
    .privacy-page h1 {
        font-size: 1.5rem;
    }
    
    .privacy-page h2 {
        font-size: 1.1rem;
    }
    
    .privacy-page section {
        padding: 1rem;
    }
}
</style>

<div class="container py-3 py-md-4 privacy-page">
    <a href="javascript:void(0)" id="privacy-back-link" class="btn btn-link mb-2 mb-md-3">&larr; Back</a>
    <h1 class="mb-3 mb-md-4">Privacy Policy</h1>

    <p class="text-muted">Last updated: <?php echo date('F j, Y'); ?>.</p>

    <section class="mt-3 mt-md-4">
        <p>At CPT League, we respect your privacy and are committed to protecting your personal information. This policy explains what we collect, why we collect it, how we use it, and the choices you have. This site is also used inside our Android app via WebView.</p>
    </section>

    <section class="mt-3">
        <h2>1. Information We Collect</h2>
        <ul>
            <li><strong>Account & profile data:</strong> name, email, profile images you upload, team information.</li>
            <li><strong>Communications:</strong> messages you send to us (support, feedback).</li>
            <li><strong>Usage data:</strong> pages you visit, actions you take, device and browser information, and IP address.</li>
            <li><strong>Technical data:</strong> logs, error reports, and analytics data from services we use.</li>
        </ul>
    </section>

    <section class="mt-3">
        <h2>2. How We Use Your Information</h2>
        <ul>
            <li>To operate, maintain, and improve our services and app.</li>
            <li>To respond to support requests and communicate important notices.</li>
            <li>To detect and prevent fraud, abuse, and security incidents.</li>
            <li>To analyze usage trends and enhance user experience.</li>
        </ul>
    </section>

    <section class="mt-3">
        <h2>3. Legal Basis</h2>
        <p>Where applicable, we rely on your consent, our contractual need to provide the service, and our legitimate interests (such as improving and securing the service).</p>
    </section>

    <section class="mt-3">
        <h2>4. Data Retention</h2>
        <p>We retain personal information only as long as necessary to provide services, comply with legal obligations, resolve disputes, and enforce our agreements.</p>
    </section>

    <section class="mt-3">
        <h2>5. Sharing & Third-Party Services</h2>
        <p>We may share data with trusted third parties who help provide our services (for hosting, analytics, images, and push notifications). These providers act under our instruction and have their own privacy policies. Examples include OneSignal for notifications and Cloudinary for media storage.</p>
    </section>

    <section class="mt-3">
        <h2>6. Cookies & Tracking</h2>
        <p>We use cookies and similar technologies for functionality, preferences, and analytics. You can control cookie settings in your browser. Disabling cookies may affect how the site works.</p>
    </section>

    <section class="mt-3">
        <h2>7. Push Notifications (Mobile)</h2>
        <p>If you enable push notifications in the Android app, we may use a third-party service (for example OneSignal) to deliver notifications. You can opt out via app settings or uninstall the app.</p>
    </section>

    <section class="mt-3">
        <h2>8. Data Security</h2>
        <p>We implement reasonable technical and organizational measures to protect personal data. However, no method of transmission or storage is completely secure. If you suspect a security issue, contact us immediately.</p>
    </section>

    <section class="mt-3">
        <h2>9. Your Rights</h2>
        <p>Depending on your jurisdiction, you may have rights to access, correct, delete, or restrict processing of your personal data. To exercise these rights, contact us at the address below.</p>
    </section>

    <section class="mt-3">
        <h2>10. Children's Privacy</h2>
        <p>Our service is not directed to children under 13. We do not knowingly collect personal data from children under 13. If you believe we have collected such data, please contact us to request deletion.</p>
    </section>

    <section class="mt-3">
        <h2>11. International Transfers</h2>
        <p>Your information may be stored or processed in countries outside your country of residence. We take steps to ensure appropriate safeguards are in place.</p>
    </section>

    <section class="mt-3">
        <h2>12. Contact Us</h2>
        <p>For questions, requests, or complaints, contact: <a href="mailto:mpvignesh2107@gmail.com">mpvignesh2107@gmail.com</a></p>
    </section>

    <section class="mt-3">
        <h2>13. Changes to This Policy</h2>
        <p>We may update this policy. We will post the revised version here with a new date. Continued use of the service after changes constitutes acceptance of the updated policy.</p>
    </section>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var backLink = document.getElementById('privacy-back-link');
    if (backLink) {
        backLink.addEventListener('click', function(e) {
            e.preventDefault();
            if (document.referrer && document.referrer !== window.location.href) {
                window.history.back();
            } else {
                // Fallback: go to homepage or previous accessible page
                window.location.href = 'index.php';
            }
        });
    }
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>