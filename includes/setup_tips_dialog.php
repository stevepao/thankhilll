<?php
declare(strict_types=1);
?>
        <dialog id="thankhill-setup-tips-dialog" class="me-dialog th-setup-tips-dialog" aria-labelledby="thankhill-setup-tips-title">
            <button type="button" class="th-setup-tips-dialog__x th-setup-tips-dismiss" aria-label="Close tips">&times;</button>
            <h2 id="thankhill-setup-tips-title" class="th-setup-tips-dialog__title">Tips &amp; setup</h2>
            <p class="th-setup-tips-dialog__lede">Optional steps so Thankhill feels more like an app—only if you want them.</p>
            <ol class="th-setup-tips__list">
                <li class="th-setup-tips__item">
                    <span class="th-setup-tips__label">iPhone — Add to Home Screen</span>
                    <p class="th-setup-tips__text">In Safari, open the share sheet and choose <strong>Add to Home Screen</strong>. That opens Thankhill like a standalone app.</p>
                    <p class="th-setup-tips__links">
                        <a href="https://support.apple.com/guide/iphone/add-a-website-icon-to-your-home-screen-iphb871836ec/ios" target="_blank" rel="noopener noreferrer">Show me how — Apple</a>
                    </p>
                </li>
                <li class="th-setup-tips__item">
                    <span class="th-setup-tips__label">Notifications</span>
                    <p class="th-setup-tips__text">Your browser may ask before Thankhill can send reminders or comment alerts. You can turn options on or off anytime under Me.</p>
                    <p class="th-setup-tips__links">
                        <a href="/me.php#me-notifications-heading">Open Notifications in Me</a>
                    </p>
                </li>
                <li class="th-setup-tips__item">
                    <span class="th-setup-tips__label">Mac — Install or shortcut</span>
                    <p class="th-setup-tips__text">In <strong>Chrome</strong> or <strong>Edge</strong>, use <strong>Install Thankhill</strong> from the address bar or menu when your browser offers it. In <strong>Safari</strong> (recent macOS), try <strong>File → Add to Dock</strong> while Thankhill is open.</p>
                    <p class="th-setup-tips__links">
                        <a href="https://support.google.com/chrome/answer/9658361" target="_blank" rel="noopener noreferrer">Chrome — Install a site</a>
                        <span class="th-setup-tips__links-sep" aria-hidden="true">·</span>
                        <a href="https://support.apple.com/guide/safari/add-webpages-to-the-dock-sfri29460/mac" target="_blank" rel="noopener noreferrer">Safari — Add webpage to Dock</a>
                    </p>
                </li>
            </ol>
            <div class="th-setup-tips-dialog__footer">
                <button type="button" class="btn btn--ghost th-setup-tips-dismiss">Done</button>
            </div>
        </dialog>
