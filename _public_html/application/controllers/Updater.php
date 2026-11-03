<?php
if (!defined('BASEPATH'))
    exit('No direct script access allowed');
/**
 * OVOO-Live TV & Movie Portal CMS with Unlimited TV-Series
 * ---------------------- OVOO --------------------
 * ------- Live TV & Movie Portal CMS with Unlimited TV-Series --------
 * - Professional live tv and movie management system -
 *
 * @package     OVOO-Movie & Video Stremaing CMS Pro
 * @author      Abdul Mannan/SpaGreen Creative
 * @copyright   Copyright (c) 2014 - 2019 SpaGreen,
 * @license     http://codecanyon.net/wiki/support/legal-terms/licensing-terms/ 
 * @link        http://www.spagreen.net
 * @link        support@spagreen.net
 *
 **/
class Updater extends CI_Controller{

    function __construct(){
        parent::__construct();
        $this->load->library('session');
        $this->load->database();
    }

    //default index function, redirects to login/dashboard 
    public function index(){
        if ($this->session->userdata('admin_is_login') != 1)
            redirect(base_url() . 'login', 'refresh');
        if ($this->session->userdata('admin_is_login') == 1)
            redirect(base_url() . 'admin/dashboard', 'refresh');
    }
    // updater function

    function process($action = '')
    {
        ini_set('max_execution_time', 300); //300 seconds 
        if ($this->session->userdata('admin_is_login') != 1)
            redirect(base_url(), 'refresh');

        // Installing an archive writes PHP into the web root, so the updater is
        // off unless an operator explicitly enables it for a controlled upgrade.
        if (!env_bool('ALLOW_WEB_UPDATER', false)) {
            $this->output->set_status_header(403);
            exit('The web updater is disabled. Deploy updates through the release process.');
        }

        if (!isset($_FILES['zip_file'])
            || !is_array($_FILES['zip_file'])
            || ($_FILES['zip_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file($_FILES['zip_file']['tmp_name'])
            || strtolower(pathinfo((string) $_FILES['zip_file']['name'], PATHINFO_EXTENSION)) !== 'zip') {
            $this->session->set_flashdata('error', 'Please upload a valid .zip update archive.');
            redirect(base_url() . 'admin/update/', 'refresh');
        }

        // create directory if not exist.
        $update_dir = rtrim(FCPATH, '/\\') . '/update';
        if (!is_dir($update_dir) && !mkdir($update_dir, 0755, true) && !is_dir($update_dir)) {
            $this->session->set_flashdata('error', 'The update directory could not be created.');
            redirect(base_url() . 'admin/update/', 'refresh');
        }

        $zip_file_name = basename((string) $_FILES['zip_file']['name']);
        $path = $update_dir . '/' . $zip_file_name;
        if (!move_uploaded_file($_FILES['zip_file']['tmp_name'], $path)) {
            $this->session->set_flashdata('error', 'The uploaded archive could not be stored.');
            redirect(base_url() . 'admin/update/', 'refresh');
        }

        // Validate traversal, symlinks, size bombs and blocked file types
        // before anything is written to disk.
        $this->load->library('safe_zip');
        if (!$this->safe_zip->extract($path, $update_dir, true)) {
            @unlink($path);
            $this->session->set_flashdata('error', 'The update archive was rejected as unsafe.');
            redirect(base_url() . 'admin/update/', 'refresh');
        }
        @unlink($path);

        $unzip_file_name = substr($zip_file_name, 0, -4);
        $package_dir = $update_dir . '/' . $unzip_file_name;

        // update database (admin-supplied SQL; the updater is already opt-in)
        $host           =     $this->db->hostname;
        $dbuser         =     $this->db->username;
        $dbpassword     =     $this->db->password;
        $dbname         =     $this->db->database;

        $sql_file = $package_dir . '/database.sql';
        try {
            $mysqli = @new mysqli($host, $dbuser, $dbpassword, $dbname);
        } catch (Throwable $e) {
            $mysqli = null;
            log_message('error', 'updater: database connection failed: ' . $e->getMessage());
        }

        if ($mysqli instanceof mysqli && (int) $mysqli->connect_errno === 0 && is_file($sql_file)) {
            $sql = file_get_contents($sql_file);

            $mysqli->multi_query($sql);
            do {

            } while (mysqli_more_results($mysqli) && mysqli_next_result($mysqli));
            $mysqli->close();

            if ($this->db->table_exists('temp_config')) {
                $configs = $this->db->get('temp_config')->result_array();
                foreach($configs as $config):
                    $data['title'] = trim($config['title']);
                    $data['value'] = trim($config['value']);

                    $this->db->where('title',trim($config['title']));
                    $query = $this->db->get('config');
                    if($query->num_rows() == 0):
                        $this->db->insert('config',$data);
                    endif;
                    if($query->num_rows() > 1):
                        $this->db->reset_query();
                        $this->db->where('title',$config['title']);
                        $this->db->delete('config');
                        $this->db->reset_query();
                        $this->db->insert('config',$data);
                    endif;
                endforeach;
                // delete temp table
                $this->load->dbforge();
                $this->dbforge->drop_table('temp_config',TRUE);
            }
        } else {
            if ($mysqli instanceof mysqli) {
                $mysqli->close();
            }
        }

        // get json_content
        $json_file = $package_dir . '/config.json';
        $converted_json = is_file($json_file)
            ? json_decode((string) file_get_contents($json_file), true)
            : null;
        if (!is_array($converted_json)) {
            $converted_json = array();
        }

        // NOTE: the previous version executed ./update/<package>/php_update.php
        // directly. That allowed arbitrary code execution from an uploaded
        // archive and has been removed; code is applied via the files list.

        // Create directories if they do not exist, confined to the app root.
        if (!empty($converted_json['directories'])) {
            foreach ($converted_json['directories'] as $dir) {
                $target = $this->_resolve_target((string) ($dir['title'] ?? ''));
                if ($target !== null && !is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            }
        }

        // Copy/replace files, but only from inside the extracted package and
        // only to a path inside the application root.
        if (!empty($converted_json['files'])) {
            foreach ($converted_json['files'] as $files):
                $from = realpath((string) ($files['from_dir'] ?? ''));
                $to   = $this->_resolve_target((string) ($files['to_dir'] ?? ''));
                if ($from === false || $to === null || !$this->_path_within($from, $package_dir)) {
                    continue;
                }
                if (!is_file($from)) {
                    continue;
                }
                $to_dir = dirname($to);
                if (!is_dir($to_dir) && !mkdir($to_dir, 0755, true) && !is_dir($to_dir)) {
                    continue;
                }
                copy($from, $to);
                @unlink($from);
            endforeach;
        }
        $this->deleteDir($update_dir);
        // redirect after ompleted
        $this->session->set_flashdata('success', "Update successfully completed.");
        redirect(base_url() . 'admin/update/', 'refresh');
    }

    /**
     * Resolve a package-relative target inside the application root, or null
     * when it is absolute or escapes through "..".
     */
    private function _resolve_target(string $relative): ?string
    {
        $relative = str_replace('\\', '/', trim($relative));
        if ($relative === '' || $relative[0] === '/' || preg_match('#^[A-Za-z]:/#', $relative) === 1) {
            return null;
        }
        if (in_array('..', explode('/', $relative), true)) {
            return null;
        }
        return rtrim(FCPATH, '/\\') . '/' . ltrim($relative, '/');
    }

    /**
     * True when $path is inside $root (both must exist and resolve).
     */
    private function _path_within(string $path, string $root): bool
    {
        $real_root = realpath($root);
        $real_path = realpath($path);
        if ($real_root === false || $real_path === false) {
            return false;
        }
        return str_starts_with($real_path, rtrim($real_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }

    public function deleteDir($dirPath =FCPATH.'update') {
        if ($this->session->userdata('admin_is_login') != 1)
            redirect(base_url(), 'refresh');

        if (! is_dir($dirPath)) {
            throw new InvalidArgumentException("$dirPath must be a directory");
        }
        if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') {
            $dirPath .= '/';
        }
        $files = glob($dirPath . '*', GLOB_MARK);
        foreach ($files as $file) {
            if (is_dir($file)) {
                self::deleteDir($file);
            } else {
                unlink($file);
            }
        }
        rmdir($dirPath);
    }

}
