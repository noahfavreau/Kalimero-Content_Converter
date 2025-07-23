<?php
/**
 * Bulk WordPress Content Converter
 *
 * This script provides administrators with a tool to bulk convert posts from the
 * legacy Classic Editor format to the modern Gutenberg block structure. It is
 * designed to be executed manually and includes several safety mechanisms to
 * ensure a smooth and safe migration process.
 *
 * @author Noah Favreau
 */

// Increase execution time and memory for bulk processing
function prepare_bulk_conversion()
{
    if (isset($_GET['run_content_converter']) && current_user_can('administrator')) {
        ini_set('max_execution_time', 0); // No time limit
        ini_set('memory_limit', '512M');  // Increase memory
        set_time_limit(0);
    }
}
add_action('init', 'prepare_bulk_conversion', 1);

// Main conversion function
function bulk_convert_all_content()
{
    // Security check - only admins can run this
    if (!isset($_GET['run_content_converter']) || !current_user_can('administrator')) {
        return;
    }

    // Start output buffering for real-time feedback
    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    echo '<html><head><title>Content Converter</title>';
    echo '<style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f1f1f1; }
        .container { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .success { color: #46b450; }
        .error { color: #dc3232; }
        .info { color: #0073aa; }
        .warning { color: #f56e28; }
        .progress { background: #0073aa; color: white; padding: 10px; margin: 10px 0; border-radius: 4px; }
        .post-item { padding: 8px 0; border-bottom: 1px solid #eee; }
        .counter { font-weight: bold; font-size: 18px; margin: 20px 0; }
        .debug { background: #f9f9f9; padding: 10px; margin: 10px 0; border-left: 4px solid #0073aa; font-size: 12px; }
        .converted { background: #e8f5e8; padding: 10px; margin: 10px 0; border-left: 4px solid #46b450; font-size: 12px; }
    </style></head><body>';

    echo '<div class="container">';
    echo '<h1>Bulk Content Converter</h1>';
    echo '<p class="info">Processing all posts with 5-second delays between each conversion...</p>';

    // Flush output to browser
    ob_flush();
    flush();

    $post_types = array('image', 'video', 'document');
    $total_converted = 0;
    $total_skipped = 0;
    $total_errors = 0;

    foreach ($post_types as $post_type) {
        echo "<div class='progress'>Processing Post Type: <strong>{$post_type}</strong></div>";
        ob_flush();
        flush();

        // Get ALL posts of this type
        $posts = get_posts(array(
            'post_type' => $post_type,
            'post_status' => array('publish', 'draft', 'private'),
            'numberposts' => -1, // Get ALL posts
            'orderby' => 'ID',
            'order' => 'ASC'
        ));

        echo "<p class='info'>Found " . count($posts) . " posts to process</p>";
        ob_flush();
        flush();

        if (empty($posts)) {
            echo "<p>No posts found for type: {$post_type}</p>";
            continue;
        }

        foreach ($posts as $index => $post) {
            $post_number = $index + 1;
            $total_posts = count($posts);

            echo "<div class='post-item'>";
            echo "<strong>Post {$post_number}/{$total_posts}:</strong> ";
            echo "ID {$post->ID} - \"{$post->post_title}\" ";

            // Get the original content
            $original_content = $post->post_content;

            // Skip if empty content
            if (empty(trim($original_content))) {
                echo "<span class='info'>Skipped (empty content)</span>";
                $total_skipped++;
                echo "</div>";
                ob_flush();
                flush();
                continue;
            }

            // Skip if already has Gutenberg blocks
            if (strpos($original_content, '<!-- wp:') !== false) {
                echo "<span class='info'>Skipped (already has blocks)</span>";
                $total_skipped++;
                echo "</div>";
                ob_flush();
                flush();
                continue;
            }

            try {
                // Process the content in the correct order
                $new_content = $original_content;

                // Step 1: Fix bullet points FIRST (before BR conversion)
                $new_content = fix_bullet_points_with_br_tags($new_content);

                // Step 2: Handle remaining <br> tags for regular paragraphs
                $new_content = convert_remaining_br_to_paragraphs($new_content);

                // Step 3: Clean up empty paragraphs
                $new_content = remove_empty_paragraphs($new_content);

                // Step 4: Convert to Gutenberg blocks
                $new_content = convert_to_gutenberg_blocks($new_content);

                // Step 5: Update the post
                $result = wp_update_post(array(
                    'ID' => $post->ID,
                    'post_content' => $new_content
                ), true); // Return WP_Error on failure

                if (is_wp_error($result)) {
                    echo "<span class='error'>Failed: " . $result->get_error_message() . "</span>";
                    $total_errors++;
                } else {
                    echo "<span class='success'>Converted successfully</span>";
                    $total_converted++;
                }

            } catch (Exception $e) {
                echo "<span class='error'>Error: " . $e->getMessage() . "</span>";
                $total_errors++;
            }

            echo "</div>";
            ob_flush();
            flush();

            // 5-second delay between posts (except for the last one)
            if ($post_number < $total_posts) {
                echo "<p class='info'>Waiting 5 seconds before next post...</p>";
                ob_flush();
                flush();
                sleep(5);
            }
        }

        echo "<hr>";
        ob_flush();
        flush();
    }

    // Final summary
    echo "<div class='counter'>";
    echo "<h2>Conversion Complete!</h2>";
    echo "<p><strong>Successfully converted:</strong> {$total_converted} posts</p>";
    echo "<p><strong>Skipped:</strong> {$total_skipped} posts</p>";
    echo "<p><strong>Errors:</strong> {$total_errors} posts</p>";
    echo "<p><strong>Total processed:</strong> " . ($total_converted + $total_skipped + $total_errors) . " posts</p>";
    echo "</div>";

    echo '<p><a href="' . admin_url() . '">← Back to Dashboard</a></p>';
    echo '</div></body></html>';

    ob_flush();
    flush();
    exit; // Stop execution
}
add_action('init', 'bulk_convert_all_content', 10);

// Function to handle bullet points with <br> tags
function fix_bullet_points_with_br_tags($content)
{
    if (empty($content)) {
        return $content;
    }

    echo "<div class='debug'>";
    echo "<strong>DEBUG - Before bullet point conversion:</strong><br>";
    echo "<pre>" . htmlspecialchars(substr($content, 0, 400)) . (strlen($content) > 400 ? '...' : '') . "</pre>";
    echo "</div>";
    ob_flush();
    flush();

    // Process paragraph by paragraph
    $content = preg_replace_callback('/<p([^>]*)>(.*?)<\/p>/is', function ($matches) {
        $p_attributes = $matches[1];
        $p_content = $matches[2];

        // Check if this paragraph contains bullet points with <br> tags
        if (preg_match('/[•·▪▫‣⁃\*\-\+→➤➢]\s*.*?<br/ui', $p_content)) {

            // Split by <br> tags first
            $parts = preg_split('/<br\s*\/?>/i', $p_content);

            $list_items = array();
            $regular_parts = array();
            $current_list = array();
            $in_list = false;

            foreach ($parts as $part) {
                $part = trim($part);
                if (empty($part))
                    continue;

                // Check if this part is a bullet point
                if (preg_match('/^[•·▪▫‣⁃\*\-\+→➤➢]\s*(.+)$/u', $part, $bullet_matches)) {
                    if (!$in_list) {
                        // Save any regular content before starting list
                        if (!empty($regular_parts)) {
                            // Join regular parts and wrap in paragraph if needed
                            $regular_content = implode('<br>', $regular_parts);
                            if (!empty(trim($regular_content))) {
                                $list_items[] = '<p' . $p_attributes . '>' . $regular_content . '</p>';
                            }
                            $regular_parts = array();
                        }
                        $in_list = true;
                        $current_list = array();
                    }
                    $current_list[] = trim($bullet_matches[1]);
                } else {
                    if ($in_list) {
                        // End the current list
                        if (!empty($current_list)) {
                            $list_html = "<ul>\n";
                            foreach ($current_list as $item) {
                                $list_html .= "<li>" . $item . "</li>\n";
                            }
                            $list_html .= "</ul>";
                            $list_items[] = $list_html;
                            $current_list = array();
                        }
                        $in_list = false;
                    }
                    $regular_parts[] = $part;
                }
            }

            // Handle end of paragraph
            if ($in_list && !empty($current_list)) {
                $list_html = "<ul>\n";
                foreach ($current_list as $item) {
                    $list_html .= "<li>" . $item . "</li>\n";
                }
                $list_html .= "</ul>";
                $list_items[] = $list_html;
            } elseif (!empty($regular_parts)) {
                $regular_content = implode('<br>', $regular_parts);
                if (!empty(trim($regular_content))) {
                    $list_items[] = '<p' . $p_attributes . '>' . $regular_content . '</p>';
                }
            }

            // Return the processed content
            return implode("\n\n", $list_items);
        }

        // No bullet points found, return original paragraph
        return '<p' . $p_attributes . '>' . $p_content . '</p>';

    }, $content);

    echo "<div class='converted'>";
    echo "<strong>DEBUG - After bullet point conversion:</strong><br>";
    echo "<pre>" . htmlspecialchars(substr($content, 0, 400)) . (strlen($content) > 400 ? '...' : '') . "</pre>";
    echo "</div>";
    ob_flush();
    flush();

    return $content;
}

// Function to handle remaining <br> tags (for non-bullet content)
function convert_remaining_br_to_paragraphs($content)
{
    if (empty($content)) {
        return $content;
    }

    echo "<div class='debug'>";
    echo "<strong>DEBUG - Before remaining BR conversion:</strong><br>";
    echo "<pre>" . htmlspecialchars(substr($content, 0, 300)) . (strlen($content) > 300 ? '...' : '') . "</pre>";
    echo "</div>";
    ob_flush();
    flush();

    // Only process paragraphs that still contain <br> tags and are not lists
    $content = preg_replace_callback('/<p([^>]*)>(.*?)<\/p>/is', function ($matches) {
        $p_attributes = $matches[1];
        $p_content = $matches[2];

        // Skip if this doesn't contain <br> tags
        if (strpos($p_content, '<br') === false) {
            return '<p' . $p_attributes . '>' . $p_content . '</p>';
        }

        // Skip if this looks like it might be a processed list
        if (preg_match('/[•·▪▫‣⁃\*\-\+→➤➢]/u', $p_content)) {
            return '<p' . $p_attributes . '>' . $p_content . '</p>';
        }

        // Split by <br> tags
        $parts = preg_split('/<br\s*\/?>/i', $p_content);

        // Filter out empty parts
        $parts = array_filter(array_map('trim', $parts), function ($part) {
            return !empty($part);
        });

        if (count($parts) > 1) {
            // Convert each part to its own paragraph
            $new_paragraphs = array();
            foreach ($parts as $part) {
                if (!empty(trim($part))) {
                    $new_paragraphs[] = '<p' . $p_attributes . '>' . trim($part) . '</p>';
                }
            }
            return implode("\n\n", $new_paragraphs);
        }

        // Only one part or no changes needed
        return '<p' . $p_attributes . '>' . $p_content . '</p>';

    }, $content);

    echo "<div class='converted'>";
    echo "<strong>DEBUG - After remaining BR conversion:</strong><br>";
    echo "<pre>" . htmlspecialchars(substr($content, 0, 400)) . (strlen($content) > 400 ? '...' : '') . "</pre>";
    echo "</div>";
    ob_flush();
    flush();

    return $content;
}

// Enhanced empty paragraph removal
function remove_empty_paragraphs($content)
{
    if (empty($content)) {
        return $content;
    }

    echo "<div class='debug'>";
    echo "<strong>DEBUG - Before empty paragraph cleanup:</strong><br>";
    echo "<pre>" . htmlspecialchars(substr($content, 0, 400)) . (strlen($content) > 400 ? '...' : '') . "</pre>";
    echo "</div>";
    ob_flush();
    flush();

    $original_content = $content;

    // Enhanced patterns for empty paragraph removal
    $empty_paragraph_patterns = array(
        // Basic empty paragraphs
        '/<p[^>]*>\s*<\/p>/i',

        // Paragraphs with only whitespace characters
        '/<p[^>]*>[\s\r\n\t]*<\/p>/i',

        // Paragraphs with only &nbsp; variations
        '/<p[^>]*>&nbsp;<\/p>/i',
        '/<p[^>]*>\s*&nbsp;\s*<\/p>/i',
        '/<p[^>]*>(&nbsp;\s*)+<\/p>/i',

        // HTML entities for spaces
        '/<p[^>]*>(\s|&nbsp;|&#160;|&#xA0;|\r|\n|\t)+<\/p>/i',

        // Complex combinations with <br> tags
        '/<p[^>]*>(<br\s*\/?>|\s|&nbsp;|&#160;|&#xA0;|\r|\n|\t)+<\/p>/i',

        // Multiple consecutive empty patterns
        '/<p[^>]*><\/p>\s*<p[^>]*><\/p>/i',
    );

    $total_removed = 0;
    foreach ($empty_paragraph_patterns as $pattern) {
        $before_count = substr_count($content, '<p');
        $content = preg_replace($pattern, '', $content);
        $after_count = substr_count($content, '<p');

        $removed_this_round = $before_count - $after_count;
        if ($removed_this_round > 0) {
            $total_removed += $removed_this_round;
            echo "<div class='info'>Removed {$removed_this_round} empty paragraphs</div>";
            ob_flush();
            flush();
        }
    }

    // Clean up multiple consecutive line breaks
    $content = preg_replace('/\n{3,}/', "\n\n", $content);

    // Trim whitespace
    $content = trim($content);

    if ($total_removed > 0) {
        echo "<div class='success'>Total empty paragraphs removed: {$total_removed}</div>";
        echo "<div class='converted'>";
        echo "<strong>DEBUG - After cleanup:</strong><br>";
        echo "<pre>" . htmlspecialchars(substr($content, 0, 400)) . (strlen($content) > 400 ? '...' : '') . "</pre>";
        echo "</div>";
        ob_flush();
        flush();
    } else {
        echo "<div class='info'>No empty paragraphs found to remove</div>";
        ob_flush();
        flush();
    }

    return $content;
}

// Improved Gutenberg block conversion
function convert_to_gutenberg_blocks($content)
{
    if (empty($content)) {
        return $content;
    }

    echo "<div class='debug'>";
    echo "<strong>DEBUG - Before Gutenberg conversion:</strong><br>";
    echo "<pre>" . htmlspecialchars(substr($content, 0, 500)) . (strlen($content) > 500 ? '...' : '') . "</pre>";
    echo "</div>";
    ob_flush();
    flush();

    $blocks = array();

    // Split content by double newlines to separate elements
    $elements = preg_split('/\n\s*\n/', $content);

    foreach ($elements as $element) {
        $element = trim($element);
        if (empty($element)) {
            continue;
        }

        // Detect element type and wrap appropriately
        if (preg_match('/^<ul[\s>]/', $element)) {
            // List block
            $blocks[] = "<!-- wp:list -->\n" . $element . "\n<!-- /wp:list -->";

        } elseif (preg_match('/^<h([1-6])[^>]*>/', $element, $matches)) {
            // Heading block
            $level = intval($matches[1]);
            $blocks[] = "<!-- wp:heading {\"level\":{$level}} -->\n" . $element . "\n<!-- /wp:heading -->";

        } elseif (preg_match('/<img[^>]+>/i', $element)) {
            // Image block
            if (strpos($element, '<figure') === false) {
                $element = '<figure class="wp-block-image">' . $element . '</figure>';
            }
            $blocks[] = "<!-- wp:image -->\n" . $element . "\n<!-- /wp:image -->";

        } elseif (preg_match('/^<p[^>]*>/', $element)) {
            // Paragraph block
            $blocks[] = "<!-- wp:paragraph -->\n" . $element . "\n<!-- /wp:paragraph -->";

        } else {
            // Plain text - wrap in paragraph
            if (!empty(trim($element))) {
                $blocks[] = "<!-- wp:paragraph -->\n<p>" . trim($element) . "</p>\n<!-- /wp:paragraph -->";
            }
        }
    }

    $result = implode("\n\n", $blocks);

    echo "<div class='converted'>";
    echo "<strong>DEBUG - Final Gutenberg blocks:</strong><br>";
    echo "<pre>" . htmlspecialchars(substr($result, 0, 500)) . (strlen($result) > 500 ? '...' : '') . "</pre>";
    echo "</div>";
    ob_flush();
    flush();

    return $result;
}

// Add admin notice with the trigger link
function show_converter_admin_notice()
{
    if (current_user_can('administrator') && !isset($_GET['run_content_converter'])) {
        $url = admin_url() . '?run_content_converter=1';
        echo '<div class="notice notice-success is-dismissible">';
        echo '<p><strong>Bulk Content Converter</strong></p>';
        echo '<p>This will process ALL posts.</p>';
        echo '<p><strong>IMPORTANT:</strong> Make sure you have a database backup before proceeding!</p>';
        echo '<p><a href="' . esc_url($url) . '" class="button button-primary" onclick="return confirm(\'WARNING: This will convert ALL your posts with 5-second delays between each. This process may take a long time. Make sure you have a backup! Continue?\')">Start Fixed Bulk Conversion</a></p>';
        echo '</div>';
    }
}
add_action('admin_notices', 'show_converter_admin_notice');
?>
