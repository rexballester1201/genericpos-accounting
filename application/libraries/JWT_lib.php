<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * JWT_lib.php — HS256 JSON Web Tokens
 *
 * GenericPOS · lazy-loaded by api_helper::_load_jwt()
 *
 *   generate(array $payload) → string
 *   validate(string $token)  → stdClass payload | FALSE
 *
 * Required claims: user_id, role, account_state, auth_method. Optional: pos
 * (register id) and dev (device id) — present only on a register (PIN) session.
 * generate() adds iat, exp and jti.
 *
 * Lifetime by audience (app.php Section B):
 *   POS session (pos claim) → jwt_expiry_pos_s
 *   customer                → jwt_expiry_customer_s
 *   staff                   → jwt_expiry_staff_s
 *
 * validate() checks structure, algorithm (HS256 only — 'none' is rejected
 * loudly), the HMAC in constant time, and expiry. It does NOT check the
 * account; api_helper::auth_check() does that once per request.
 */
class JWT_lib
{
    const ALGORITHM = 'HS256';
    const HASH_ALGO = 'sha256';

    const REQUIRED_PAYLOAD_FIELDS = ['user_id', 'role', 'account_state', 'auth_method'];

    protected $CI;
    protected $secret;
    protected $leeway;
    protected $exp_customer;
    protected $exp_staff;
    protected $exp_pos;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->config->load('jwt', TRUE);

        $secret = (string) $this->CI->config->item('jwt_secret', 'jwt');

        /* NOTE: NO FALLBACK. A missing secret is a configuration error and the
           answer is a clean JSON 503 — never a known default that lets anyone
           holding the codebase mint an administrator token, and never CI's HTML
           error page, which the SPA would report as "Unexpected token '<'". */
        if (strlen($secret) < 32) {
            log_message('error', 'JWT_lib: GP_JWT_SECRET is missing or shorter than 32 bytes. '
                . 'Set it in application/config/secrets.php. Authentication is disabled until then.');

            if (function_exists('json_error')) {
                json_error('Sign-in is temporarily unavailable: the server is not configured.', 503,
                           ['code' => 'AUTH_NOT_CONFIGURED']);
            }
            show_error('Authentication is not configured.', 503);
        }

        $this->secret = $secret;
        $this->leeway = (int) ($this->CI->config->item('jwt_leeway_s', 'jwt') ?? 0);

        $this->CI->config->load('app', FALSE, TRUE);
        $this->exp_customer = (int) ($this->CI->config->item('jwt_expiry_customer_s') ?: 86400);
        $this->exp_staff    = (int) ($this->CI->config->item('jwt_expiry_staff_s')    ?: 43200);
        $this->exp_pos      = (int) ($this->CI->config->item('jwt_expiry_pos_s')      ?: 57600);
    }

    /**
     * @param int|null $ttl  seconds; NULL = the lifetime for the audience. A
     *                       short-lived purpose token (a manager's approval at
     *                       the till) passes its own and carries a `typ` claim,
     *                       which auth_check() refuses as a session.
     */
    public function generate(array $payload, $ttl = NULL): string
    {
        $missing = array_diff(self::REQUIRED_PAYLOAD_FIELDS, array_keys($payload));
        if ($missing) {
            $msg = 'JWT_lib::generate() missing claims: ' . implode(', ', $missing);
            log_message('error', $msg);
            throw new RuntimeException($msg);
        }

        $payload['user_id'] = (int) $payload['user_id'];
        if (isset($payload['pos'])) $payload['pos'] = (int) $payload['pos'];
        if (isset($payload['dev'])) $payload['dev'] = (int) $payload['dev'];

        $now = time();

        if ($ttl !== NULL)                          $ttl = max(30, (int) $ttl);
        elseif ( ! empty($payload['pos']))          $ttl = $this->exp_pos;
        elseif ($payload['role'] === 'customer')    $ttl = $this->exp_customer;
        else                                        $ttl = $this->exp_staff;

        $payload['iat'] = $now;
        $payload['exp'] = $now + $ttl;
        $payload['jti'] = bin2hex(random_bytes(8));

        $header = $this->_b64(json_encode(['typ' => 'JWT', 'alg' => self::ALGORITHM]));
        $body   = $this->_b64(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $header . '.' . $body . '.' . $this->_sign($header . '.' . $body);
    }

    public function validate(string $token)
    {
        $parts = explode('.', trim($token));
        if (count($parts) !== 3) return FALSE;

        list($h64, $p64, $s64) = $parts;

        $header = $this->_decode_json($h64);
        if ($header === FALSE) return FALSE;

        /* The TYPE is checked before the value is touched, and the raw value is
           never interpolated: the header is attacker-written, and an array in
           strtolower() or an object in a log string used to throw an uncaught
           500 on the path every authenticated request takes. */
        $alg = $header->alg ?? NULL;
        if ( ! is_string($alg)) return FALSE;
        $alg = strtolower($alg);

        if ($alg === 'none') {
            log_message('error', "[WARN] JWT_lib::validate() — 'none' algorithm rejected.");
            return FALSE;
        }
        if ($alg !== strtolower(self::ALGORITHM)) return FALSE;

        if ( ! hash_equals($this->_sign($h64 . '.' . $p64), $s64)) return FALSE;

        $payload = $this->_decode_json($p64);
        if ($payload === FALSE || ! isset($payload->exp)) return FALSE;

        if ((int) $payload->exp < (time() - $this->leeway)) return FALSE;

        return $payload;
    }

    private function _sign(string $data): string
    {
        return $this->_b64(hash_hmac(self::HASH_ALGO, $data, $this->secret, TRUE));
    }

    private function _b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function _decode_json(string $segment)
    {
        $padded = strtr($segment, '-_', '+/');
        $rem    = strlen($padded) % 4;
        if ($rem) $padded .= str_repeat('=', 4 - $rem);

        $raw = base64_decode($padded, TRUE);
        if ($raw === FALSE) return FALSE;

        $decoded = json_decode($raw);
        return (json_last_error() === JSON_ERROR_NONE && is_object($decoded)) ? $decoded : FALSE;
    }
}
