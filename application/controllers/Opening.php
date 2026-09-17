<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Opening.php — opening balances at go-live
 *
 * GenericPOS Accounting · administrators only (PLAN.md §8)
 *
 *   GET  /api/v1/opening-balances              the draft, the posted opening entries, pickers
 *   PUT  /api/v1/opening-balances              save the draft { entry_date, lines }
 *   POST /api/v1/opening-balances/post         post the draft as the opening entry
 *   POST /api/v1/opening-balances/{id}/undo    reverse a posted one { reason }; its lines return to the draft
 *   POST /api/v1/opening-balances/{id}/copy    load a posted one's lines into the draft
 */
class Opening extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->load->model('Opening_model', 'opening');
    }

    /** GET|PUT /api/v1/opening-balances */
    public function index()
    {
        require_method(['GET', 'PUT']);
        if (strtolower($this->input->method()) === 'put') return $this->save();
        admin_check();
        return json_response($this->opening->status(), 'Opening balances');
    }

    public function save()
    {
        $claims = admin_check();
        require_method('PUT');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $errors = $this->opening->save_draft(get_json_body(), (int) $claims['user_id']);
        if ($errors) return json_invalid($errors);
        log_admin_action($claims, 'opening.save_draft', 'app_state', NULL, ['lines' => count($this->opening->draft()['lines'])]);
        return json_response($this->opening->status(), 'Draft saved.');
    }

    /** POST /api/v1/opening-balances/post */
    public function post()
    {
        $claims = admin_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        list($id, $errors) = $this->opening->post((int) $claims['user_id']);
        if ( ! $id) {
            if (isset($errors['_']) && count($errors) === 1) return json_error($errors['_'], 409);
            return json_invalid($errors);
        }
        $this->load->model('Journal_model');
        $j = $this->Journal_model->find($id);
        return json_response($this->opening->status() + ['journal_id' => $id], 'Opening balances posted as ' . $j['journal_no'] . '.', 201);
    }

    /** POST /api/v1/opening-balances/{id}/undo */
    public function undo($id)
    {
        $claims = admin_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $reason = trim((string) (get_json_body()['reason'] ?? ''));
        if ($reason === '') return json_invalid(['reason' => 'Say why the opening balances are undone.']);

        list($rid, $err) = $this->opening->undo((int) $id, (int) $claims['user_id'], $reason);
        if ( ! $rid) return json_error($err, 409);
        return json_response($this->opening->status() + ['reversal_id' => $rid], 'Opening balances undone. Their lines are back in the draft for correcting.');
    }

    /** POST /api/v1/opening-balances/{id}/copy */
    public function copy($id)
    {
        $claims = admin_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $err = $this->opening->copy_to_draft((int) $id);
        if ($err !== '') return json_error($err, 404);
        return json_response($this->opening->status(), 'Lines copied into the draft.');
    }
}
