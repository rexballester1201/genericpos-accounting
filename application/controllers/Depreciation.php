<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Depreciation.php — monthly depreciation runs
 *
 * GenericPOS Accounting · fixed assets
 *
 *   GET  /api/v1/depreciation/runs              every role: the runs, newest first, and the periods that can be run next
 *   GET  /api/v1/depreciation/runs/{id}         every role: one run and what it charged each asset
 *   GET  /api/v1/depreciation/preview           accountant: ?period_id= — what a run would post, per asset and per line
 *   POST /api/v1/depreciation/runs              accountant: { period_id } — post the run
 *   POST /api/v1/depreciation/runs/{id}/undo    accountant: { reason } — the latest run only
 *
 * Running depreciation is a period job (PLAN.md §8): accountants run it
 * directly; there is no draft for a bookkeeper to prepare. Every rule lives
 * in Depreciation_model.
 */
class Depreciation extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->load->model('Depreciation_model', 'dep');
    }

    /** GET /api/v1/depreciation/runs */
    public function index()
    {
        $claims = viewer_check();
        require_method('GET');
        $acct    = role_rank($claims['role']) >= 3;
        $runs    = array_map(function ($r) use ($acct) {
            return $r + ['can_undo' => $acct && $r['latest'] && $r['period_status'] === 'open'];
        }, $this->dep->runs());
        $periods = $this->dep->choosable_periods();
        return json_response([
            'items'          => $runs,
            'periods'        => $periods,
            'next_period_id' => $periods ? $periods[0]['id'] : NULL,
            'latest'         => $runs ? $runs[0] : NULL,
            'can_run'        => $acct,
            'today'          => company_today(),
        ], 'Depreciation runs');
    }

    /** GET /api/v1/depreciation/runs/{id} */
    public function show($id)
    {
        $claims = viewer_check();
        require_method('GET');
        $d = $this->dep->run_detail((int) $id);
        if ( ! $d) return json_error('That depreciation run does not exist.', 404);
        $d['run']['can_undo'] = role_rank($claims['role']) >= 3 && $d['run']['latest'] && $d['run']['period_status'] === 'open';
        return json_response($d, 'Depreciation run');
    }

    /** GET /api/v1/depreciation/preview */
    public function preview()
    {
        accountant_check();
        require_method('GET');
        $pid = (int) $this->input->get('period_id');
        if ($pid <= 0) return json_invalid(['period_id' => 'Choose the month to depreciate.']);
        $p = $this->dep->period($pid);
        if ( ! $p) return json_error('That period does not exist.', 404);
        $why = $this->dep->refusal($p);
        if ($why !== '') return json_error($why, 409);

        $c = $this->dep->compute($p);
        $n = count($c['rows']);
        return json_response([
            'period'      => ['id' => (int) $p['id'], 'name' => $p['name'], 'start_date' => $p['start_date'], 'end_date' => $p['end_date']],
            'journal'     => Depreciation_model::head($p),
            'rows'        => $c['rows'],
            'lines'       => $c['lines'],
            'total_cents' => $c['total_cents'],
            'problems'    => $c['problems'],
            'caught_up'   => $c['caught_up'],
            'can_post'    => $n > 0 && ! $c['problems'],
        ], $n ? $n . ' asset' . ($n === 1 ? '' : 's') . ', ' . money_format_cents($c['total_cents']) . ' in all.'
              : 'Nothing to depreciate in ' . $p['name'] . ': no asset in use is due for depreciation that month.');
    }

    /** POST /api/v1/depreciation/runs */
    public function run()
    {
        $claims = accountant_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $pid = (int) (get_json_body()['period_id'] ?? 0);
        if ($pid <= 0) return json_invalid(['period_id' => 'Choose the month to depreciate.']);
        if ( ! $this->dep->period($pid)) return json_error('That period does not exist.', 404);

        list($run_id, $err) = $this->dep->run($pid, $claims);
        if ( ! $run_id) return json_error($err, 409);

        $d = $this->dep->run_detail($run_id);
        $d['run']['can_undo'] = $d['run']['latest'] && $d['run']['period_status'] === 'open';
        return json_response($d, 'Depreciation for ' . date('F Y', strtotime($d['run']['start_date'])) . ' posted as ' . $d['run']['journal_no'] . '.', 201);
    }

    /** POST /api/v1/depreciation/runs/{id}/undo */
    public function undo($id)
    {
        $claims = accountant_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $d = $this->dep->run_detail((int) $id);
        if ( ! $d) return json_error('That depreciation run does not exist.', 404);
        $reason = trim((string) (get_json_body()['reason'] ?? ''));
        if ($reason === '') return json_invalid(['reason' => 'Say why the run is undone.']);

        list($rid, $err) = $this->dep->undo((int) $id, $reason, $claims);
        if ( ! $rid) return json_error($err, 409);

        $this->load->model('Journal_model', 'journals');
        $r = $this->journals->find($rid);
        return json_response(['run_id' => (int) $id, 'journal_id' => $d['run']['journal_id'], 'reversal_id' => $rid, 'reversal_no' => $r['journal_no']],
            'Depreciation for ' . date('F Y', strtotime($d['run']['start_date'])) . ' undone: ' . $d['run']['journal_no'] . ' reversed by ' . $r['journal_no'] . '.');
    }
}
