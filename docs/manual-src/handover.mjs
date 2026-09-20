// handover.mjs — HO·1, for the next maintainer.

export const body = `
    <section class="sec" id="first">
      <h2><span class="n">1</span>Read these, in this order</h2>
      <ol>
        <li><strong>This note</strong>, to the end. It is short on purpose.</li>
        <li><strong><a href="technical-spec.html">TS·1 Technical Specification</a></strong> — the architecture,
        the data model and the rules the code enforces.</li>
        <li><strong><code>PLAN.md</code></strong> in the repository — the record of what was decided and why.
        It is not documentation of the code; it is the argument behind it.</li>
        <li><strong><code>SCHEMA.sql</code></strong> — the whole database with the reasoning in its comments.
        Read it before you read a model.</li>
        <li><strong><code>application/models/Journal_model.php</code></strong> — the one file that writes the
        ledger. Everything else is a consumer of it.</li>
      </ol>
      <p>Then install it locally, seed the demo company, and click through the screens for an hour. The system
      is easier to hold in your head from the outside in.</p>
    </section>

    <section class="sec" id="map">
      <h2><span class="n">2</span>The shape of it</h2>
      <div class="tablewrap"><table>
        <thead><tr><th>Part</th><th>Where</th></tr></thead>
        <tbody>
          <tr><td class="k">API and business rules</td><td><code>application/</code> — CodeIgniter 3.1.9 on PHP 8.2</td></tr>
          <tr><td class="k">The one way into the ledger</td><td><code>application/models/Journal_model.php</code></td></tr>
          <tr><td class="k">Statements and analysis</td><td><code>application/libraries/Statement_lib.php</code>, <code>Analysis_lib.php</code>, <code>Books_lib.php</code>, <code>Integrity_lib.php</code></td></tr>
          <tr><td class="k">Screens</td><td><code>js/&lt;page&gt;.js</code> + <code>pages/&lt;page&gt;.html</code>, one pair per screen</td></tr>
          <tr><td class="k">Routing</td><td><code>application/config/routes.php</code>, plus one file per module in <code>application/config/routes/</code></td></tr>
          <tr><td class="k">The database</td><td><code>SCHEMA.sql</code></td></tr>
          <tr><td class="k">Decisions</td><td><code>PLAN.md</code></td></tr>
          <tr><td class="k">The user's guide</td><td><code>docs/guide/*.md</code> → <code>help/guide.json</code> (<code>node docs/build-guide.mjs</code>)</td></tr>
          <tr><td class="k">This documentation set</td><td><code>docs/manual-src/*.mjs</code> → <code>docs/manual/*.html</code> (<code>node docs/build-docs.mjs</code>)</td></tr>
          <tr><td class="k">Tests</td><td><code>tests/</code> — see <code>tests/README.md</code></td></tr>
        </tbody>
      </table></div>
    </section>

    <section class="sec" id="rules">
      <h2><span class="n">3</span>Four rules you should not break</h2>
      <div class="rule-card">
        <h3>1 · Nothing writes journal lines but Journal_model</h3>
        <p>A new module that needs to post calls <code>post_system()</code> and defines a new source. Writing
        <code>gp_journal_lines</code> directly skips the balance check, the period check, the control-account
        check, the numbering and the audit row — all of which live in one place precisely so that no module has
        to remember them.</p>
      </div>
      <div class="rule-card">
        <h3>2 · The guard is the first line of every controller method</h3>
        <p><code>viewer_check()</code>, <code>bookkeeper_check()</code>, <code>accountant_check()</code>,
        <code>admin_check()</code>. There is no middleware to register and therefore none to forget. A method
        without a guard is a public endpoint; treat one as a defect.</p>
      </div>
      <div class="rule-card">
        <h3>3 · Money is an integer number of centavos</h3>
        <p>Columns end in <code>_cents</code>, rates are basis points. Never introduce a float into a stored
        figure, and never format money for storage. Where an amount must be shared out, use the
        largest-remainder helper so the parts add back to the whole.</p>
      </div>
      <div class="rule-card">
        <h3>4 · The audit row goes inside the transaction</h3>
        <p><code>log_admin_action()</code> is called within the same transaction as the change, and a failure to
        write it fails the change. Do not move it "for performance".</p>
      </div>
    </section>

    <section class="sec" id="unattended">
      <h2><span class="n">4</span>What runs without anybody watching</h2>
      <ul>
        <li><strong>The scheduled job</strong>, every fifteen minutes:
        <code>php index.php tools cron</code>. It drafts recurring saved entries and prunes the auth tables,
        spent password-reset links and long-read notifications. It takes a database lock and exits non-zero on
        failure — something should be watching that exit code.</li>
        <li><strong>The service worker</strong> in every browser that has opened the app. It serves the shell
        from cache until <code>CACHE_VERSION</code> in <code>sw.js</code> changes. Forgetting to raise it is
        the single most common way to ship a change nobody sees.</li>
        <li><strong>Nothing else.</strong> No queue, no daemon, no background worker. Mail is sent
        synchronously inside the request that causes it.</li>
      </ul>
    </section>

    <section class="sec" id="week-one">
      <h2><span class="n">5</span>Week one</h2>
      <ol>
        <li><strong>Get a local copy running</strong> against a scratch database with the demo seed, and run
        the suites in <code>tests/</code> against it. If they pass, your environment is right.</li>
        <li><strong>Find the production backup</strong> and restore it onto a scratch database. Run the
        integrity check against the restore. Now you know the backups work — which is more important than
        anything else on this list.</li>
        <li><strong>Read the audit log</strong> on production for the last month. It tells you how the system is
        actually used, which is never quite how it was designed to be used.</li>
        <li><strong>Check the scheduled job</strong> is running and exiting zero.</li>
        <li><strong>Make sure there are two administrators</strong>, and that you are one of them.</li>
      </ol>
    </section>

    <section class="sec" id="change">
      <h2><span class="n">6</span>Making a change safely</h2>
      <ol>
        <li>Branch. The current line of work is <code>build/accounting</code>.</li>
        <li>Make the change. If it touches the ledger, add the case to a suite in <code>tests/</code>
        first.</li>
        <li><code>php -l</code> over what you touched, and <code>node tests/modcheck.mjs</code> — it checks
        every import resolves, every route has a page, every page has its module and every icon used is
        defined.</li>
        <li>Seed a scratch database and run the suites against it. Re-seed between the ones that close a year
        or register an asset; they are not idempotent, by nature rather than by accident.</li>
        <li>If the front end changed: add new files to the precache list in <code>sw.js</code> and raise
        <code>CACHE_VERSION</code>.</li>
        <li>If the guide changed: <code>node docs/build-guide.mjs</code>. If this documentation changed:
        <code>node docs/build-docs.mjs</code>.</li>
        <li>Drop the scratch database and run <code>php index.php tools cache</code> against the real one —
        seeding rewrites the branding cache.</li>
        <li>Deploy per <a href="deployment-guide.html#upgrade">DG·1 §7</a>, and run the integrity check
        afterwards.</li>
      </ol>
    </section>

    <section class="sec" id="traps">
      <h2><span class="n">7</span>Things that will surprise you</h2>
      <ul>
        <li><strong>Nested transactions throw.</strong> CodeIgniter counts depth and only the outermost
        rollback reaches the database, so <code>post_system()</code> throws when it is called inside a caller's
        transaction and fails. The caller must let the exception roll its own transaction back.</li>
        <li><strong><code>mysqli_sql_exception</code> extends <code>RuntimeException</code>.</strong> Catch the
        SQL one first, or a database fault will be reported to the user as a rule violation.</li>
        <li><strong>An account's flags freeze</strong> once it has any line, draft included. This is why the
        chart has to be right before the first entry.</li>
        <li><strong>Strict SQL mode is on.</strong> A value that does not fit is refused rather than
        truncated.</li>
        <li><strong>Three settings are read-only after setup</strong> — the kind of organisation, the business
        form and the fiscal-year start month. Everything since has been built on them.</li>
        <li><strong>Saved entries are stored by account code, not id</strong>, so a renumbered chart cannot
        silently point one somewhere else.</li>
        <li><strong>Development mode needs a loopback address</strong> as well as the <code>Host</code> header.
        A remote request never gets error pages.</li>
        <li><strong>CLI arguments are parsed as a URI</strong>, so an email address needs its <code>@</code>
        percent-encoded.</li>
      </ul>
    </section>

    <section class="sec" id="open">
      <h2><span class="n">8</span>What is still open</h2>
      <p>None of these is a defect. They are known limits, listed so you do not discover them at a bad
      moment.</p>
      <div class="tablewrap"><table>
        <thead><tr><th>Limit</th><th>What it means for you</th></tr></thead>
        <tbody>
          <tr><td class="k">No migration runner</td><td>Schema changes are applied by hand. Diff <code>SCHEMA.sql</code> before an upgrade.</td></tr>
          <tr><td class="k">The audit trail is append-only in code, not in the database</td><td>Somebody with direct SQL access can still edit rows. Backups and database permissions are the control.</td></tr>
          <tr><td class="k">No virus scanning on attachments</td><td>Only the accepted types get in and nothing is executed, but scan <code>uploads/</code> with whatever the estate uses.</td></tr>
          <tr><td class="k">Mail is synchronous</td><td>A slow SMTP server slows the request that sends the mail.</td></tr>
          <tr><td class="k">One currency, one company, no inventory costing, no payroll</td><td>Deliberate. See <a href="design-spec.html#scope">DS·1 §1</a>.</td></tr>
          <tr><td class="k">Most screens have not been clicked through in a browser</td><td>They are covered by the API suites and a static pass over every module, but a visual sweep is still worth an afternoon.</td></tr>
        </tbody>
      </table></div>
    </section>

    <section class="sec" id="history">
      <h2><span class="n">9</span>Where it came from</h2>
      <p>It grew out of the GenericPOS shop codebase (baseline commit <code>3ab52a9</code>). The shop, the point
      of sale, the USDT rail and the Android build were removed; what remains of that lineage is the account
      system, the mailer, the rate limiting and the offline shell. That is why some table names carry a
      <code>gp_</code> prefix and why the project keeps the name.</p>
      <p>The ledger and its modules were built on the branch <code>build/accounting</code> in nine commits, each
      with its own theme — platform and security, the ledger foundations, the five modules, year-end and the
      office tools, the reports and dashboard, the wiring, the tests, the guide, and the last of the audit.
      <code>git log</code> reads as the order the system was built in, and the messages say why.</p>
    </section>

    <section class="sec" id="checklist">
      <h2><span class="n">10</span>Handover checklist</h2>
      <div class="tablewrap"><table>
        <thead><tr><th>Item</th><th>Confirmed</th></tr></thead>
        <tbody>
          <tr><td>Repository access, and the branch it is deployed from</td><td></td></tr>
          <tr><td>Server access: SSH, the web root, the database</td><td></td></tr>
          <tr><td>A copy of <code>application/config/secrets.php</code>, held safely</td><td></td></tr>
          <tr><td>Where the backups go, and who checks them</td><td></td></tr>
          <tr><td>A restore tested onto a scratch database, with the integrity check run against it</td><td></td></tr>
          <tr><td>The scheduled job, and what watches its exit code</td><td></td></tr>
          <tr><td>The SMTP account, and <code>maildiag</code> proved to work</td><td></td></tr>
          <tr><td>TLS certificate: who renews it, and when it expires</td><td></td></tr>
          <tr><td>Two administrator accounts, one of them yours</td><td></td></tr>
          <tr><td>Who to ask about the books themselves — the accountant, not the developer</td><td></td></tr>
        </tbody>
      </table></div>
    </section>
`;
