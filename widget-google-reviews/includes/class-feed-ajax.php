<?php

namespace WP_Rplg_Google_Reviews\Includes;

use WP_Rplg_Google_Reviews\Includes\Core\Core;
use WP_Rplg_Google_Reviews\Includes\Core\Connect_Google;

class Feed_Ajax {

    private $core;
    private $view;
    private $feed_serializer;
    private $feed_deserializer;
    private $connect_google;

    public function __construct(Connect_Google $connect_google, Feed_Serializer $feed_serializer, Feed_Deserializer $feed_deserializer, Core $core, View $view) {
        $this->connect_google = $connect_google;
        $this->feed_serializer = $feed_serializer;
        $this->feed_deserializer = $feed_deserializer;
        $this->core = $core;
        $this->view = $view;

        add_action('wp_ajax_grw_feed_save_ajax', array($this, 'save_ajax'));
        add_action('wp_ajax_grw_get_place', array($this, 'get_place'));
        add_action('wp_ajax_grw_connect', array($this, 'connect'));
    }

    public function save_ajax() {

        $post_id = $this->feed_serializer->save($_POST['post_id'], $_POST['title'], $_POST['content']);

        if (isset($post_id)) {
            $feed = $this->feed_deserializer->get_feed($post_id);

            $data = $this->core->get_reviews($feed, true);
            $businesses = $data['businesses'];
            $reviews = $data['reviews'];
            $options = $data['options'];

            echo $this->view->render($feed->ID, $businesses, $reviews, $options, true);
        }

        wp_die();
    }

    public function get_place() {
        if (current_user_can('manage_options')) {
            if (isset($_POST['grw_nonce']) === false) {
                $error = __('Unable to call request. Make sure you are accessing this page from the Wordpress dashboard.', 'widget-google-reviews');
                $response = compact('error');
            } else {
                check_admin_referer('grw_wpnonce', 'grw_nonce');

                $pid = sanitize_text_field(wp_unslash($_POST['pid']));
                $lang = sanitize_text_field(wp_unslash($_POST['lang']));
                $token = sanitize_text_field(wp_unslash($_POST['token']));

                $google_api_key = get_option('grw_google_api_key');

                if ($google_api_key && strlen($google_api_key) > 0) {
                    $url = $this->api_url($pid, $google_api_key, $lang);
                } else {
                    $url = 'https://app.richplugins.com/gpaw2/get/json?pid=' . $pid . '&token=' . $token .
                           '&siteurl=' . get_option('siteurl') . '&authcode=' . get_option('grw_auth_code');

                    if ($lang && strlen($lang) > 0) {
                        $url = $url . '&lang=' . $lang;
                    }
                }

                $res = wp_remote_get($url);
                $body = wp_remote_retrieve_body($res);
                $body_json = json_decode($body);

                if (!$body_json || !isset($body_json->result)) {
                    $result = $body_json;
                    $status = 'failed';
                } elseif (!isset($body_json->result->rating)) {
                    $error_msg = 'Google place <a href="' . $body_json->result->url . '" target="_blank">which you try to connect</a> ' .
                                 'does not have a rating and reviews, it seems it\'s a street address, not a business locations. ' .
                                 'Please read manual how to find ' .
                                 '<a href="' . admin_url('admin.php?page=grw-support&grw_tab=fig#place_id') . '" target="_blank">right Place ID</a>.';
                    $result = array('error_message' => $error_msg);
                    $status = 'failed';
                } else {
                    $result = $body_json->result;
                    $status = 'success';
                }
                $response = compact('status', 'result');
            }
            header('Content-type: text/json');
            echo json_encode($response);
            wp_die();
        }
    }

    public function connect() {
        if (current_user_can('manage_options')) {
            if (isset($_POST['grw_nonce']) === false) {
                $error = __('Unable to call request. Make sure you are accessing this page from the Wordpress dashboard.', 'widget-google-reviews');
                $response = compact('error');
            } else {
                check_admin_referer('grw_wpnonce', 'grw_nonce');

                $pid = sanitize_text_field(wp_unslash($_POST['pid']));
                $lang = sanitize_text_field(wp_unslash($_POST['lang']));
                $token = sanitize_text_field(wp_unslash($_POST['token']));

                $google_api_key = get_option('grw_google_api_key');

                if ($google_api_key && strlen($google_api_key) > 0) {
                    $url = $this->api_url($pid, $google_api_key, $lang);
                } else {
                    $url = 'https://app.richplugins.com/gpaw2/get/json?pid=' . $pid . '&token=' . $token .
                           '&siteurl=' . get_option('siteurl') . '&authcode=' . get_option('grw_auth_code');

                    if ($lang && strlen($lang) > 0) {
                        $url = $url . '&lang=' . $lang;
                    }
                }

                $res = wp_remote_get($url);
                $body = wp_remote_retrieve_body($res);
                $body_json = json_decode($body);

                if (!$body_json || !isset($body_json->result)) {
                    $result = $body_json;
                    $status = 'failed';
                } elseif (!isset($body_json->result->rating)) {
                    $error_msg = 'Google place <a href="' . $body_json->result->url . '" target="_blank">which you try to connect</a> ' .
                                 'does not have a rating and reviews, it seems it\'s a street address, not a business locations. ' .
                                 'Please read manual how to find ' .
                                 '<a href="' . admin_url('admin.php?page=grw-support&grw_tab=fig#place_id') . '" target="_blank">right Place ID</a>.';
                    $result = array('error_message' => $error_msg);
                    $status = 'failed';
                } else {
                    $this->connect_google->save_reviews($body_json->result, false);
                    $content = json_encode([
                        'connections' => [
                            [
                                'id'        => $body_json->result->place_id,
                                'name'      => $body_json->result->name,
                                'photo'     => strlen($body_json->result->business_photo) ? $body_json->result->business_photo : GRW_GOOGLE_BIZ,
                                'lang'      => $lang,
                                'refresh'   => true,
                                'local_img' => false,
                                'platform'  => 'google'

                            ]
                        ],
                        'options'     => [
                            'view_mode' => 'slider'
                        ]
                    ]);

                    $post_id = $this->feed_serializer->save(null, $body_json->result->name, $content);
                    if (isset($post_id)) {
                        $status = 'success';
                        $result = array('feed_id' => $post_id);
                    }

                    /*if ($_POST['feed_id']) {
                        delete_transient('grw_feed_' . GRW_VERSION . '_' . $_POST['feed_id'] . '_reviews', false);
                    }*/
                }
                $response = compact('status', 'result');
            }
            header('Content-type: text/json');
            echo json_encode($response);
            wp_die();
        }
    }

    function api_url($placeid, $google_api_key, $reviews_lang = '', $reviews_sort = '') {
        $url = GRW_GOOGLE_PLACE_API . 'details/json?placeid=' . $placeid . '&key=' . $google_api_key;
        if (strlen($reviews_lang) > 0) {
            $url = $url . '&language=' . $reviews_lang;
        }
        if (strlen($reviews_sort) > 0) {
            $url = $url . '&reviews_sort=' . $reviews_sort;
        }
        return $url;
    }

}
