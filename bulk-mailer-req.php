<?php
/**
 * Plugin Name: Force HTML Emails
 * Description: Forces WordPress wp_mail() to send HTML formatted emails.
 * Version: 1.0
 * Author: You
 */

add_filter( 'wp_mail_content_type', function() {
    return 'text/html';
});
