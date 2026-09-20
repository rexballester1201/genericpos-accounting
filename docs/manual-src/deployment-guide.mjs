// deployment-guide.mjs — DG·1, putting it on a server.

export const body = `
    <section class="sec" id="need">
      <h2><span class="n">1</span>What you need</h2>
      <div class="tablewrap"><table>
        <thead><tr><th>Part</th><th>Version</th><th>Note</th></tr></thead>
        <tbody>
          <tr><td class="k">PHP</td><td>8.2</td><td>With <code>mysqli</code>, <code>mbstring</code>, <code>fileinfo</code> and <code>gd</code>. 8.1 works; 8.0 and below do not.</td></tr>
          <tr><td class="k">Database</td><td>MariaDB 10.2.1+ or MySQL 8.0.16+</td><td>Older versions parse the schema's <code>CHECK</code> constraints and then ignore them, which silently removes a layer of protection.</td></tr>
          <tr><td class="k">Web server</td><td>Apache 2.4</td><td><code>mod_rewrite</code> on and <code>AllowOverride All</code> for the directory. Nginx needs the <code>.htaccess</code> rules translated by hand — see §5.</td></tr>
          <tr><td class="k">Node</td><td>18+</td><td>Only to rebuild the guide or run the tests. Not needed to run the system.</td></tr>
          <tr><td class="k">TLS</td><td>any</td><td>Required in practice. Sessions and attachments travel over it.</td></tr>
        </tbody>
      </table></div>
      <p><strong>Sizing.</strong> One company's books. A year of a small trading company is a few thousand
      journal lines — tens of megabytes with attachments. Two shared cores and 2 GB of memory are ample; the
      database is the only part that grows, and it grows slowly.</p>
    </section>

    <section class="sec" id="install">
      <h2><span class="n">2</span>Installing</h2>
      <h3>1 · The database</h3>
      <pre><code>mysql -u root -e "CREATE DATABASE accounting CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root accounting &lt; SCHEMA.sql</code></pre>
      <p>Create a database user for the application with <code>SELECT, INSERT, UPDATE, DELETE</code> on that
      database and nothing else. It does not need <code>DROP</code>, and it should not have it.</p>

      <h3>2 · The files</h3>
      <div class="note warn">
        <p class="lbl">The project root is the document root</p>
        <p>Apache must serve the folder that contains <code>index.php</code>. What keeps
        <code>application/</code>, <code>docs/</code>, <code>tests/</code>, <code>SCHEMA.sql</code> and every
        <code>.md</code>, <code>.sql</code>, <code>.log</code> and <code>.env</code> from being downloaded is
        the <code>.htaccess</code> in that folder. If <code>AllowOverride</code> is not <code>All</code>, the
        file is ignored and your source and schema are public. Verify it — §4.</p>
      </div>

      <h3>3 · The secrets</h3>
      <pre><code>cp application/config/secrets.example.php application/config/secrets.php</code></pre>
      <div class="tablewrap"><table>
        <thead><tr><th>Key</th><th>Value</th></tr></thead>
        <tbody>
          <tr><td class="k">GP_DB_HOST, GP_DB_NAME, GP_DB_USER, GP_DB_PASSWORD</td><td>The database above.</td></tr>
          <tr><td class="k">GP_JWT_SECRET</td><td>At least 32 random characters. <strong>It must differ from every other application's on the same host</strong> — a token signed with a shared secret would otherwise be accepted here as whoever has that user id. (The issuer claim also blocks this, but do not rely on one lock.)</td></tr>
          <tr><td class="k">GP_SETUP_KEY</td><td>Any long string. It is needed once, on the setup screen. Clear it afterwards.</td></tr>
          <tr><td class="k">SMTP settings</td><td>Only if the system is to send mail. Leave blank and it will not try.</td></tr>
        </tbody>
      </table></div>
      <pre><code>php -r "echo rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');"</code></pre>
      <p>Keep <code>secrets.php</code> out of version control — it already is, in <code>.gitignore</code> — and
      readable only by the web server user.</p>

      <h3>4 · Permissions</h3>
      <pre><code>chown -R www-data:www-data uploads application/logs application/cache
chmod -R 750          uploads application/logs application/cache
chmod 640             application/config/secrets.php</code></pre>
      <p>Those three directories are the only ones the application writes to. Everything else can be read-only
      to the web server. Attachments land in <code>uploads/attachments/</code>, behind a deny-all
      <code>.htaccess</code> the application writes itself on first use.</p>

      <h3>5 · The company</h3>
      <p>Open the site and work through the setup screen: the company, whether it is a business or a
      co-operative, the month its fiscal year starts, and the first administrator. It runs in one transaction
      and answers only while there is no user — afterwards it refuses everybody.</p>
      <p>A server with no browser to hand does the same three things by command:</p>
      <pre><code>php index.php tools seed_chart business_corporation   # or business_sole_proprietorship, cooperative
php index.php tools create_year 2026-01-01            # the first day of the first month of the books
php index.php tools create_admin owner%40example.com  # prints a password once</code></pre>
      <p>The first fiscal year fixes the month the books turn on, which Settings will not change afterwards.</p>
    </section>

    <section class="sec" id="verify">
      <h2><span class="n">3</span>Before you hand it over</h2>
      <p>Every one of these should be true. They take five minutes together.</p>
      <div class="tablewrap"><table>
        <thead><tr><th>Check</th><th>Expected</th></tr></thead>
        <tbody>
          <tr><td><code>curl -I https://site/SCHEMA.sql</code></td><td class="k">403</td></tr>
          <tr><td><code>curl -I https://site/application/config/secrets.php</code></td><td class="k">403</td></tr>
          <tr><td><code>curl -I https://site/docs/guide/01-welcome.md</code></td><td class="k">403</td></tr>
          <tr><td><code>curl -I https://site/uploads/attachments/</code></td><td class="k">403</td></tr>
          <tr><td><code>curl -s https://site/robots.txt</code></td><td class="k">Disallow: /</td></tr>
          <tr><td><code>curl -s https://site/api/v1/store</code></td><td class="k">JSON, and no TIN, address or phone in it</td></tr>
          <tr><td><code>curl -s https://site/api/v1/journals</code></td><td class="k">401 as JSON, not an HTML page</td></tr>
          <tr><td>Sign in, then <code>php index.php tools trial_balance</code></td><td class="k">"Balanced."</td></tr>
          <tr><td>Reports › Integrity check</td><td class="k">every tie passes</td></tr>
          <tr><td><code>php index.php maildiag you@example.com</code></td><td class="k">the message arrives</td></tr>
          <tr><td>Setup screen, signed out</td><td class="k">refuses — "already set up"</td></tr>
        </tbody>
      </table></div>
      <p>Then clear <code>GP_SETUP_KEY</code> from <code>secrets.php</code>.</p>
    </section>

    <section class="sec" id="scheduled">
      <h2><span class="n">4</span>The scheduled job</h2>
      <pre><code>*/15 * * * * php /path/to/accounting/index.php tools cron &gt;&gt; /var/log/accounting-cron.log 2&gt;&amp;1</code></pre>
      <p>On Windows, a Task Scheduler task running the same command every fifteen minutes. It takes a database
      lock, so overlapping runs are harmless, and it exits non-zero when a part fails — point your monitoring
      at the exit code, not the log size.</p>
      <p>It drafts recurring saved entries and prunes the auth tables, spent password-reset links and long-read
      notifications. The audit log is never pruned.</p>
    </section>

    <section class="sec" id="server">
      <h2><span class="n">5</span>Web server notes</h2>
      <h3>Apache</h3>
      <pre><code>&lt;Directory /var/www/accounting&gt;
    AllowOverride All
    Require all granted
&lt;/Directory&gt;</code></pre>
      <p>Serve production from a virtual host bound to its real name. The application will not enter
      development mode for a request that did not come from the machine itself, but a host that answers for any
      name is a bad idea for other reasons.</p>
      <h3>Nginx</h3>
      <p>There is no <code>.htaccess</code>, so its rules have to be written into the site config: send
      everything that is not a real file to <code>index.php</code>, and deny
      <code>/application</code>, <code>/docs</code>, <code>/tests</code>, <code>/uploads</code> and the
      <code>.md</code>, <code>.sql</code>, <code>.log</code>, <code>.env</code> extensions. Test all of §3
      afterwards — on Nginx those refusals are entirely your configuration.</p>
      <h3>Behind a proxy or load balancer</h3>
      <p>One node only. The rate limiter, the counters and the cron lock all use the database, so they are safe
      across processes, but the service worker and the branding cache assume one origin. Pass the real client
      address through, or the audit log records the proxy.</p>
    </section>

    <section class="sec" id="harden">
      <h2><span class="n">6</span>Hardening</h2>
      <ul>
        <li><strong>TLS everywhere</strong>, with HSTS. Sessions and attachments travel over it.</li>
        <li><strong>The database user has no <code>DROP</code></strong> and no access to other databases.</li>
        <li><strong>The database is not reachable from outside the host.</strong></li>
        <li><strong><code>display_errors = Off</code></strong> in <code>php.ini</code>. The application also
        refuses development mode for a remote request, but set both.</li>
        <li><strong>Back up before you need to.</strong> See <a href="admin-guide.html#backups">AG·1 §10</a>.</li>
        <li><strong>Two administrators</strong>, not one.</li>
        <li><strong>Clear <code>GP_SETUP_KEY</code></strong> once setup is done.</li>
        <li><strong>Attachments are not scanned.</strong> Only the accepted types get in and nothing is
        executed, but a malicious PDF is still a malicious PDF when somebody opens it. Scan
        <code>uploads/</code> with whatever the rest of the estate uses.</li>
      </ul>
    </section>

    <section class="sec" id="upgrade">
      <h2><span class="n">7</span>Upgrading</h2>
      <ol>
        <li><strong>Back up first</strong> — database and <code>uploads/</code>. Every step below assumes you
        can go back.</li>
        <li>Put the new files in place, keeping <code>application/config/secrets.php</code>,
        <code>uploads/</code> and <code>application/logs/</code>.</li>
        <li><strong>If <code>SCHEMA.sql</code> changed, apply the change by hand.</strong> There is no
        migration runner; the schema's comments say what each table is for. Compare the old and new files
        before you start.</li>
        <li><code>php index.php tools cache</code> — the sign-in page's branding.</li>
        <li><code>node docs/build-guide.mjs</code> if the guide changed, and
        <code>node docs/build-docs.mjs</code> if this documentation did.</li>
        <li><strong>Raise <code>CACHE_VERSION</code> in <code>sw.js</code></strong> whenever
        <code>index.html</code>, <code>css/</code>, <code>js/</code> or <code>pages/</code> changed, and make
        sure any new file is in its precache list. Without this, browsers keep serving the old app from
        cache.</li>
        <li>Run the suites in <code>tests/</code> against a <em>scratch copy</em> of the live database, never
        the live one.</li>
        <li>Run the integrity check on the live system and keep the result.</li>
      </ol>
      <h3>Rolling back</h3>
      <p>Restore the files and, if the schema changed, the database. A rollback that keeps a migrated database
      under old code is how books get damaged — if the schema moved, both move back.</p>
    </section>

    <section class="sec" id="move">
      <h2><span class="n">8</span>Moving it to another server</h2>
      <ol>
        <li>Stop the scheduled job on the old server.</li>
        <li><code>mysqldump</code> the database; copy <code>uploads/</code> and
        <code>application/config/secrets.php</code>.</li>
        <li>Install as in §2, but restore the dump instead of loading <code>SCHEMA.sql</code>, and put the old
        <code>secrets.php</code> back — keeping <code>GP_JWT_SECRET</code> means nobody is signed out.</li>
        <li><code>php index.php tools cache</code>.</li>
        <li>Work through §3, then the integrity check.</li>
        <li>Start the scheduled job on the new server, and only then point the name at it.</li>
      </ol>
      <p>Never run both servers against the same database with two scheduled jobs pointing at it. The lock
      makes it safe, but two hosts writing the same books is a situation nobody should have to reason about.</p>
    </section>
`;
