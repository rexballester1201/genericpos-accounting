// marketing.mjs — MK·1, for somebody deciding whether to use it.

export const body = `
    <section class="sec" id="what">
      <h2><span class="n">1</span>In one paragraph</h2>
      <p>GenericPOS Accounting is a complete double-entry accounting system for a single Philippine business or
      co-operative, installed on your own server. It keeps the general ledger, the customers and suppliers
      behind it, the bank reconciliations, the fixed-asset register and the budgets, and produces the financial
      statements and the BIR-style books of accounts that come out of them — printed on A4 with your letterhead
      or downloaded as CSV. There is no subscription, no per-seat licence and no third party holding your
      books.</p>
    </section>

    <section class="sec" id="does">
      <h2><span class="n">2</span>What it does</h2>
      <div class="tablewrap"><table>
        <thead><tr><th>Area</th><th>What you get</th></tr></thead>
        <tbody>
          <tr><td class="k">The ledger</td><td>Chart of accounts from a business or CDA co-operative template, fiscal years and monthly periods, journal entries with two-person approval, reversals, saved and recurring entries, opening balances, attachments and printable vouchers.</td></tr>
          <tr><td class="k">Sales</td><td>Customers, invoices and credit notes with VAT, receipts with creditable withholding tax, allocations against open invoices, aging, statements of account and the subsidiary ledger.</td></tr>
          <tr><td class="k">Purchases</td><td>Suppliers, bills and debit notes, payments with expanded withholding tax, the same aging and subsidiary ledger on the payables side.</td></tr>
          <tr><td class="k">Banking</td><td>Bank accounts, statements typed in or imported from CSV, matching one bank line to many book lines, charges and interest recorded straight from a statement line, and a reconciliation that must reach zero before it can be signed off.</td></tr>
          <tr><td class="k">Fixed assets</td><td>Categories, the register, straight-line and declining-balance depreciation run month by month, disposals with the gain or loss worked out, and the lapsing schedule.</td></tr>
          <tr><td class="k">Planning</td><td>Budgets by account and month, by department, approved and then compared with the actual figures; income statements by department.</td></tr>
          <tr><td class="k">Reports</td><td>Balance sheet, income statement, changes in equity and cash flows — the last by either method PAS 7 allows — with comparative, monthly and common-size columns; trial balance in three forms; the ten-column worksheet; the general ledger; the five books of accounts; twenty-one ratios; and a co-operative's PESOS-style indicators.</td></tr>
          <tr><td class="k">Year-end</td><td>A checklist that blocks on what must be fixed, the closing entry shown in full before it is made, a co-operative's net surplus allocated to its statutory funds, and reopening if something was missed.</td></tr>
        </tbody>
      </table></div>
    </section>

    <section class="sec" id="trust">
      <h2><span class="n">3</span>Why the figures can be trusted</h2>
      <p>Most accounting software will let you do the wrong thing and then help you hide it. This one is built
      the other way round.</p>
      <div class="rule-card">
        <h3>One way in, and no way to edit history</h3>
        <p>Every figure in the ledger arrives through a balanced journal entry — whether a person typed it, an
        invoice produced it, or a depreciation run did. Entries are numbered without gaps, per book and per
        year. A posted entry is never edited and never deleted: it is reversed, and both stay on record.</p>
      </div>
      <ul>
        <li><strong>Two people, by default.</strong> One prepares, another approves, and nobody approves their
        own work. A one-person office can switch that off — and the setting says so in plain words.</li>
        <li><strong>Reports prove themselves on the page.</strong> The balance sheet balances, the aging equals
        its control account, the asset register equals accumulated depreciation, the cash-flow statement
        reconciles to the movement in cash. Each report says whether its own checks passed instead of quietly
        printing a wrong number.</li>
        <li><strong>An audit trail that cannot be skipped.</strong> Every change records who, what, when and
        from where, written in the same database transaction as the change itself. If the record cannot be
        written, the change does not happen.</li>
        <li><strong>Sixteen ties, on demand.</strong> The integrity check reconciles the stored records against
        each other — before an audit, after a restore, or whenever a total looks wrong.</li>
        <li><strong>Exact money.</strong> Every amount is a whole number of centavos. Nothing rounds where you
        cannot see it.</li>
      </ul>
    </section>

    <section class="sec" id="ph">
      <h2><span class="n">4</span>Written for Philippine practice</h2>
      <ul>
        <li>VAT inclusive or exclusive, exempt and zero-rated, per line, to input and output VAT accounts.</li>
        <li>Creditable withholding tax on receipts and expanded withholding tax on payments, each to its own
        account, with the invoice settled for the gross.</li>
        <li>The general journal, cash receipts, cash disbursements, sales and purchases books — entry by entry,
        or columnar and paged with totals carried forward, which is the form an examiner expects.</li>
        <li>For co-operatives: a CDA-style chart, the statements in CDA wording, the net-surplus allocation to
        the reserve, education and training, community development and optional funds, and the PESOS-style
        indicators with benchmarks your accountant can edit when the guidance changes.</li>
        <li>Pesos and centavos throughout, and amounts in words on vouchers and cheques.</li>
      </ul>
    </section>

    <section class="sec" id="run">
      <h2><span class="n">5</span>What it takes to run</h2>
      <div class="tablewrap"><table>
        <thead><tr><th>Question</th><th>Answer</th></tr></thead>
        <tbody>
          <tr><td class="k">Where does it live?</td><td>Your server, or a small virtual machine. PHP 8.2, MariaDB or MySQL, Apache. Two shared cores and 2 GB of memory are ample.</td></tr>
          <tr><td class="k">Who holds the data?</td><td>You. There is no cloud service, no telemetry and no outbound call other than the mail you configure.</td></tr>
          <tr><td class="k">How many users?</td><td>As many as you like. Roles, not seats.</td></tr>
          <tr><td class="k">What does it cost to run?</td><td>The server, and the time of whoever backs it up.</td></tr>
          <tr><td class="k">Does it work offline?</td><td>The app installs like a native one and keeps working for reading when the connection drops, including the whole user's guide.</td></tr>
          <tr><td class="k">How long to install?</td><td>An afternoon: load the schema, fill in four secrets, work through the setup screen. A shell-only server can do the same by command.</td></tr>
        </tbody>
      </table></div>
    </section>

    <section class="sec" id="not">
      <h2><span class="n">6</span>What it does not do</h2>
      <p>Worth knowing before you choose it, not after.</p>
      <ul>
        <li><strong>One company per installation.</strong> A second company means a second installation. That
        is a deliberate choice — no tenant column means no chance of one company seeing another's books.</li>
        <li><strong>One currency</strong>, fixed at installation. No revaluation, no translation.</li>
        <li><strong>No perpetual inventory costing.</strong> Inventory is an account; cost of sales is posted by
        entry or by count.</li>
        <li><strong>No payroll calculation.</strong> Salaries and statutory contributions are posted as
        entries.</li>
        <li><strong>No electronic filing.</strong> It produces the books and statements; submitting them is
        still a person's job.</li>
        <li><strong>No mobile app store build.</strong> It installs from the browser as a progressive web
        app.</li>
      </ul>
    </section>

    <section class="sec" id="who">
      <h2><span class="n">7</span>Who it suits</h2>
      <div class="tablewrap"><table>
        <thead><tr><th></th><th></th></tr></thead>
        <tbody>
          <tr><td class="k">A good fit</td><td>A trading or service company, or a co-operative, with one set of books, a bookkeeper and an accountant, that wants its own system on its own server and cares about being able to prove its figures.</td></tr>
          <tr><td class="k">A poor fit</td><td>A group of companies needing consolidation, anyone invoicing in several currencies, a business whose margin depends on perpetual inventory costing, or an office with nobody to take a nightly backup.</td></tr>
        </tbody>
      </table></div>
    </section>

    <section class="sec" id="included">
      <h2><span class="n">8</span>What comes with it</h2>
      <ul>
        <li><strong>The source</strong>, all of it. No compiled component, no licence server, no expiry.</li>
        <li><strong>A user's guide of sixteen chapters</strong>, written for the person keeping the books — and
        the same guide is the Help screen inside the app, searchable and available offline.</li>
        <li><strong>This documentation set</strong>: design and technical specifications, guides for
        administrators, deployment and troubleshooting, and a handover note.</li>
        <li><strong>Sixteen test suites, 677 checks</strong>, that run against a throwaway copy of your
        database — so an upgrade can be proved before it touches the books.</li>
        <li><strong>An independent build audit</strong> (<a href="audit.html">AUD·1</a>): 35 findings raised
        against the code and every one closed, with the evidence.</li>
        <li><strong>A demo company</strong> — a full year of books for a trading corporation — so you can look
        at every report with real figures in it before deciding.</li>
      </ul>
      <div class="note">
        <p class="lbl">Seeing it</p>
        <p>Install it against an empty database, run the demo seed, and open the trial balance. You will be
        looking at 405 journal entries, 1,277 lines and a balanced set of books, with every report, register
        and statement populated.</p>
      </div>
    </section>
`;
