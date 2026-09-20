// design-spec.mjs — DS·1, the design specification. Prose only; the shell is in build-docs.mjs.

export const body = `
    <section class="sec" id="scope">
      <h2><span class="n">1</span>What this is</h2>
      <p>GenericPOS Accounting is a double-entry general ledger for <strong>one company per installation</strong>,
      written for Philippine practice: a business (corporation or sole proprietorship) or a co-operative
      registered with the CDA. It keeps the books, the subsidiary ledgers behind them, and the statements and
      registers that come out of them.</p>
      <p>It is one system, not a suite. The chart of accounts, the journals, receivables and payables, banking,
      fixed assets, budgets and the statements all sit on the same ledger and the same audit trail. There is no
      integration to keep in step, because there is nothing to integrate.</p>
      <div class="rule-card">
        <h3>The one rule everything else follows from</h3>
        <p>Nothing reaches the ledger except through a posted, balanced journal entry. An invoice, a receipt, a
        depreciation run and the year-end closing all write the same kind of entry, through the same function,
        numbered gap-free per book per fiscal year, dated in an open month of an open year, with its audit row
        written inside the same database transaction.</p>
        <p>A posted entry is never edited and never deleted. It is reversed, and both the entry and its reversal
        stay on record. Everything a user can do to the books is a consequence of that rule.</p>
      </div>
      <h3>Out of scope, deliberately</h3>
      <ul>
        <li><strong>Several companies in one installation.</strong> One set of books per install, one database.
        A second company is a second installation; nothing is shared and nothing can leak between them.</li>
        <li><strong>Several currencies.</strong> One currency, chosen at setup. Multi-currency means revaluation,
        translation and a second set of rounding rules in every statement — a different product.</li>
        <li><strong>Perpetual inventory costing.</strong> Inventory is an account. Cost of sales is posted by
        entry or by the periodic count, which is what a business of this size actually does.</li>
        <li><strong>Payroll.</strong> Salaries, statutory contributions and withholding on compensation are
        expenses and liabilities posted as entries. Computing them is a payroll system's job.</li>
        <li><strong>A public face.</strong> There is no storefront, no customer login, no anonymous page. Every
        endpoint but sign-in requires a session; <code>robots.txt</code> refuses everything.</li>
      </ul>
    </section>

    <section class="sec" id="people">
      <h2><span class="n">2</span>Who uses it</h2>
      <p>Four roles, ranked. Each one can do everything the role below it can.</p>
      <div class="tablewrap"><table>
        <thead><tr><th>Role</th><th>Rank</th><th>What they do</th><th>What they cannot do</th></tr></thead>
        <tbody>
          <tr><td class="k">Viewer</td><td class="num">1</td><td>Reads every report, every ledger, every register. Prints and downloads.</td><td>Change anything at all.</td></tr>
          <tr><td class="k">Bookkeeper</td><td class="num">2</td><td>Prepares entries, invoices, bills, receipts, payments, bank statements and asset records. Submits them for approval.</td><td>Approve or post anything, including their own work. Close a month.</td></tr>
          <tr><td class="k">Accountant</td><td class="num">3</td><td>Approves and posts, reverses, runs depreciation, reconciles banks, closes and reopens months, runs the year-end.</td><td>Add users, change settings, read the audit log, import files.</td></tr>
          <tr><td class="k">Administrator</td><td class="num">4</td><td>Everything, plus users, settings, imports, the integrity check, opening balances and the audit log.</td><td>Edit a posted entry. Nobody can.</td></tr>
        </tbody>
      </table></div>
      <div class="note">
        <p class="lbl">Maker–checker</p>
        <p>The separation between preparing and approving is the point of the bookkeeper and accountant roles.
        By default nobody approves their own work, and only the person who prepared a draft may edit it. A
        one-person office can switch self-approval on in Settings; the setting says plainly that doing so turns
        maker–checker off, and every approval still records who did it.</p>
      </div>
      <p>The role is read from the database on every request, never from the session token. Changing somebody's
      role takes effect on their next click, not at their next sign-in.</p>
    </section>

    <section class="sec" id="principles">
      <h2><span class="n">3</span>The principles</h2>
      <dl class="defs">
        <div><dt>The chart of accounts is the layout</dt>
        <dd>No statement has a hard-coded line. An account's type decides which statement it appears on, its
        subtype decides the section (current and non-current; cost of sales, operating, finance, other, income
        tax), and the header tree decides the line items and their subtotals. A company that renames, renumbers
        or regroups its chart gets statements that follow, with nothing to edit in the code.</dd></div>

        <div><dt>Every report proves itself</dt>
        <dd>A statement carries its own checks and says on the page whether they passed: the balance sheet
        balances, the income statement's net income equals its accounts, the cash-flow statement reconciles to
        the movement in cash, the aging ties to its control account, the asset register ties to accumulated
        depreciation. Posted entries always balance, so a failed check means damaged data, and the report says
        so rather than printing a wrong figure quietly.</dd></div>

        <div><dt>Money is an integer</dt>
        <dd>Every amount is a whole number of centavos. Rates are basis points. No floating-point value ever
        touches a stored figure, so ₱0.01 never appears or disappears in a total. Where a division must be
        shared out — a surplus allocation, a depreciation schedule — the largest-remainder method puts the odd
        centavos somewhere deliberate, and the parts add back to the whole.</dd></div>

        <div><dt>Nothing disappears</dt>
        <dd>Posted entries are reversed, not deleted. Documents are cancelled, not removed. Users are suspended,
        not erased. The audit log is append-only and is never pruned, not even by the scheduled job that prunes
        everything else.</dd></div>

        <div><dt>It works when the connection does not</dt>
        <dd>The whole front end — shell, screens, styles, icons and the sixteen-chapter guide — is cached by a
        service worker. A dropped connection leaves the app usable for reading, and says clearly when a write
        cannot go through, instead of appearing to succeed.</dd></div>

        <div><dt>Plain words on the screen</dt>
        <dd>Screens speak the way an accountant speaks: "This month is closed", "They agree", "Cash received
        from customers". Errors say what went wrong and what to do. Nothing in the interface refers to a table,
        a route or a status code.</dd></div>
      </dl>
    </section>

    <section class="sec" id="model">
      <h2><span class="n">4</span>The things it keeps</h2>
      <p>The domain in the order a user meets it.</p>
      <div class="tablewrap"><table>
        <thead><tr><th>Thing</th><th>What it is</th></tr></thead>
        <tbody>
          <tr><td class="k">Account</td><td>A line in the chart: code, name, type, subtype, a place in the header tree. May be a header (a total, nothing posts to it) or postable. May be a control account, in which case every line on it names a customer or supplier.</td></tr>
          <tr><td class="k">Fiscal year</td><td>Twelve months, starting on the first of any month. Open, then closed. Years do not overlap and do not leave gaps.</td></tr>
          <tr><td class="k">Period</td><td>A calendar month inside a year: open → closed ⇄ open → locked. A locked month never reopens.</td></tr>
          <tr><td class="k">Journal entry</td><td>A header and two or more lines that balance, in one of eight books. Draft → submitted → posted, or rejected; cancelled from draft; reversed once posted.</td></tr>
          <tr><td class="k">Contact</td><td>A customer, a supplier, or both. Carries the TIN, terms and address that print on documents.</td></tr>
          <tr><td class="k">Document</td><td>An invoice, bill, credit note or debit note, with VAT and its own lines. Posts one journal entry; carries an open balance that settlements reduce.</td></tr>
          <tr><td class="k">Settlement</td><td>A receipt or payment: cash moved, tax withheld, and allocations against documents. Posts one journal entry.</td></tr>
          <tr><td class="k">Bank statement</td><td>Lines from the bank, entered or imported, each matched to book lines, ignored, or recorded as a new entry. Reconciles to zero or it does not reconcile.</td></tr>
          <tr><td class="k">Asset</td><td>A registered item in a category, depreciated month by month by run, disposed of with a gain or loss. Ties to the ledger's accumulated depreciation.</td></tr>
          <tr><td class="k">Budget</td><td>Amounts by account and month, optionally by department, approved and then compared with the actual figures.</td></tr>
          <tr><td class="k">Department</td><td>A cost or profit centre named on journal lines. Accounts can be made to require one.</td></tr>
        </tbody>
      </table></div>
    </section>

    <section class="sec" id="flow">
      <h2><span class="n">5</span>How the work moves</h2>
      <h3>The life of an entry</h3>
      <p>A bookkeeper writes a draft and submits it. It appears in the accountant's approval queue with a
      notification. The accountant either posts it — at which point it takes its journal number, its lines reach
      the ledger and its audit row is written, all inside one transaction — or rejects it with a reason, which
      sends it back to the preparer to correct and submit again. Once posted, the only way back is a reversal,
      dated on or after the original, which leaves both entries visible.</p>
      <p>A module's entry — the one behind an invoice or a depreciation run — cannot be reversed from the
      journal screen. It is undone from the module that made it, so the document and its entry move together.</p>
      <h3>The month</h3>
      <p>Post everything, reconcile the banks, run depreciation, check the aging against its control accounts,
      then close the month. A month with entries still waiting for approval will not close, and the screen links
      to them. Closing can be undone; locking cannot.</p>
      <h3>The year</h3>
      <p>The year-end screen runs a checklist first: some items block the close, some only warn. It then shows
      the closing entry in full — every income and expense account against the equity account — before anything
      is written. A co-operative's net surplus goes on to the statutory funds in the proportions the CDA
      requires, with the largest-remainder method settling the odd centavos. Both steps can be undone by an
      administrator while the next year is still open.</p>
    </section>

    <section class="sec" id="screens">
      <h2><span class="n">6</span>The screens</h2>
      <p>Fifty-six screens, each one a page of markup and a module of JavaScript with the same name. They group
      the way the menu groups them.</p>
      <div class="tablewrap"><table>
        <thead><tr><th>Group</th><th>Screens</th></tr></thead>
        <tbody>
          <tr><td class="k">Front</td><td>Dashboard, notifications, the account page, sign-in and password reset, first-time setup</td></tr>
          <tr><td class="k">Ledger</td><td>Journal entries, the entry editor, approvals, vouchers, saved and recurring entries, opening balances, year-end closing</td></tr>
          <tr><td class="k">Chart and periods</td><td>Chart of accounts, one account, fiscal years and periods</td></tr>
          <tr><td class="k">Sales and purchases</td><td>Customers, suppliers, one contact, invoices, bills, one document, the document editor, receipts, payments, one settlement, the settlement editor</td></tr>
          <tr><td class="k">Banking</td><td>Bank accounts, one statement, matching, the reconciliation report</td></tr>
          <tr><td class="k">Assets</td><td>The register, one asset, the asset editor, categories, depreciation runs, the lapsing schedule</td></tr>
          <tr><td class="k">Planning</td><td>Budgets, one budget, budget vs actual, departments, income by department</td></tr>
          <tr><td class="k">Reports</td><td>The four statements, trial balance, general ledger, books of accounts, the worksheet, aging, statements of account, subsidiary ledgers, ratio analysis, the integrity check</td></tr>
          <tr><td class="k">Administration</td><td>Users, settings, imports, the audit log, help</td></tr>
        </tbody>
      </table></div>
      <h3>What a screen looks like</h3>
      <ul>
        <li><strong>A working screen</strong> — a toolbar of choices, a table or form, and a status line that
        says what the figures mean. Every choice a user makes goes into the address bar, so a reload, a bookmark
        or a link shared with a colleague shows the same thing.</li>
        <li><strong>A report sheet</strong> — the company's letterhead, the title, the period, the figures in
        tabular numerals, the tie-out line, and the signature block. The same sheet is what prints: A4, the
        navigation gone, the letterhead kept. Every report also downloads as CSV with a UTF-8 byte-order mark,
        so Excel opens it with the peso sign intact.</li>
      </ul>
      <p>Amounts are right-aligned tabular figures; negatives are shown in parentheses, the way a ledger shows
      them. A single rule sits over a total and a double rule under a grand total.</p>
    </section>

    <section class="sec" id="local">
      <h2><span class="n">7</span>Written for the Philippines</h2>
      <ul>
        <li><strong>VAT</strong> — inclusive or exclusive, exempt and zero-rated, computed per document line,
        with input and output VAT to their own accounts.</li>
        <li><strong>Withholding</strong> — creditable withholding tax on a receipt (the customer withheld it)
        and expanded withholding tax on a payment (you withheld it), each posted to its own account, with the
        document settled for the gross amount.</li>
        <li><strong>The books of accounts</strong> — the general journal, cash receipts, cash disbursements,
        sales and purchases books, printable entry by entry or columnar and paged with totals carried forward,
        which is the form a BIR examiner expects.</li>
        <li><strong>Co-operatives</strong> — a CDA-style chart, the statements in CDA wording (financial
        condition, operations, changes in members' equity), the net-surplus allocation to the reserve, education
        and training, community development and optional funds, and the PESOS-style indicators with benchmarks
        an accountant can edit when the guidance changes.</li>
        <li><strong>The peso</strong> — ₱ and two decimal places throughout, and amounts in words on vouchers
        and cheques.</li>
      </ul>
    </section>

    <section class="sec" id="decisions">
      <h2><span class="n">8</span>Decisions, and why</h2>
      <div class="tablewrap"><table>
        <thead><tr><th>Decision</th><th>Why</th></tr></thead>
        <tbody>
          <tr><td class="k">One company per install</td><td>A tenant column on every table is one forgotten <code>WHERE</code> away from showing one company another's books. Separate databases cannot make that mistake.</td></tr>
          <tr><td class="k">One writer into the ledger</td><td>Every rule — balance, period, control account, department, numbering, audit — is enforced in one function. A module cannot forget one of them, because it never writes lines itself.</td></tr>
          <tr><td class="k">Integer centavos</td><td>Money that rounds is money that disagrees with itself. Integers make the arithmetic exact and the comparisons trustworthy.</td></tr>
          <tr><td class="k">Statements laid out from the chart</td><td>Every company's chart is different. Hard-coded statements mean code changes for each one; a chart-driven layout means none.</td></tr>
          <tr><td class="k">No build step on the front end</td><td>Plain ES modules load straight from disk. Nobody needs Node to run the system, and a fix can be made on the server with a text editor.</td></tr>
          <tr><td class="k">JSON API behind a single-page app</td><td>The same API serves the screens, the tests and anything added later. Every rule is on the server; the browser is a view.</td></tr>
          <tr><td class="k">Reversal instead of deletion</td><td>The books must show what happened, including the mistakes. An entry that vanishes leaves a gap in a numbered book, which is exactly what a numbered book exists to prevent.</td></tr>
          <tr><td class="k">The audit row inside the transaction</td><td>A change that is recorded afterwards can be made without being recorded. If the audit row cannot be written, the change does not happen.</td></tr>
        </tbody>
      </table></div>
    </section>

    <section class="sec" id="done">
      <h2><span class="n">9</span>What counts as finished</h2>
      <p>The system is complete against its plan when all of the following hold. All of them do, as of this
      version; the evidence is in <a href="audit.html">AUD·1</a>.</p>
      <ul>
        <li>Every posted line belongs to a balanced entry, in an open period, on a postable account.</li>
        <li>Journal numbers run without gaps, per book and per fiscal year.</li>
        <li>Every line on a control account names a customer or supplier, and the schedule of that control
        account equals its balance.</li>
        <li>Every statement's own checks pass on real books.</li>
        <li>Every change is in the audit log, with who made it and from where.</li>
        <li>A closed month refuses entries; a locked month refuses to reopen.</li>
        <li>The asset register ties to accumulated depreciation, and each depreciation run ties to its entry.</li>
        <li>Both cash-flow methods reach the same change in cash.</li>
        <li>The sixteen ties of the integrity check pass.</li>
      </ul>
    </section>
`;
