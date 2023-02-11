<?php
/*
  Plugin name:  Formidable QRCode
  Plugin URI: https://github.com/susantohenri/formidable-qrcode
  Description: Generate QR Code on Formidable Form Create and Update Entry
  Author: Henri Susanto
  Author URI: https://github.com/susantohenri/
  Version: 1.0
  Licens: GPL2
 */

define('FORMIDABLE_QRCODE_MAP', [
    [
        'text_field' => 16,
        'file_field' => 17
    ],
    [
        'text_field' => 9,
        'file_field' => 10
    ]
]);
define('FORMIDABLE_QRCODE_SIZE', 3);
define('FORMIDABLE_QRCODE_MARGIN', 4);
define('FORMIDABLE_QRCODE_DIR', '/formidable-qrcode/');

register_activation_hook(__FILE__, function () {
    $upload = wp_upload_dir();
    $upload_dir = $upload['basedir'];
    $upload_dir .= FORMIDABLE_QRCODE_DIR;
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777);
});

add_action('frm_after_create_entry', 'formidable_qrcode_generate', 30, 2);
add_action('frm_after_update_entry', 'formidable_qrcode_generate', 10, 2);

function formidable_qrcode_generate($entry_id, $form_id)
{
    $text_fields = [];
    $file_fields = [];
    foreach (FORMIDABLE_QRCODE_MAP as $map) {
        $text_fields[] = $map['text_field'];
        $file_fields[$map['text_field']] = $map['file_field'];
    }
    $text_fields = implode(',', $text_fields);

    global $wpdb;
    $filtereds = $wpdb->get_results($wpdb->prepare("
        SELECT
            {$wpdb->prefix}frm_item_metas.field_id
            , {$wpdb->prefix}frm_item_metas.meta_value
        FROM {$wpdb->prefix}frm_item_metas
        WHERE {$wpdb->prefix}frm_item_metas.item_id = %d
        AND {$wpdb->prefix}frm_item_metas.field_id IN ({$text_fields})
    ", $entry_id));
    if (count($filtereds) < 1) return true;

    if (!class_exists('QRcode')) include('lib/qrlib.php');
    $upload = wp_upload_dir();
    $upload_dir = $upload['basedir'];
    $upload_dir .= FORMIDABLE_QRCODE_DIR;

    foreach ($filtereds as $submitted) {
        $text = $submitted->meta_value;
        $file_field = $file_fields[$submitted->field_id];

        $text = str_replace(' ', '-', $text); // Replaces all spaces with hyphens.
        $text = preg_replace('/[^A-Za-z0-9\-]/', '', $text); // Removes special chars.
        $text = preg_replace('/-+/', '-', $text); // Replaces multiple hyphens with single one.

        $name = str_replace(' ', '', $text . FORMIDABLE_QRCODE_SIZE . FORMIDABLE_QRCODE_MARGIN);
        $file_path = $upload_dir . $name . '.png';

        QRcode::png($text, $file_path, QR_ECLEVEL_H, FORMIDABLE_QRCODE_SIZE, FORMIDABLE_QRCODE_MARGIN);

        $wp_filetype = wp_check_filetype($file_path);
        $attach_id = wp_insert_attachment([
            'post_mime_type' => $wp_filetype['type'],
            'post_title' => sanitize_file_name(basename($file_path)),
            'post_content' => '',
            'post_status' => 'inherit'
        ], $file_path);
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
        wp_update_attachment_metadata($attach_id, $attach_data);

        $current_file_field_answers = $wpdb->get_results($wpdb->prepare("
            SELECT
                {$wpdb->prefix}frm_item_metas.id
                , {$wpdb->prefix}frm_item_metas.meta_value
            FROM {$wpdb->prefix}frm_item_metas
            WHERE {$wpdb->prefix}frm_item_metas.item_id = %d
            AND {$wpdb->prefix}frm_item_metas.field_id = %d
        ", $entry_id, $file_field));

        if (!empty($current_file_field_answers)) {
            $answer = $current_file_field_answers[0];
            $answer_id = $answer->id;
            $answer_value = $answer->meta_value;
            if (is_int($answer_value) && $answer_value > 0) wp_delete_attachment($answer_value);
            $wpdb->update(
                "{$wpdb->prefix}frm_item_metas",
                ['meta_value' => $attach_id],
                ['id' => $answer_id],
                ['%s'],
                ['%d']
            );
        } else {
            $wpdb->insert("{$wpdb->prefix}frm_item_metas", [
                'meta_value' => $attach_id,
                'field_id' => $file_field,
                'item_id' => $entry_id
            ], ['%s', '%d', '%d']);
        }
    }
}
