<?php
/**
 * Bulk WordPress Content Converter
 * Processes ALL posts with 5-second delays between each post
 * Converts classic content to Gutenberg blocks and fixes bullet points
 * NEW: Handles <br> tag conversion to separate paragraphs
 * 
 * SAFETY FEATURES:
 * - Manual trigger only
 * - Administrator access only
 * - 5-second delay between posts
 * - Real-time progress display
 * - Skip already converted posts
 * - Error handling and logging
 */

// Increase execution time and memory for bulk processing
function prepare_bulk_conversion() {
    if (isset($_GET['run_content_converter']) && current_user_can('administrator')) {
        ini_set('max_execution_time', 0); // No time limit
        ini_set('memory_limit', '512M');  // Increase memory
        set_time_limit(0);
    }
}
add_action('init', 'prepare_bulk_conversion', 1);

// Main conversion function
function bulk_convert_all_content() {
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
    echo '<h1>🚀 Bulk Content Converter</h1>';
    echo '<p class="info">Processing all posts with 5-second delays between each conversion...</p>';
    echo '<p class="warning"><strong>NEW:</strong> Now handles &lt;br&gt; tag conversion to separate paragraphs!</p>';
    
    // Flush output to browser
    ob_flush();
    flush();
    
    $post_types = array('image', 'video', 'interactive');
    $total_converted = 0;
    $total_skipped = 0;
    $total_errors = 0;
    
    foreach ($post_types as $post_type) {
        echo "<div class='progress'>📁 Processing Post Type: <strong>{$post_type}</strong></div>";
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
                echo "<span class='info'>⏭️ Skipped (empty content)</span>";
                $total_skipped++;
                echo "</div>";
                ob_flush();
                flush();
                continue;
            }
            
            // Skip if already has Gutenberg blocks
            if (strpos($original_content, '<!-- wp:') !== false) {
                echo "<span class='info'>⏭️ Skipped (already has blocks)</span>";
                $total_skipped++;
                echo "</div>";
                ob_flush();
                flush();
                continue;
            }
            
            try {
                // Process the content
                $new_content = $original_content;
                
                // Step 1: Handle <br> tag conversion to separate paragraphs
                $new_content = convert_br_to_paragraphs($new_content);
                
                // Step 2: Fix bullet points
                $new_content = fix_bullet_points_safe($new_content);
                
                // Step 3: Convert to Gutenberg blocks
                $new_content = convert_to_gutenberg_blocks($new_content);
                
                // Step 4: Update the post
                $result = wp_update_post(array(
                    'ID' => $post->ID,
                    'post_content' => $new_content
                ), true); // Return WP_Error on failure
                
                if (is_wp_error($result)) {
                    echo "<span class='error'>❌ Failed: " . $result->get_error_message() . "</span>";
                    $total_errors++;
                } else {
                    echo "<span class='success'>✅ Converted successfully</span>";
                    $total_converted++;
                }
                
            } catch (Exception $e) {
                echo "<span class='error'>❌ Error: " . $e->getMessage() . "</span>";
                $total_errors++;
            }
            
            echo "</div>";
            ob_flush();
            flush();
            
            // 5-second delay between posts (except for the last one)
            if ($post_number < $total_posts) {
                echo "<p class='info'>⏳ Waiting 5 seconds before next post...</p>";
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
    echo "<h2>🎉 Conversion Complete!</h2>";
    echo "<p><strong>✅ Successfully converted:</strong> {$total_converted} posts</p>";
    echo "<p><strong>⏭️ Skipped:</strong> {$total_skipped} posts</p>";
    echo "<p><strong>❌ Errors:</strong> {$total_errors} posts</p>";
    echo "<p><strong>📊 Total processed:</strong> " . ($total_converted + $total_skipped + $total_errors) . " posts</p>";
    echo "</div>";
    
    echo '<p><a href="' . admin_url() . '">← Back to Dashboard</a></p>';
    echo '</div></body></html>';
    
    ob_flush();
    flush();
    exit; // Stop execution
}
add_action('init', 'bulk_convert_all_content', 10);

// NEW: Function to convert <br> tags to separate paragraphs
function convert_br_to_paragraphs($content) {
    if (empty($content)) {
        return $content;
    }
    
    // Debug: Show original content
    echo "<div class='debug'>";
    echo "<strong>🔍 DEBUG - Before BR conversion:</strong><br>";
    echo "<pre>" . htmlspecialchars(substr($content, 0, 300)) . (strlen($content) > 300 ? '...' : '') . "</pre>";
    echo "</div>";
    ob_flush();
    flush();
    
    // Handle content that's already wrapped in <p> tags
    if (preg_match('/<p[^>]*>.*?<\/p>/is', $content)) {
        // Process each paragraph individually
        $content = preg_replace_callback('/<p([^>]*)>(.*?)<\/p>/is', function($matches) {
            $p_attributes = $matches[1];
            $p_content = $matches[2];
            
            // Check if this paragraph contains <br> tags
            if (strpos($p_content, '<br') !== false) {
                // Split by <br> tags (handle both <br> and <br />)
                $parts = preg_split('/<br\s*\/?>/i', $p_content);
                
                // Filter out empty parts and trim whitespace
                $parts = array_filter(array_map('trim', $parts), function($part) {
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
            }
            
            // Return original if no <br> tags found or only one part
            return '<p' . $p_attributes . '>' . $p_content . '</p>';
        }, $content);
        
    } else {
        // Handle plain text content with <br> tags
        // Split by <br> tags (handle both <br> and <br />)
        $parts = preg_split('/<br\s*\/?>/i', $content);
        
        // Filter out empty parts and trim whitespace
        $parts = array_filter(array_map('trim', $parts), function($part) {
            return !empty($part);
        });
        
        if (count($parts) > 1) {
            // Wrap each part in <p> tags
            $new_paragraphs = array();
            foreach ($parts as $part) {
                if (!empty(trim($part))) {
                    $new_paragraphs[] = '<p>' . trim($part) . '</p>';
                }
            }
            $content = implode("\n\n", $new_paragraphs);
        } else {
            // Single part or no <br> tags - wrap in <p> if not already wrapped
            if (!preg_match('/^\s*<p/i', $content)) {
                $content = '<p>' . trim($content) . '</p>';
            }
        }
    }
    
    // Debug: Show result
    echo "<div class='converted'>";
    echo "<strong>✅ DEBUG - After BR conversion:</strong><br>";
    echo "<pre>" . htmlspecialchars(substr($content, 0, 400)) . (strlen($content) > 400 ? '...' : '') . "</pre>";
    echo "</div>";
    ob_flush();
    flush();
    
    return $content;
}

// Safe bullet point conversion function
function fix_bullet_points_safe($content) {
    if (empty($content)) {
        return $content;
    }
    
    // Split by double line breaks to handle paragraphs
    $paragraphs = preg_split('/\n\s*\n/', $content);
    $result_paragraphs = array();
    
    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph);
        if (empty($paragraph)) {
            continue;
        }
        
        $lines = explode("\n", $paragraph);
        $list_items = array();
        $regular_content = array();
        $in_list = false;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Check for various bullet patterns
            if (preg_match('/^[•·▪▫‣⁃\*\-\+→➤➢]\s*(.+)$/u', $line, $matches)) {
                if (!$in_list) {
                    // Save any regular content before starting list
                    if (!empty($regular_content)) {
                        $result_paragraphs[] = implode("\n", $regular_content);
                        $regular_content = array();
                    }
                    $in_list = true;
                    $list_items = array();
                }
                $list_items[] = trim($matches[1]);
            } else {
                if ($in_list) {
                    // End the list and save it
                    if (!empty($list_items)) {
                        $list_html = "<ul>\n";
                        foreach ($list_items as $item) {
                            $list_html .= "<li>" . $item . "</li>\n";
                        }
                        $list_html .= "</ul>";
                        $result_paragraphs[] = $list_html;
                        $list_items = array();
                    }
                    $in_list = false;
                }
                $regular_content[] = $line;
            }
        }
        
        // Handle end of paragraph
        if ($in_list && !empty($list_items)) {
            $list_html = "<ul>\n";
            foreach ($list_items as $item) {
                $list_html .= "<li>" . $item . "</li>\n";
            }
            $list_html .= "</ul>";
            $result_paragraphs[] = $list_html;
        } elseif (!empty($regular_content)) {
            $result_paragraphs[] = implode("\n", $regular_content);
        }
    }
    
    return implode("\n\n", $result_paragraphs);
}

