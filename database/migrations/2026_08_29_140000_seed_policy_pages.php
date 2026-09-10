<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the policy pages with the content from kbeautybliss.com.
 *
 * These are rows in the pages table, not hard-coded templates, so they can be
 * edited in the admin afterwards. Existing rows are left alone — if a page has
 * already been edited, re-running must not overwrite that.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->pages() as $page) {
            $exists = DB::table('pages')->where('slug', $page['slug'])->exists();

            if ($exists) {
                continue;
            }

            DB::table('pages')->insert($page + [
                'status' => 'published',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Deliberately empty: removing content a merchant may have edited would
        // be worse than leaving it.
    }

    private function pages(): array
    {
        return [
            [
                'slug' => 'privacy-policy',
                'title' => 'Privacy Policy',
                'content' => <<<'HTML'
<p>We are committed to protecting your privacy. We hope that you will take the time to read this privacy policy, which explains what information we collect from you and how we use that information. By using our website or by purchasing products or services from us, you agree to be bound by this privacy policy.</p>

<h3>What Information We Collect:</h3>
<p>We receive and store certain types of information when you interact with us. Our purpose is to allow the website to work correctly, to evaluate use of the website, and to support website analytics and marketing campaigns.</p>
<p>We may collect your technical information such as your IP address and MAC address to determine what region you are accessing the website from, in order to show you the version of the website that is in your language or covers that region.</p>

<h3>How We Use Your Information:</h3>
<p>We use your information to offer and provide our products and services and to support our core business functions. These include order or service fulfillment, internal business processes, marketing, authentication, loss and fraud prevention, public safety and legal functions, as listed below.</p>
<ul>
<li>To fulfill your orders for products and services and communicate with you about those orders.</li>
<li>To register and service your account.</li>
<li>To provide customer service.</li>
<li>To protect the security or integrity of our websites and mobile app and our business.</li>
<li>To help us improve and customise our product and service offerings, website, and advertising, including through data analytics. A traffic and user behavior auditing system operated by Google Analytics is used on our website. Please see the Google Analytics website for information about Google Analytics, the Google Analytics auditing system, and the Google Analytics&rsquo; privacy policy.</li>
</ul>

<h3>How We Protect Your Information:</h3>
<p>To protect against the loss, misuse, and alteration of the information under our control we have implemented security measures on our website.</p>
<p>When you place orders or access your account information, a secure server is employed. All information you input is encrypted by the secure server layer (SSL) before it is sent to us and all the customer data we collect is similarly protected against unauthorised access.</p>
<p>K-Beauty Bliss cannot be and is not responsible for unauthorised access to information by hackers or others who have obtained such access through illegal measures.</p>
<p>&ldquo;Phishing&rdquo; is a scam designed to steal your personal information. If you receive an e-mail that looks like it might be from K-Beauty Bliss asking for your personal information, do not respond. K-Beauty Bliss would not request your password, username, credit card information, or other personal information through e-mail.</p>

<h3>How Long We Keep Your Information:</h3>
<p>We will only store your information as long as necessary to fulfil the purposes for which the information is collected and processed or, where applicable law provides for longer storage and retention periods, for the storage and retention period required by law. After that your personal information will be deleted.</p>
<p>We may disclose your personal information if we are required by law to do so or if you violate our Terms of Service.</p>

<h3>Age of Consent:</h3>
<p>By using this site, you represent that you are at least the age of majority in your state or province of residence, or that you are the age of majority in your state or province of residence, and you have given us your consent to allow any of your minor dependents to use this site.</p>

<h3>Privacy Policy Changes:</h3>
<p>We reserve the right to modify this privacy policy at any time, so please review it frequently. Changes and clarifications will take effect immediately upon their posting on the website.</p>
<p>Questions about the Privacy Policy should be sent to us at <a href="mailto:info@kbeautybliss.com">info@kbeautybliss.com</a></p>
HTML,
            ],
            [
                'slug' => 'terms-and-conditions',
                'title' => 'Terms &amp; Conditions',
                'content' => <<<'HTML'
<p>Welcome to K-Beauty Bliss! Before you embark on your journey with us, please take a moment to review our terms and conditions:</p>
<ol>
<li><strong>Acceptance of Terms:</strong> By accessing or using our website, purchasing products, or engaging with our services, you agree to abide by these terms and conditions.</li>
<li><strong>Product Information:</strong> While we strive to provide accurate descriptions and images of our products, Colors, textures, and finishes may vary slightly from what is displayed on your screen.</li>
<li><strong>Orders and Payments:</strong> All orders placed through our website are subject to availability and acceptance. Prices are inclusive of taxes where applicable. Payment must be made in full at the time of international purchase ( COD available for United Arab Emirates only ) and we accept various payment methods, including credit/debit cards and PayPal.</li>
<li><strong>Shipping and Delivery:</strong> Please refer to our Shipping Policy for detailed information regarding shipping options, delivery times, and associated fees.</li>
<li><strong>Returns and Exchanges:</strong> Please review our return policy page.</li>
<li><strong>Privacy Policy:</strong> Your privacy is important to us. Please review our Privacy Policy to understand how we collect, use, and protect your personal information.</li>
<li><strong>Limitation of Liability:</strong> K-Beauty Bliss shall not be liable for any direct, indirect, incidental, special, or consequential damages arising out of or in connection with your use of our website, products, or services.</li>
<li><strong>Indemnification:</strong> You agree to indemnify and hold harmless K-Beauty Bliss, its affiliates, partners, and employees from any claims, losses, damages, liabilities, and expenses (including legal fees) arising out of or in connection with your breach of these terms and conditions or your violation of any law or third-party rights.</li>
<li><strong>Warrenty Claim:</strong> All the devices comes with certain warrenty period from the Brand. The warranty can be claimed only from the Brand&rsquo;s side. The warrenty conditions must need to match with the warranty claim information, issued from the Brand(s). All the cost while warrenty claim and shipping fee to be paid by the customer.</li>
<li><strong>Changes to Terms and Conditions:</strong> K-Beauty Bliss reserves the right to modify, amend, or update these terms and conditions at any time without prior notice. Any changes will be effective immediately upon posting on our website.</li>
</ol>
<p>By continuing to use our website or purchase our products after any such changes, you agree to be bound by the latest terms and conditions.</p>
<p>If you have any questions or concerns about these terms and conditions, please contact us at <a href="mailto:info@kbeautybliss.com">info@kbeautybliss.com</a></p>
<p>Thank you for choosing K-Beauty Bliss. We appreciate your support and look forward to serving you!</p>
HTML,
            ],
        ];
    }
};
