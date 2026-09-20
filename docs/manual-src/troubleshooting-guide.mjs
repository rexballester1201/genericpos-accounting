// troubleshooting-guide.mjs — TG·1, when something is wrong.

export const body = `
    <section class="sec" id="triage">
      <h2><span class="n">1</span>Start here</h2>
      <p>Four places hold the answer to almost everything. Look in this order.</p>
      <div class="tablewrap"><table>
        <thead><tr><th>Where</th><th>What it tells you</th></tr></thead>
        <tbody>
          <tr><td class="k">The message on the screen</td><td>The system says what it refused and why. "That month is closed", "Only the person who prepared this may edit it". Read it before anything else — most of what looks like a fault is a rule.</td></tr>
          <tr><td class="k">Administration › Audit log</td><td>What actually happened, who did it, when, from where. It also tells you what did <em>not</em> happen: no row means the change was rolled back.</td></tr>
          <tr><td class="k"><code>application/logs/</code></td><td>One file a day. Anything beginning <code>[audit]</code>, <code>[Journal_model]</code> or <code>[cron]</code> is worth reading. Timestamps are UTC.</td></tr>
          <tr><td class="k">Reports › Integrity check</td><td>Sixteen ties over the stored records. If it passes, the data is sound and the problem is elsewhere.</td></tr>
        </tbody>
      </table></div>
      <div class="note">
        <p class="lbl">Before you change anything</p>
        <p>Take a database backup. Most of what follows is safe, but a wrong diagnosis acted on quickly is
        worse than a right one acted on slowly.</p>
      </div>
    </section>

    <section class="sec" id="access">
      <h2><span class="n">2</span>Signing in and getting around</h2>
      <dl class="defs">
        <div><dt>Nobody can sign in</dt>
        <dd>Check the database is up and reachable. Then <code>application/logs/</code>. A missing or short
        <code>GP_JWT_SECRET</code> gives a clean "Sign-in is temporarily unavailable: the server is not
        configured" and logs the reason — it has no fallback on purpose.</dd></div>

        <div><dt>One person cannot sign in</dt>
        <dd>Too many wrong passwords locks the account for a while; the screen says so. Otherwise the account
        may be suspended — Administration › Users. Reset their password from there if needed; it is audited.</dd></div>

        <div><dt>Everybody was signed out at once</dt>
        <dd><code>GP_JWT_SECRET</code> changed, which invalidates every token. If that was not deliberate, the
        secrets file was replaced — restore it from your safe copy. Signing back in is otherwise harmless.</dd></div>

        <div><dt>"You do not have access to this"</dt>
        <dd>The role, not a fault. A bookkeeper cannot approve; an accountant cannot reach Settings or the
        audit log. Roles are read fresh on every request, so a change takes effect on the next click.</dd></div>

        <div><dt>A menu item is missing</dt>
        <dd>Same reason. The menu only shows what the role may open.</dd></div>
      </dl>
    </section>

    <section class="sec" id="entries">
      <h2><span class="n">3</span>Entries and the workflow</h2>
      <dl class="defs">
        <div><dt>"No fiscal period covers that date"</dt>
        <dd>The date is outside every fiscal year. Create the year — Chart and periods › Fiscal years, or
        <code>php index.php tools create_year</code>. Years cannot overlap or leave gaps, so the next one must
        start the day after the last one ends.</dd></div>

        <div><dt>"That month is closed"</dt>
        <dd>Either date the entry in an open month, or ask an accountant to reopen that one. A
        <strong>locked</strong> month never reopens — that is what locking is for.</dd></div>

        <div><dt>A month will not close</dt>
        <dd>Entries are still waiting for approval in it. The screen lists them and links to each. Post or
        cancel them, then close.</dd></div>

        <div><dt>"Only the person who prepared this may edit it"</dt>
        <dd>Maker–checker. The preparer edits and resubmits; the approver approves or rejects with a reason.
        A one-person office can turn self-approval on in Settings.</dd></div>

        <div><dt>The approver cannot approve their own entry</dt>
        <dd>By design, unless Settings allows it. Somebody else approves, or the setting changes — and the
        setting says plainly that it switches maker–checker off.</dd></div>

        <div><dt>A posted entry is wrong</dt>
        <dd>Reverse it and enter it again. It cannot be edited. If it came from a module — an invoice, a
        receipt, a depreciation run — the journal screen will refuse, because it must be undone from that
        module so the document and its entry move together.</dd></div>

        <div><dt>A journal number is missing from a book</dt>
        <dd>It is not. Numbers are assigned inside the posting transaction, so a failed posting consumes none.
        If a number really is absent, the integrity check's counter tie will say so.</dd></div>

        <div><dt>A recurring entry did not appear</dt>
        <dd>Run <code>php index.php tools cron</code> by hand and read what it prints. Usually the month the
        draft would be dated in is closed, and the owner has a notification saying so. If it prints "Another
        run is still going", a previous run is stuck — check the database.</dd></div>
      </dl>
    </section>

    <section class="sec" id="figures">
      <h2><span class="n">4</span>Figures that do not agree</h2>
      <p>Every tie-out report names what caused the difference. Start with the report itself, not the
      database.</p>
      <dl class="defs">
        <div><dt>A statement says it does not prove out</dt>
        <dd>The red line on a statement means its own check failed. Posted entries always balance, so this is
        damaged data, not arithmetic. Run the integrity check and do not rely on the figures until it
        passes.</dd></div>

        <div><dt>The aging does not equal the receivables control</dt>
        <dd>Something was posted straight to the control account by journal entry instead of through an invoice
        or receipt. The aging screen shows both totals and the difference; the general ledger of that account,
        filtered to manual entries, finds it in a minute.</dd></div>

        <div><dt>A bank reconciliation will not reach zero</dt>
        <dd>Work the four lists on the screen: deposits in transit, outstanding cheques, bank charges and
        credits not yet in the books, and errors. A statement cannot be marked reconciled until the difference
        is zero — that is the point of it.</dd></div>

        <div><dt>The asset register does not match accumulated depreciation</dt>
        <dd>A depreciation entry was reversed from the journal screen rather than the run being undone, or an
        accumulated depreciation account was posted to by hand. The lapsing schedule shows the register and the
        ledger side by side.</dd></div>

        <div><dt>The two cash-flow methods differ in operating activities</dt>
        <dd>Not a fault. An asset bought on account moves no cash: the indirect method shows it as an investing
        outflow with an offsetting rise in payables, the direct method shows nothing in investing, and the note
        under the direct statement names the difference on its own line.</dd></div>

        <div><dt>A report is empty</dt>
        <dd>Check the dates on the toolbar. Reports default to the current fiscal year, and a period with no
        entries is genuinely empty.</dd></div>
      </dl>
    </section>

    <section class="sec" id="screens">
      <h2><span class="n">5</span>Screens and the browser</h2>
      <dl class="defs">
        <div><dt>The app shows an old version after an upgrade</dt>
        <dd>The service worker is serving its cache. <code>CACHE_VERSION</code> in <code>sw.js</code> was not
        raised. Raise it and reload twice. To prove it locally: DevTools → Application → Service workers,
        unregister the one scoped to this site, then delete its <code>acc-</code> caches. Avoid "Clear site
        data" if another application shares the origin.</dd></div>

        <div><dt>The loading spinner never clears</dt>
        <dd>A module failed to load. The browser console names the file. After an upgrade the usual cause is a
        new <code>js/</code> file that was not copied, or one missing from the service worker's list.
        <code>node tests/modcheck.mjs</code> catches both without a browser.</dd></div>

        <div><dt>"Unexpected token '&lt;'"</dt>
        <dd>Something answered HTML where JSON was expected. On this system that means the request never
        reached the API — a rewrite rule is missing, or the URL is wrong. Uncaught server faults answer as
        JSON, so they do not cause this.</dd></div>

        <div><dt>"Request failed" with no reason</dt>
        <dd>The network, not the server. The screen says so when it can tell the difference; the app keeps
        working for reading, because the shell is cached.</dd></div>

        <div><dt>A print comes out wrong</dt>
        <dd>Print from the report screen's own Print button, which uses the A4 stylesheet. Set the browser to
        A4 with default margins and background graphics off; the letterhead is drawn, not an image.</dd></div>

        <div><dt>The sign-in page greets the wrong company</dt>
        <dd>Somebody seeded a demo database on this machine and the branding cache was rewritten.
        <code>php index.php tools cache</code> against the real database.</dd></div>
      </dl>
    </section>

    <section class="sec" id="mail">
      <h2><span class="n">6</span>Mail</h2>
      <dl class="defs">
        <div><dt>No email arrives</dt>
        <dd><code>php index.php maildiag you@example.com</code> says whether the message left the server. If it
        did, the problem is at the receiving end — SPF, DKIM or a spam folder. If it did not, the SMTP settings
        in <code>secrets.php</code> are wrong, or the port is blocked.</dd></div>

        <div><dt>A page is slow when it sends mail</dt>
        <dd>The mailer is synchronous, so a slow SMTP server slows the request that triggered it. Use a nearby
        relay.</dd></div>

        <div><dt>Password reset links do not work</dt>
        <dd>They expire, and they are spent on first use. The scheduled job also prunes used ones. Issue a new
        one, or set the password directly from Administration › Users.</dd></div>
      </dl>
    </section>

    <section class="sec" id="server">
      <h2><span class="n">7</span>The server</h2>
      <dl class="defs">
        <div><dt>"Something went wrong on the server"</dt>
        <dd><code>application/logs/</code> has the reason with a timestamp. The audit log says what did and did
        not complete. Every API fault answers as JSON, so the browser console usually has the message
        too.</dd></div>

        <div><dt>A query that used to work now fails</dt>
        <dd>Strict SQL mode is on — a value that does not fit is refused rather than silently truncated. The
        log names the column. That is the intended behaviour; fix the value, not the mode.</dd></div>

        <div><dt>The source code is downloadable</dt>
        <dd><code>.htaccess</code> is being ignored. Set <code>AllowOverride All</code> for the directory and
        re-run the checks in <a href="deployment-guide.html#verify">DG·1 §3</a>. Treat any secret in the file as
        compromised.</dd></div>

        <div><dt>Attachments will not upload</dt>
        <dd><code>uploads/</code> is not writable by the web server, or the file is larger than PHP's
        <code>upload_max_filesize</code> and <code>post_max_size</code>. Only the accepted types get in; the
        message names them.</dd></div>

        <div><dt>An attachment opens as a download instead of in the browser</dt>
        <dd>Intended. Files are streamed with <code>nosniff</code> and a sandbox, so nothing an attachment
        contains can run in the page.</dd></div>
      </dl>
    </section>

    <section class="sec" id="serious">
      <h2><span class="n">8</span>When the data itself is wrong</h2>
      <div class="note warn">
        <p class="lbl">Stop</p>
        <p>If the integrity check fails, or a statement says it does not prove out, <strong>do not carry on
        working</strong>. Take a backup of the current state — damaged books are still evidence — and work out
        what happened before posting anything else.</p>
      </div>
      <ol>
        <li><strong>Take a backup now</strong>, labelled as the damaged state.</li>
        <li><strong>Read the integrity check.</strong> It names the entries, documents or assets involved in
        each failed tie.</li>
        <li><strong>Read the audit log around that time.</strong> Every application change is there. If the
        damage is not in the audit log, it was not made through the application — somebody has direct SQL
        access, and that is the incident.</li>
        <li><strong>Restore the last good backup onto a scratch database</strong> and run the integrity check
        against it to find when it was last sound.</li>
        <li><strong>Decide between restoring and correcting.</strong> A day of re-keying is often better than a
        correction nobody can explain to an auditor. Whichever you choose, write down what you did and keep it
        with the books.</li>
      </ol>
    </section>

    <section class="sec" id="lockedout">
      <h2><span class="n">9</span>Getting back in</h2>
      <p>With a shell on the server, nothing is unrecoverable.</p>
      <pre><code># the last administrator is gone
php index.php tools create_admin you%40example.com

# the branding is wrong
php index.php tools cache

# is the ledger sound?
php index.php tools trial_balance

# did the scheduled job run?
php index.php tools cron</code></pre>
      <p>Everything in that list is audited as System, so the recovery itself is on the record.</p>
    </section>
`;
