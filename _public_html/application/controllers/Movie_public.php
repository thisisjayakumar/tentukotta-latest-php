<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Public (unauthenticated) movie endpoints that used to live in the
 * Admin controller. Moved here so the admin gate can be enforced on
 * Admin_Core_Controller without breaking public site features.
 *
 * URL contract is preserved via routes.php:
 *   $route['admin/rating'] = 'movie_public/rating';
 */
class Movie_public extends Home_Core_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    /**
     * POST /admin/rating
     * Body: rate=<int 0..5>, video_id=<int>
     */
    public function rating()
    {
        $this->output->set_content_type('application/json');

        $rate     = (int) $this->input->post('rate');
        $video_id = (int) $this->input->post('video_id');

        // Validate: rating is 0..5, video must exist and be published.
        if ($rate < 0 || $rate > 5 || $video_id <= 0) {
            echo json_encode([
                'post_status' => 'error',
                'message'     => 'Invalid rating payload',
            ]);
            return;
        }

        $exists = $this->db->get_where('videos', ['videos_id' => $video_id])->num_rows() > 0;
        if (!$exists) {
            echo json_encode([
                'post_status' => 'error',
                'message'     => 'Video not found',
            ]);
            return;
        }

        $post_status = $this->post_rating($rate, $video_id);

        echo json_encode([
            'post_status' => $post_status,
            'rate'        => $rate,
            'video_id'    => $video_id,
        ]);
    }

    /**
     * Record or update a rating for the current IP address.
     *
     * @param int $rate      0..5
     * @param int $video_id  videos.videos_id
     * @return string 'success' | 'error'
     */
    private function post_rating(int $rate, int $video_id): string
    {
        $ip = $this->input->ip_address();

        $verify = [
            'video_id' => $video_id,
            'ip'       => $ip,
        ];

        $data = [
            'video_id' => $video_id,
            'rating'   => $rate,
            'ip'       => $ip,
        ];

        $query = $this->db->get_where('rating', $verify);

        if ($query->num_rows() > 0) {
            $this->db->where($verify);
            return $this->db->update('rating', $data) ? 'success' : 'error';
        }

        if (!$this->db->insert('rating', $data)) {
            return 'error';
        }

        // Only bump the aggregate counter on a *new* rating row.
        $row = $this->db->select('total_rating')
            ->get_where('videos', ['videos_id' => $video_id])
            ->row();

        if ($row !== null) {
            $this->db->where('videos_id', $video_id);
            $this->db->update('videos', ['total_rating' => (int) $row->total_rating + 1]);
        }

        return 'success';
    }
}
