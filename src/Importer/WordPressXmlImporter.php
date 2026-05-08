<?php
namespace UrlImageImporter\Importer;

class WordPressXmlImporter {
    
    /**
     * Process WordPress XML import
     *
     * @param string $xml_file_path Path to the XML file
     * @param array $options Import options
     * @return array Results of the import
     */
    public function process_xml_import($xml_file_path, $options = []) {
        $results = [
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0,
            'messages' => []
        ];

        if (!file_exists($xml_file_path)) {
            $results['messages'][] = 'XML file not found: ' . $xml_file_path;
            return $results;
        }

        // Load XML
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($xml_file_path);
        
        if ($xml === false) {
            $results['messages'][] = 'Failed to parse XML file. Please ensure it\'s a valid WordPress export file.';
            return $results;
        }

        // Register namespaces
        $xml->registerXPathNamespace('wp', 'http://wordpress.org/export/1.2/');
        $xml->registerXPathNamespace('content', 'http://purl.org/rss/1.0/modules/content/');

        // Find all attachment items in the XML
        $attachments = $xml->xpath('//item[wp:post_type="attachment"]');
        
        if (empty($attachments)) {
            $results['messages'][] = 'No attachments found in the XML file.';
            return $results;
        }

        foreach ($attachments as $attachment) {
            $this->import_attachment($attachment, $options, $results);
        }

        return $results;
    }

    /**
     * Import a single attachment from XML
     */
    private function import_attachment($attachment, $options, &$results) {
        try {
            // Extract attachment data
            $title = (string) $attachment->title;
            $guid = (string) $attachment->guid;
            $description = (string) $attachment->description;
            $pub_date = (string) $attachment->pubDate;
            
            // Get attachment URL from wp:attachment_url or guid
            $attachment_url = '';
            if (isset($attachment->children('wp', true)->attachment_url)) {
                $attachment_url = (string) $attachment->children('wp', true)->attachment_url;
            } else {
                $attachment_url = $guid;
            }

            // Skip if not an image URL (optional filter)
            if (!empty($options['images_only']) && !$this->is_image_url($attachment_url)) {
                $results['skipped']++;
                return;
            }

            // Check if already exists by filename
            $filename = basename(parse_url($attachment_url, PHP_URL_PATH));
            if ($this->attachment_exists($filename) && empty($options['force_reimport'])) {
                $results['skipped']++;
                $results['messages'][] = "Skipped existing file: $filename";
                return;
            }

            // Import the image
            $strip_extension = !array_key_exists('strip_extension', $options) || (bool) $options['strip_extension'];

            $import_result = $this->import_image_from_url($attachment_url, [
                'title' => $title,
                'description' => $description,
                'date' => $pub_date
            ], $strip_extension);

            if (is_wp_error($import_result)) {
                $results['errors']++;
                $results['messages'][] = "Failed to import $filename: " . $import_result->get_error_message();
            } else {
                $results['imported']++;
                $results['messages'][] = "Successfully imported: $filename";
            }

        } catch (\Exception $e) {
            $results['errors']++;
            $results['messages'][] = "Error processing attachment: " . $e->getMessage();
        }
    }

    /**
     * Check if URL is an image
     */
    private function is_image_url($url) {
        $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'tiff', 'ico'];
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        return in_array($extension, $image_extensions);
    }

    /**
     * Check if attachment already exists
     */
    private function attachment_exists($filename) {
        global $wpdb;
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid LIKE %s",
            '%' . $wpdb->esc_like($filename)
        ));
        return !empty($result);
    }

    /**
     * Import image from URL with metadata
     */
    private function import_image_from_url($image_url, $metadata = [], $strip_extension = true) {
        // Use the existing function from the main plugin
        if (function_exists('uimptr_import_image_from_url')) {
            $attachment_id = uimptr_import_image_from_url($image_url, null, $metadata, false, $strip_extension);
            
            if (!is_wp_error($attachment_id) && !empty($metadata)) {
                // Update attachment metadata
                if (!empty($metadata['title'])) {
                    wp_update_post([
                        'ID' => $attachment_id,
                        'post_title' => sanitize_text_field($metadata['title'])
                    ]);
                }
                
                if (!empty($metadata['description'])) {
                    wp_update_post([
                        'ID' => $attachment_id,
                        'post_content' => sanitize_textarea_field($metadata['description'])
                    ]);
                }
                
                if (!empty($metadata['date'])) {
                    wp_update_post([
                        'ID' => $attachment_id,
                        'post_date' => date('Y-m-d H:i:s', strtotime($metadata['date'])),
                        'post_date_gmt' => gmdate('Y-m-d H:i:s', strtotime($metadata['date']))
                    ]);
                }
            }
            
            return $attachment_id;
        }
        
        return new \WP_Error('function_missing', 'Image import function not available');
    }
}
