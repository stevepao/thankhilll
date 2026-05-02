<?php
/**
 * Public marketing copy for / when the user is not signed in (OAuth / Play verification).
 */
declare(strict_types=1);

$pageTitle = 'Thankhill';
$currentNav = '';
$showNav = false;
$metaDescription = 'Thankhill is a mobile-first gratitude journal by Hillwork, LLC. Capture daily gratitude, timestamped thoughts, and optional photos—privately or with small groups you trust. For adults 18+.';

require_once dirname(__DIR__) . '/header.php';
?>

            <article class="public-home">
                <header class="public-home__hero">
                    <p class="public-home__brand">Thankhill</p>
                    <p class="public-home__tagline">A gratitude journal for everyday life.</p>
                </header>

                <section class="public-home__section" aria-labelledby="public-home-purpose-heading">
                    <h2 id="public-home-purpose-heading" class="public-home__h2">What Thankhill is</h2>
                    <p>
                        <strong>Thankhill</strong> helps you keep a simple daily gratitude practice in the browser
                        (you can also install it like an app on your phone). You write one entry for each calendar day,
                        add thoughts as you go, optionally attach photos, and choose whether to keep entries private or
                        share them with small groups you belong to.
                    </p>
                </section>

                <section class="public-home__section" aria-labelledby="public-home-features-heading">
                    <h2 id="public-home-features-heading" class="public-home__h2">What you can do</h2>
                    <ul class="public-home__list">
                        <li><strong>Today</strong> — one gratitude note per day with timestamped thoughts and optional photos.</li>
                        <li><strong>Notes</strong> — browse your journal and shared entries from groups.</li>
                        <li><strong>Groups</strong> — invite trusted people and share only what you choose.</li>
                        <li><strong>Sign in</strong> — with Google or a one-time email code.</li>
                    </ul>
                </section>

                <section class="public-home__section" aria-labelledby="public-home-audience-heading">
                    <h2 id="public-home-audience-heading" class="public-home__h2">Who it’s for</h2>
                    <p>
                        Thankhill is operated by <strong>Hillwork, LLC</strong> and is intended for <strong>adults 18 and older</strong>.
                        See our <a href="/terms">Terms of Use</a> and <a href="/policy">Privacy Policy</a> for details.
                    </p>
                </section>

                <p class="public-home__cta">
                    <a class="btn btn--primary" href="/login.php">Log in or sign up</a>
                </p>
            </article>

<?php
require_once dirname(__DIR__) . '/footer.php';
