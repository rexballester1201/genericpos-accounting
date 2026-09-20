// admin-guide.mjs — AG·1, running the system day to day.

export const body = `
    <section class="sec" id="yours">
      <h2><span class="n">1</span>What is yours alone</h2>
      <p>An accountant can do everything to the books. An administrator can do everything to the
      <em>system</em>: the people, the settings, the chart's shape, the imports, the audit log and the
      integrity check. This guide covers those. For the books themselves, see
      <a href="users-guide.html">UG·1</a>.</p>
      <div class="note warn">
        <p class="lbl">One thing you cannot do</p>
        <p>Edit or delete a posted entry. Nobody can, including you and including anybody with the database
        password, without leaving the books provably inconsistent. If a posted entry is wrong, it is reversed
        and re-entered, and both stay on record. Say so plainly when somebody asks you to "just fix it".</p>
      </div>
    </section>

    <section class="sec" id="people">
      <h2><span class="n">2</span>People</h2>
      <p><strong>Administration › Users.</strong> Add somebody with their name, email address and role, and
      either set a password or let the system generate one — it is shown once.</p>
      <ul>
        <li><strong>Give the lowest role that lets them work.</strong> Somebody who only reads reports is a
        viewer. Somebody who enters invoices is a bookkeeper. Do not make everybody an accountant "for now".</li>
        <li><strong>Keep at least two administrators.</strong> The system refuses to demote or suspend the last
        active one, so a single administrator who leaves the company is a locked door; a second one avoids it.
        If it happens anyway, <code>php index.php tools create_admin</code> on the server is the way back.</li>
        <li><strong>Suspend, do not delete.</strong> A suspended account stops working on the next click, and
        everything that person did stays attributed to them. Accounts are never removed, because the entries
        they prepared name them.</li>
        <li><strong>Password resets</strong> go to the person's own address and expire. You can set a password
        for somebody directly, which is audited; they should change it.</li>
      </ul>
      <p>Changing a role takes effect immediately — the role is read from the database on every request, not
      from the session.</p>
    </section>

    <section class="sec" id="settings">
      <h2><span class="n">3</span>Settings</h2>
      <p><strong>Administration › Settings.</strong> Grouped, with a short explanation under each. The ones
      worth knowing about:</p>
      <div class="tablewrap"><table>
        <thead><tr><th>Setting</th><th>What it changes</th></tr></thead>
        <tbody>
          <tr><td class="k">Company details</td><td>The name, TIN, address and contact that print on the letterhead of every report and document.</td></tr>
          <tr><td class="k">Signatories</td><td>The names and titles on the signature block at the foot of a printed report.</td></tr>
          <tr><td class="k">A second person approves every entry</td><td>On: an accountant or administrator approves each entry, invoice, bill, receipt and payment, and never one they prepared. Off: they may approve their own. A bookkeeper's work always waits for an accountant either way.</td></tr>
          <tr><td class="k">Preparers may approve their own entries</td><td>For a one-person office. It switches maker–checker off; the approval is still recorded.</td></tr>
          <tr><td class="k">Number prefixes</td><td>The letters in front of a journal number, per book, and in front of document numbers. They may not be blank and may not be shared — two books with one prefix would share a counter.</td></tr>
          <tr><td class="k">Account defaults</td><td>Which account is the receivables control, the payables control, the gain on disposal, and so on. Picked from the chart, limited to the types each default accepts.</td></tr>
          <tr><td class="k">Co-operative benchmarks</td><td>Portfolio at risk, allowance cover, share capital, statutory funds, operating cost. Edit them when the CDA's guidance changes; the analysis screen marks each indicator against them.</td></tr>
          <tr><td class="k">Notifications</td><td>Whether approvals send email as well as the in-app bell.</td></tr>
          <tr><td class="k">Retention</td><td>How long read notifications are kept. The audit log is never pruned.</td></tr>
        </tbody>
      </table></div>
      <p>Three settings are shown but cannot be changed: <strong>the kind of organisation</strong> (business or
      co-operative), <strong>the business form</strong>, and <strong>the month the fiscal year starts</strong>.
      They were chosen at setup and every statement, template and period since has been built on them.</p>
    </section>

    <section class="sec" id="chart">
      <h2><span class="n">4</span>The chart of accounts</h2>
      <p>The chart is the layout of every statement, so its shape is an administrative decision, not a
      bookkeeping one.</p>
      <ul>
        <li><strong>Get it right before the first entry.</strong> An account's control flag and its
        needs-a-department flag freeze the moment it has any line — draft or posted. That is deliberate: a
        control account that could be switched on later would have lines on it with no customer.</li>
        <li><strong>Headers total, accounts hold.</strong> Nothing posts to a header. Add depth where you want
        a subtotal on the statements.</li>
        <li><strong>Deactivating is refused</strong> while a balance-sheet account has a balance, while an
        income or expense account has activity in an open year, while entries on it are waiting for approval,
        while a setting names it as a default, and while a header still has active accounts under it. Each
        refusal says which of those it is.</li>
        <li><strong>Deleting</strong> is only possible for an account nothing has ever touched.</li>
      </ul>
      <p>A large chart change is easier as a CSV import than by hand — see below.</p>
    </section>

    <section class="sec" id="calendar">
      <h2><span class="n">5</span>Periods and years</h2>
      <p><strong>Chart and periods › Fiscal years.</strong> A year is twelve months. The next one can be created
      before the current one closes, so January entries never wait on the December close.</p>
      <div class="tablewrap"><table>
        <thead><tr><th>A month is…</th><th>Meaning</th><th>Who</th></tr></thead>
        <tbody>
          <tr><td class="k">Open</td><td>Entries may be dated in it.</td><td>—</td></tr>
          <tr><td class="k">Closed</td><td>Entries are refused. Can be reopened.</td><td>Accountant</td></tr>
          <tr><td class="k">Locked</td><td>Entries are refused, and it never reopens.</td><td>Accountant</td></tr>
        </tbody>
      </table></div>
      <p>Lock a month once its figures have been filed — a return, a board pack, an audited statement. Until
      then, closed is enough.</p>
    </section>

    <section class="sec" id="cron">
      <h2><span class="n">6</span>The scheduled job</h2>
      <p>Every fifteen minutes the server should run:</p>
      <pre><code>php /path/to/accounting/index.php tools cron</code></pre>
      <p>It does two things: it turns recurring saved entries that are due into drafts for their owners, and it
      clears out old rate-limit rows, expired sign-in tokens, spent password-reset links and notifications read
      long ago. It takes a database lock, so two runs cannot overlap, and exits non-zero if a part failed —
      which is what a monitoring system should watch.</p>
      <p>Run it by hand when a recurring draft has not appeared and read what it prints. The usual answer is
      that the month the draft would be dated in is closed, and the owner already has a notification saying
      so.</p>
    </section>

    <section class="sec" id="imports">
      <h2><span class="n">7</span>Imports</h2>
      <p><strong>Administration › Imports.</strong> Four kinds: the chart of accounts, contacts, opening
      balances and journal entries. Each works the same way:</p>
      <ol>
        <li>Download the sample file for that kind. The columns and the values each accepts are listed on the
        screen.</li>
        <li>Upload yours. Nothing is written yet — the check runs first and lists every problem with its row
        number.</li>
        <li>Fix the file and check again until it is clean.</li>
        <li>Commit. The whole file goes in one transaction: all of it, or none of it.</li>
      </ol>
      <p>Imported entries are drafts, not postings; somebody still approves them. An import is audited as one
      action with its counts.</p>
    </section>

    <section class="sec" id="audit">
      <h2><span class="n">8</span>The audit log</h2>
      <p><strong>Administration › Audit log.</strong> Every change: who, what, when, from which address, and a
      link to the thing that changed. Search by action, by person or by target.</p>
      <ul>
        <li>It is append-only in the application and is never pruned.</li>
        <li>Work nobody signed in for — the scheduled job, a command on the server — shows as <strong>System</strong>.</li>
        <li>The audit row is written inside the same transaction as the change, so a change without a record
        does not happen.</li>
      </ul>
      <p>Read it when a figure is disputed, when somebody leaves, and after any incident. It is the first thing
      an auditor asks for.</p>
    </section>

    <section class="sec" id="integrity">
      <h2><span class="n">9</span>The integrity check</h2>
      <p><strong>Reports › Integrity check.</strong> Sixteen ties over the stored records — the ledger against
      itself, the subsidiary ledgers against their control accounts, documents against their entries, the asset
      register against accumulated depreciation, the counters against the numbers actually used.</p>
      <p>Everything should pass. Run it:</p>
      <ul>
        <li>after an upgrade;</li>
        <li>before an audit or a board pack;</li>
        <li>after restoring a backup;</li>
        <li>whenever a total looks wrong.</li>
      </ul>
      <p>A failure names the records involved. Nothing the application does can cause one, so a failure means
      the database was changed from outside it, or a restore was incomplete.</p>
    </section>

    <section class="sec" id="backups">
      <h2><span class="n">10</span>Backups</h2>
      <p>This is the part of your job the books depend on.</p>
      <ul>
        <li><strong>The database, every day, kept off the server.</strong> It is the books. A backup on the same
        disk is not a backup.</li>
        <li><strong><code>uploads/</code></strong> — the attachments are files, not rows, and a database dump
        does not contain them.</li>
        <li><strong><code>application/config/secrets.php</code></strong> — one copy somewhere safe. Without the
        signing secret, everybody is signed out; without the database password, nothing starts.</li>
        <li><strong>Test a restore</strong> onto a scratch database at least once a year, and run the integrity
        check against it. An untested backup is a guess.</li>
      </ul>
      <p>Restoring: the database, then the files, then <code>php index.php tools cache</code>.</p>
    </section>

    <section class="sec" id="routine">
      <h2><span class="n">11</span>A routine</h2>
      <div class="tablewrap"><table>
        <thead><tr><th>When</th><th>What</th></tr></thead>
        <tbody>
          <tr><td class="k">Daily</td><td>Confirm last night's backup exists and has a sensible size. Glance at <code>application/logs/</code> for anything new.</td></tr>
          <tr><td class="k">Weekly</td><td>Check the scheduled job is still running and exiting zero. Skim the audit log.</td></tr>
          <tr><td class="k">Monthly</td><td>After the accountant closes the month: run the integrity check, and keep a copy of the trial balance and the four statements.</td></tr>
          <tr><td class="k">Quarterly</td><td>Review the user list — leavers suspended, roles still right.</td></tr>
          <tr><td class="k">Yearly</td><td>Create the next fiscal year before it starts. Test a restore. Lock the months that have been filed.</td></tr>
        </tbody>
      </table></div>
    </section>

    <section class="sec" id="cli">
      <h2><span class="n">12</span>From the command line</h2>
      <pre><code>php index.php tools                                    what there is
php index.php tools create_admin &lt;email&gt; [password]    create or promote an administrator
php index.php tools create_user &lt;email&gt; &lt;role&gt; [password]
php index.php tools seed_chart &lt;kind&gt;                  the starting chart, into an empty ledger
php index.php tools create_year [YYYY-MM-01]           the next fiscal year and its twelve months
php index.php tools cache                              rebuild the sign-in page's branding
php index.php tools cron                               the scheduled job
php index.php tools trial_balance [date] [kind]        print a trial balance
php index.php maildiag &lt;address&gt;                       check that mail actually leaves</code></pre>
      <p>Percent-encode the <code>@</code> in an address — CodeIgniter reads these arguments as a URI and
      refuses the character. Everything here bypasses the checks the web path enforces, which is only
      acceptable for somebody who already has a shell on the server; every command is audited as System.</p>
    </section>
`;
