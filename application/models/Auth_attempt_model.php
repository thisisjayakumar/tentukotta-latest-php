<?php
if (!defined('BASEPATH'))
    exit('No direct script access allowed');

/**
 * Records failed credential checks and answers whether an IP or username
 * has crossed the failure threshold inside the observation window.
 *
 * Every method degrades to a no-op when the login_attempts table has not
 * been created yet, so deploying the code before the migration cannot lock
 * users out with a database error.
 */
class Auth_attempt_model extends CI_Model
{
    const TABLE  = 'login_attempts';
    const MAX    = 5;    // failures allowed inside the window
    const WINDOW = 900;  // 15 minutes
    const KEEP   = 86400; // prune rows older than 24 hours

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    /**
     * True when this IP or username already has MAX failures in WINDOW.
     */
    public function too_many(string $ip, string $username): bool
    {
        if (!$this->db->table_exists(self::TABLE)) {
            return false;
        }

        $since = date('Y-m-d H:i:s', time() - self::WINDOW);
        $count = $this->db
            ->from(self::TABLE)
            ->where('attempted_at >=', $since)
            ->group_start()
                ->where('ip', $ip)
                ->or_where('username', $this->normalise($username))
            ->group_end()
            ->count_all_results();

        return $count >= self::MAX;
    }

    /**
     * Record one failed attempt and prune stale rows.
     */
    public function record(string $ip, string $username): void
    {
        if (!$this->db->table_exists(self::TABLE)) {
            return;
        }

        $this->db
            ->where('attempted_at <', date('Y-m-d H:i:s', time() - self::KEEP))
            ->delete(self::TABLE);

        $this->db->insert(self::TABLE, array(
            'ip'           => substr($ip, 0, 45),
            'username'     => $this->normalise($username),
            'attempted_at' => date('Y-m-d H:i:s'),
        ));
    }

    /**
     * Clear the failures for a successful identity.
     */
    public function clear(string $ip, string $username): void
    {
        if (!$this->db->table_exists(self::TABLE)) {
            return;
        }

        $this->db
            ->where('ip', $ip)
            ->where('username', $this->normalise($username))
            ->delete(self::TABLE);
    }

    private function normalise(string $username): string
    {
        return substr(strtolower(trim($username)), 0, 255);
    }
}
