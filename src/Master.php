<?php


require_once  __DIR__ . '/client.php'; 
require_once  __DIR__ . '/media.php'; 

class Master
{
    var $client;
    
    var $manager;

    function __construct($client = null, $manager = null)
    {
        if ($client) {
            $this->client = $client;
        } else {
            $this->client = defined("WP_TESTS_DOMAIN") ? new xmlrpc_test_client() : new xmlrpc_client();
        }
        
        if ($manager) {
            $this->manager = $manager;
        } else {
            $this->manager = new NoUploadMediaManager($this->client);
        }
    }

    public function push($post, $site)
    {
        $mapping = get_post_meta($post->ID, MAPPING_META_KEY, true);
        if (isset($mapping[$site])) {
            $content = array();
            $content['post_title'] = $post->post_title;
            $content['post_content'] = $post->post_content;
            $content['post_excerpt'] = $post->post_excerpt;
            $content['post_status'] = $post->post_status;
        } else {
            $content = get_object_vars($post);
            unset($content['post_author']);
        }
        
        $content['terms_names'] = $this->get_terms_names($post);
        
        $thumbnail_id = get_post_thumbnail_id($post->ID);
        if ($thumbnail_id) {
            $remote_thumb_id = $this->manager->get_or_push_thumbnail($thumbnail_id, $site);
            $content['post_thumbnail'] = $remote_thumb_id;
        }
        
        $content['post_content'] = $this->filter_gallery_shortcode($content['post_content'], $site);
        
        $content = apply_filters('push_content', $content, $post, $site);
        if (isset($mapping[$site])) {
            $remote_id = $mapping[$site];
            $this->client->xmlrpc_edit_post($site, $remote_id, $content);
        } else {
            $remote_id = $this->client->xmlrpc_new_post($site, $content);
            $mapping[$site] = $remote_id;
            $mapping = apply_filters('update_mapping', $mapping, $post, $site);
            update_post_meta($post->ID, MAPPING_META_KEY, $mapping);
        }
        return $remote_id;
    }

    private function filter_gallery_shortcode($post_content, $site)
    {
        $gallery_shortcode_regex = '/\[gallery ids="([\d,]+)"\]/';
        if (preg_match($gallery_shortcode_regex, $post_content, $matches)) {
            $images = preg_split('/,/', $matches[1]);
            $remote_images = array();
            foreach ($images as $gallery_thumbnail_id) {
                $remote_images[] = $this->manager->get_or_push_thumbnail($gallery_thumbnail_id, $site);
            }
            $ids = implode(',', $remote_images);
            $replacement = "[gallery ids=\"$ids\"]";
            $post_content = preg_replace($gallery_shortcode_regex, $replacement, $post_content);
        }
        return $post_content;
    }

    /**
     */
    private function get_terms_names($post)
    {
        $terms_names = array();
        $tax_list = get_object_taxonomies($post->post_type);
        foreach ($tax_list as $tax) {
            $terms_names[$tax] = wp_get_post_terms($post->ID, $tax, array(
                "fields" => "names"
            ));
        }
        return $terms_names;
    }

    /**
     */
    public function update_custom_fields($site, $id, $remote_id)
    {
        $custom_fields = $this->get_custom_fields($site, $id, $remote_id);
        if ($custom_fields) {
            $this->client->xmlrpc_edit_post($site, $remote_id, array(
                'custom_fields' => $custom_fields
            ));
        }
    }

    private function get_custom_fields($site, $id, $remote_id)
    {
        $custom_fields = array();
        $local_server = new local_server();
        $local = $local_server->get_local_custom_fields($id);
        $local = array_filter($local, array($this, 'skip_mapping'));
        $local = array_filter($local, array($this, 'skip_private_keys'));
        $local = apply_filters('sync_custom_fields', $local, $site);
        
        if (empty($local))
            return false;
        
        $remote = $this->client->get_remote_custom_fields($site, $remote_id);
        
        foreach ($local as $item) {
            unset($item['id']);
            $index = $this->search_field_in_array($item['key'], $remote);
            if (isset($remote[$index])) {
                $remote_item = $remote[$index];
                if ($item['value'] != $remote_item['value']) {
                    $custom_fields[] = array_merge($remote_item, $item);
                }
            } else {
                $custom_fields[] = $item;
            }
        }
        return $custom_fields;
    }
    
    private function skip_mapping($item)
    {
        return $item['key'] != MAPPING_META_KEY;
    }
    
    private function skip_private_keys($item) {
        $meta_key = $item['key'];
        return $meta_key == '_wp_attachment_metadata' || substr($item['key'], 0, 1) != "_";
    }

    private function item_to_update($item, $remote_item)
    {
        if ($item['value'] != $remote_item['value']) {
            return array_merge($remote_item, $item);
        }
    }

    private function search_field_in_array($meta_key, $remote)
    {
        foreach ($remote as $index => $remote_item) {
            if ($remote_item['key'] == $meta_key) {
                return $index;
            }
        }
        return - 1;
    }

    private function entry_enabled($entry)
    {
        return isset($entry['enabled']) && $entry['enabled'];
    }

    private function get_entry_name($entry)
    {
        return $entry['name'];
    }

    function get_site_list($post)
    {
        $site_opt = get_option(SITE_OPT);
        $post_opt = get_option(POST_OPT);
        $destinations = array_filter($site_opt, array(
            $this,
            'entry_enabled'
        ));
        $sites = array_map(array(
            $this,
            'get_entry_name'
        ), $destinations);
        $target_sites = array_keys(array_filter($post_opt[$post->post_type]));
        $target_sites = apply_filters('target_sites', $target_sites, $post);
        return array_intersect($target_sites, $sites);
    }

    function publish_post($ID, $post)
    {
        $site_list = $this->get_site_list($post);
        foreach ($site_list as $site) {
            $remote_id = $this->push($post, $site);
            $this->update_custom_fields($site, $ID, $remote_id);
        }
    }

    function update_post_meta($meta_id, $post_id)
    {
        $mapping = get_post_meta($post_id, MAPPING_META_KEY, true);
        if ($mapping) {
            foreach ($mapping as $site => $remote_id) {
                $this->update_custom_fields($site, $post_id, $remote_id);
            }
        }
    }
}


class local_server extends wp_xmlrpc_server
{

    function get_local_custom_fields($id)
    {
        $fields = array(
            'custom_fields'
        );
        $post = get_post($id, ARRAY_A);
        $local = $this->_prepare_post($post, $fields);
        return $local['custom_fields'];
    }
    
}

