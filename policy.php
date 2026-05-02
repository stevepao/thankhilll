<?php
/**
 * policy.php — Public privacy policy (canonical URL: /policy).
 */
declare(strict_types=1);

$pageTitle = 'Privacy Policy';
$currentNav = '';
$showNav = false;

require_once __DIR__ . '/header.php';
?>

            <article class="policy-doc" lang="en">
                <p class="policy-doc__meta"><strong>Last updated:</strong> May 1, 2026</p>

                <p>
                    This Privacy Policy describes how <strong>Hillwork, LLC</strong> (“<strong>we</strong>,” “<strong>us</strong>,” or “<strong>our</strong>”)
                    collects, uses, stores, and shares information when you use our gratitude journaling web application (the “<strong>Service</strong>”),
                    available at <a href="https://thank.hillwork.net">https://thank.hillwork.net</a>.
                </p>

                <p>
                    <strong>Contact:</strong> <a href="mailto:support@hillwork.com">support@hillwork.com</a><br>
                    <strong>Mailing address:</strong> Hillwork, LLC, 5441 S Macadam Ave Ste R, Portland, OR 97239<br>
                    <strong>Official policy URL:</strong> <a href="https://thank.hillwork.net/policy">https://thank.hillwork.net/policy</a>
                </p>

                <p>
                    Please read this policy carefully. By using the Service, you agree to this Privacy Policy.
                    If you do not agree, do not use the Service.
                </p>

                <hr class="policy-doc__rule">

                <h2 class="policy-doc__h2">1. Who this applies to</h2>
                <p>
                    This policy applies to visitors and registered users of the Service.
                    We operate the Service to provide a personal gratitude journaling and optional social/group experience for adults and general audiences.
                </p>

                <h2 class="policy-doc__h2">2. Information we collect</h2>

                <h3 class="policy-doc__h3">2.1 Information you provide</h3>
                <ul>
                    <li><strong>Journal content and related data.</strong> Text you enter as notes or gratitude entries, optional thoughts or comments on entries, reactions, and similar content you submit through the Service.</li>
                    <li><strong>Profile preferences.</strong> Information you may provide in your account or settings (for example, display name, timezone, or notification preferences where available).</li>
                    <li><strong>Email address (email sign-in).</strong> If you choose “Sign in with email,” you provide an email address so we can send you a one-time sign-in code. We store a normalized form of that email to maintain your account and sign-in identity.</li>
                    <li><strong>Group and collaboration data.</strong> If you use shared groups or invitations, we process identifiers and content needed to operate those features (for example, group membership, invitation tokens or requests, and visibility of notes you choose to share).</li>
                </ul>

                <h3 class="policy-doc__h3">2.2 Information collected automatically</h3>
                <ul>
                    <li><strong>Cookies and similar technologies.</strong> We use cookies (and related server-side mechanisms) to maintain your session after sign-in, protect against session fixation, enforce idle timeouts, and optionally support a bounded “stay signed in” experience using an <strong>HttpOnly</strong> cookie tied to a server-stored token (not stored in browser <code>localStorage</code> for authentication). Session-related data may include a server-side record of recent activity and browser <strong>User-Agent</strong> string for session integrity checks.</li>
                    <li><strong>Technical data.</strong> Standard server and application logs may include IP address, request timestamps, URLs, and error information—used for security, debugging, and reliability.</li>
                    <li><strong>Optional media.</strong> If you attach photos or files where the Service supports it, those files are stored according to our configuration (see Section 5).</li>
                </ul>

                <h3 class="policy-doc__h3">2.3 Web Push notifications (if you enable them)</h3>
                <p>
                    If you opt in to browser push notifications, we store <strong>Web Push</strong> subscription data needed to deliver notifications to your device (for example, push endpoint URL and related cryptographic keys).
                    Push infrastructure may involve <strong>your browser vendor’s push service</strong> (for example, Mozilla, Google, or Apple, depending on your browser and OS).
                    We use this only to send notifications you have opted into (such as reminders or reply alerts, where enabled).
                </p>

                <h3 class="policy-doc__h3">2.4 Sign in with Google (limited Google user data)</h3>
                <p>
                    If you choose <strong>Sign in with Google</strong>, we use <strong>Google’s OAuth 2.0 / OpenID Connect</strong> sign-in service.
                    Google may show you what information is shared. Based on our current implementation, we request permissions consistent with these purposes:
                </p>
                <div class="policy-doc__table-wrap">
                    <table class="policy-doc__table">
                        <thead>
                            <tr>
                                <th scope="col">Data from Google (typical)</th>
                                <th scope="col">How we use it</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Subject identifier (<code>sub</code>)</strong></td>
                                <td>Stable identifier linking your Google account to your app account in our database.</td>
                            </tr>
                            <tr>
                                <td><strong>Email address</strong></td>
                                <td>Account creation, sign-in, communication with your account, and normalized login email on our side where applicable.</td>
                            </tr>
                            <tr>
                                <td><strong>Name / profile name</strong></td>
                                <td>Default <strong>display name</strong> for your account if you do not set another.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p>
                    We request Google sign-in with <strong><code>openid</code></strong>, <strong><code>email</code></strong>, and <strong><code>profile</code></strong>-related scopes appropriate to standard “Sign in with Google.”
                    We do <strong>not</strong> use Google sign-in to access your Gmail messages, Google Drive files, Calendar, Contacts, or other Google APIs beyond what is needed for authentication and basic profile information described above.
                </p>
                <p>
                    For users who sign in with Google, we may store <strong>Google’s OAuth refresh token</strong> (or equivalent) <strong>only</strong> where needed to <strong>revoke</strong> our access when you delete your account, consistent with our account-deletion process.
                    We do <strong>not</strong> use that token to read your Google data for unrelated purposes.
                </p>

                <h2 class="policy-doc__h2">3. How we use information</h2>
                <p>We use the information above to:</p>
                <ul>
                    <li>Provide, operate, and improve the Service (save and display your journal, groups, and related features).</li>
                    <li>Authenticate you and keep your account secure (sessions, optional bounded reauthentication, fraud and abuse mitigation).</li>
                    <li>Send <strong>transactional emails</strong> you request (for example, email sign-in codes) using our configured mail delivery.</li>
                    <li>Deliver <strong>optional push notifications</strong> you enable.</li>
                    <li>Comply with law and enforce our terms.</li>
                </ul>
                <p>
                    We do <strong>not</strong> sell your personal information.
                    We do <strong>not</strong> use Google user data for <strong>surveillance or tracking</strong> beyond what is described here, and we do <strong>not</strong> use Google sign-in data to serve <strong>third-party personalized ads</strong> in the Service.
                </p>

                <h2 class="policy-doc__h2">4. Google API Services User Data Policy (Limited Use)</h2>
                <p>
                    If we receive information from Google APIs, our use of that information will comply with the
                    <strong>Google API Services User Data Policy</strong>, including the <strong>Limited Use</strong> requirements:
                    we use Google user data only to provide or improve <strong>user-facing features</strong> of the Service that are prominent in our offering;
                    we do not use such data for advertising purposes as restricted by that policy;
                    and we do not allow humans to read Google user data except as permitted by the policy (for example, with your consent, for security purposes, or as required by law).
                </p>

                <h2 class="policy-doc__h2">5. Where data is stored and subprocessors</h2>
                <ul>
                    <li><strong>Our servers and database.</strong> Account data, journal content, authentication identifiers, OTP challenges (stored in hashed form), optional push subscription records, and related tables are stored on <strong>hosting infrastructure provided by IONOS SE</strong> (or its affiliates), which we use as our primary hosting vendor. Unless we tell you otherwise in this policy or in-product notices, data is processed <strong>in the United States</strong>. Hillwork, LLC is organized under the laws of the <strong>State of Oregon</strong>; our mailing address is at the <strong>top of this policy</strong>.</li>
                    <li><strong>Email delivery.</strong> Sign-in codes and other emails are sent using <strong>SMTP</strong> through settings <strong>we</strong> configure (which may include mail services bundled with our hosting or a separate email provider). Those providers process recipient addresses and message content only as needed to deliver mail.</li>
                    <li><strong>Push services.</strong> Push notifications may be routed through your <strong>browser’s push network</strong> (operated by third parties such as Mozilla, Google, or Apple). Those services receive technical data needed to deliver the notification; they act as infrastructure providers, not as controllers of your journal content.</li>
                </ul>
                <p>We may use additional subprocessors for reliability or security; material changes to how we share data will be reflected in updates to this policy.</p>

                <h2 class="policy-doc__h2">6. Retention</h2>
                <ul>
                    <li><strong>Account data</strong> is kept while your account is active.</li>
                    <li><strong>Sessions</strong> expire after <strong>idle timeout</strong> on the server; optional refresh authentication has a <strong>bounded maximum lifetime</strong> (not indefinite).</li>
                    <li><strong>Sign-in codes (email OTP)</strong> are short-lived and invalidated according to our database rules.</li>
                    <li><strong>Google OAuth tokens</strong> stored for revocation are removed when your account is deleted or when no longer needed.</li>
                    <li><strong>Server logs</strong> may be retained for a limited period for security and operations.</li>
                </ul>
                <p>
                    When you <strong>delete your account</strong> through the Service, we <strong>permanently delete</strong> your user-owned data held in our database and remove associated files we store (for example, uploaded media tied to your account), subject to reasonable backup rotation delays.
                    <strong>Content that belongs to other users</strong> (for example, another member’s notes in a shared context) may remain as described at deletion time in the product.
                    We <strong>attempt to revoke</strong> our Google OAuth access when you delete your account if a stored refresh token is available.
                </p>

                <h2 class="policy-doc__h2">7. Security</h2>
                <p>
                    We use industry-typical measures appropriate to the Service, including HTTPS in production, secure cookie settings where configured, server-side session controls, and hashed storage for secrets such as one-time codes.
                    No method of transmission or storage is 100% secure; we cannot guarantee absolute security.
                </p>

                <h2 class="policy-doc__h2">8. Your choices and rights</h2>
                <p>Depending on where you live, you may have rights to <strong>access</strong>, <strong>correct</strong>, <strong>delete</strong>, or <strong>export</strong> your personal data, or to <strong>object</strong> to certain processing. You may:</p>
                <ul>
                    <li><strong>Update</strong> profile or preference settings in the Service where available.</li>
                    <li><strong>Delete your account</strong> using the account deletion flow in the Service (permanent).</li>
                    <li><strong>Withdraw</strong> optional features such as push notifications in your browser or in-app settings.</li>
                    <li><strong>Contact us</strong> at <a href="mailto:support@hillwork.com">support@hillwork.com</a> for privacy requests.</li>
                </ul>
                <p>
                    If you signed in with Google, you can also <strong>remove the Service’s access</strong> to your Google Account at any time in your Google Account settings (connected apps).
                    That does not delete data already stored in our Service; use account deletion in our app for that.
                </p>

                <h2 class="policy-doc__h2">9. Children’s privacy</h2>
                <p>
                    The Service is <strong>not directed to children under 13</strong> (or the minimum age in your jurisdiction).
                    We do not knowingly collect personal information from children.
                    If you believe we have collected information from a child, contact us and we will take appropriate steps.
                </p>

                <h2 class="policy-doc__h2">10. International users</h2>
                <p>
                    Hillwork, LLC is based in the <strong>United States</strong> (<strong>Oregon</strong>).
                    If you access the Service from outside the United States, your information may be processed in the <strong>United States</strong> (including on servers or services located or operated there).
                    By using the Service, you understand that your data may be transferred to the United States or other jurisdictions that may have different data protection laws.
                </p>

                <h2 class="policy-doc__h2">11. Changes to this policy</h2>
                <p>
                    We may update this Privacy Policy from time to time.
                    We will post the updated version and revise the “Last updated” date.
                    For material changes, we may provide additional notice (for example, a notice in the Service).
                    Continued use after changes constitutes acceptance of the updated policy.
                </p>

                <h2 class="policy-doc__h2">12. U.S. state privacy notices and EU/UK users</h2>

                <h3 class="policy-doc__h3">Oregon consumers — Oregon Consumer Privacy Act (OCPA)</h3>
                <p>
                    Oregon law includes the <strong>Oregon Consumer Privacy Act (OCPA)</strong>, which may grant Oregon residents certain privacy rights <strong>when the law applies</strong> to a business.
                    Applicability depends on factors such as revenue and how much personal data the business processes; exemptions may apply.
                    This policy does not determine legal applicability.
                </p>
                <p>
                    If you are an <strong>Oregon resident</strong> and believe OCPA applies to our processing of your personal data, you may contact
                    <a href="mailto:support@hillwork.com">support@hillwork.com</a> to submit requests or questions (for example, access, correction, or deletion, where applicable).
                    We describe account deletion in Section 8.
                    We <strong>do not sell</strong> personal information as defined under typical state privacy laws.
                </p>

                <h3 class="policy-doc__h3">Other U.S. states</h3>
                <p>
                    Certain states (including <strong>California</strong>) impose additional privacy rights and disclosure obligations.
                    If you are a resident of those states, you may have additional rights. Contact <a href="mailto:support@hillwork.com">support@hillwork.com</a>.
                </p>

                <h3 class="policy-doc__h3">EU / UK / Switzerland</h3>
                <p>
                    The <strong>European Economic Area</strong>, <strong>United Kingdom</strong>, and <strong>Switzerland</strong> impose specific rules on international transfers and individual rights.
                    Contact <a href="mailto:support@hillwork.com">support@hillwork.com</a> for requests.
                </p>

                <p class="policy-doc__legal-note"><em>This section is a general summary and is not legal advice. Qualified counsel should confirm whether OCPA or other laws apply to Hillwork’s metrics and practices.</em></p>

                <hr class="policy-doc__rule">

                <p class="policy-doc__footer-note">
                    This Privacy Policy is published at <a href="https://thank.hillwork.net/policy">https://thank.hillwork.net/policy</a>.
                </p>
            </article>

<?php require_once __DIR__ . '/footer.php'; ?>