// Safe Gutenberg block conversion function
function convert_to_gutenberg_blocks($content) {
    if (empty($content)) {
        return $content;
    }
    
    // Debug: Show original content structure
    echo "<div class='debug'>";
    echo "<strong>🔍 DEBUG - Before Gutenberg conversion:</strong><br>";
    echo "<pre>" . htmlspecialchars(substr($content, 0, 500)) . (strlen($content) > 500 ? '...' : '') . "</pre>";
    echo "</div>";
    ob_flush();
    flush();
    
    $blocks = array();
    
    // Method 1: If content has wpautop formatting (WordPress auto paragraphs)
    if (strpos($content, '<p>') !== false) {
        
        // Split content while preserving HTML structure
        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true); // Suppress HTML parsing warnings
        $dom->loadHTML('<?xml encoding="UTF-8">' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        
        foreach ($dom->childNodes as $node) {
            if ($node->nodeType === XML_ELEMENT_NODE) {
                $html = $dom->saveHTML($node);
                
                if ($node->nodeName === 'p') {
                    // Individual paragraph
                    $blocks[] = "<!-- wp:paragraph -->\n" . $html . "\n<!-- /wp:paragraph -->";
                    
                } elseif ($node->nodeName === 'ul') {
                    // List
                    $blocks[] = "<!-- wp:list -->\n" . $html . "\n<!-- /wp:list -->";
                    
                } elseif (preg_match('/^h[1-6]$/', $node->nodeName)) {
                    // Heading
                    $level = intval(substr($node->nodeName, 1));
                    $blocks[] = "<!-- wp:heading {\"level\":{$level}} -->\n" . $html . "\n<!-- /wp:heading -->";
                    
                } elseif ($node->nodeName === 'img') {
                    // Image
                    $blocks[] = "<!-- wp:image -->\n<figure class=\"wp-block-image\">" . $html . "</figure>\n<!-- /wp:image -->";
                    
                } else {
                    // Other HTML elements - wrap in paragraph
                    $blocks[] = "<!-- wp:paragraph -->\n<p>" . $html . "</p>\n<!-- /wp:paragraph -->";
                }
            } elseif ($node->nodeType === XML_TEXT_NODE && trim($node->nodeValue)) {
                // Plain text node
                $blocks[] = "<!-- wp:paragraph -->\n<p>" . trim($node->nodeValue) . "</p>\n<!-- /wp:paragraph -->";
            }
        }
        
    } else {
        // Method 2: Plain text content - split by double line breaks
        $paragraphs = preg_split('/\n\s*\n/', $content);
        
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if (empty($paragraph)) {
                continue;
            }
            
            // Check for lists
            if (preg_match('/^<ul[\s>]/', $paragraph)) {
                $blocks[] = "<!-- wp:list -->\n" . $paragraph . "\n<!-- /wp:list -->";
                
            } elseif (preg_match('/^<h([1-6])[^>]*>/', $paragraph, $matches)) {
                $level = intval($matches[1]);
                $blocks[] = "<!-- wp:heading {\"level\":{$level}} -->\n" . $paragraph . "\n<!-- /wp:heading -->";
                
            } elseif (preg_match('/<img[^>]+>/i', $paragraph)) {
                if (strpos($paragraph, '<figure') === false) {
                    $paragraph = '<figure class="wp-block-image">' . $paragraph . '</figure>';
                }
                $blocks[] = "<!-- wp:image -->\n" . $paragraph . "\n<!-- /wp:image -->";
                
            } else {
                // Plain text paragraph - wrap in <p> if needed
                if (!preg_match('/^\s*<p/i', $paragraph)) {
                    $paragraph = '<p>' . $paragraph . '</p>';
                }
                $blocks[] = "<!-- wp:paragraph -->\n" . $paragraph . "\n<!-- /wp:paragraph -->";
            }
        }
    }
    
    $result = implode("\n\n", $blocks);
    
    // Debug: Show result
    echo "<div class='converted'>";
    echo "<strong>✅ DEBUG - Final Gutenberg blocks:</strong><br>";
    echo "<pre>" . htmlspecialchars(substr($result, 0, 500)) . (strlen($result) > 500 ? '...' : '') . "</pre>";
    echo "</div>";
    ob_flush();
    flush();
    
    return $result;
}

// Add admin notice with the trigger link
function show_converter_admin_notice() {
    if (current_user_can('administrator') && !isset($_GET['run_content_converter'])) {
        $url = admin_url() . '?run_content_converter=1';
        echo '<div class="notice notice-info is-dismissible">';
        echo '<p><strong>🚀 Bulk Content Converter Ready</strong></p>';
        echo '<p>This will process ALL posts in your images, video, and interactives post types.</p>';
        echo '<p><strong>🆕 NEW FEATURE:</strong> Now converts &lt;br&gt; tags to separate paragraphs!</p>';
        echo '<p><strong>⚠️ IMPORTANT:</strong> Make sure you have a database backup before proceeding!</p>';
        echo '<p><a href="' . esc_url($url) . '" class="button button-primary" onclick="return confirm(\'⚠️ WARNING: This will convert ALL your posts with 5-second delays between each. This process may take a long time. Make sure you have a backup! Continue?\')">Start Bulk Conversion</a></p>';
        echo '</div>';
    }
}
add_action('admin_notices', 'show_converter_admin_notice');
?>
