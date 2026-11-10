<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Base controller for the mobile REST APIs (v100 / v130 / legacy).
 *
 * SECURITY-AUDIT.md CRIT-03: the REST surfaces were unauthenticated because
 * `rest_auth = FALSE` in application/config/rest.php. This controller adds a
 * single choke point that every mobile endpoint inherits.
 *
 * Authentication: shared secret stored in the `config` table under
 * `mobile_apps_api_secret_key`. Sent as:
 *   - header `X-API-KEY: <secret>`, or
 *   - request param `api_secret_key=<secret>` (legacy client compatibility).
 *
 * The comparison uses hash_equals() to avoid timing leaks.
 */
require_once(APPPATH . '/libraries/RestController.php');
require_once(APPPATH . '/libraries/Api_key.php');

use chriskacerguis\RestServer\RestController;

class API_Core_Controller extends RestController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->_require_api_key();
    }

    /**
     * Reject the request unless a valid API key is presented.
     */
    protected function _require_api_key(): void
    {
        $supplied = $this->input->get_request_header('X-API-KEY', true);

        if (empty($supplied)) {
            $supplied = $this->input->get_post('api_secret_key', true);
        }

        $expected = $this->_expected_api_key();

        if (!Api_key::verify($expected, $supplied)) {
            $this->response([
                'status'  => 'error',
                'message' => 'Unauthorized',
            ], 401);
            exit;
        }
    }

    /**
     * Read the expected shared secret from the settings table.
     */
    protected function _expected_api_key(): string
    {
        $row = $this->db->select('value')
            ->get_where('config', ['title' => 'mobile_apps_api_secret_key'])
            ->row();

        return ($row && isset($row->value)) ? (string) $row->value : '';
    }

    /**
     * Fields safe to expose for a user record.
     *
     * Never include: password, token, firebase_auth_uid.
     *
     * @param object|array $user
     * @return array
     */
    protected function public_user_fields($user): array
    {
        $user = (array) $user;

        $fields = [
            'user_id',
            'name',
            'email',
            'phone',
            'gender',
            'join_date',
            'last_login',
        ];

        $out = [];
        foreach ($fields as $field) {
            $out[$field] = $user[$field] ?? null;
        }

        return $out;
    }
}
