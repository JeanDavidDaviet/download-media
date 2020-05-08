<?php

namespace JDD;

defined( 'ABSPATH' ) || die();

/**
 * Plugin handling the list media library.
 *
 * @since 1.2
 */
class DownloadMedia_List {

  /**
   * The capability required to download a media.
   * Please don't change this directly. Use the "download_media_download_cap" filter instead.
   *
   * @var string
   */
  public $capability_download = 'upload_files';

  public function __construct() {

    $this->capability_download = apply_filters( 'download_media_download_cap', $this->capability_download );

    add_filter( 'media_row_actions', array( $this, 'add_download_link_to_media_list_view' ), 10, 2 );
    add_filter( 'attachment_fields_to_edit', array( $this, 'add_download_link_to_edit_media_modal_fields_area' ), 10, 2 );

    // For the bulk action dropdowns.
    add_action( 'admin_head-upload.php', array( $this, 'add_bulk_actions_via_javascript' ) );
    add_action( 'admin_action_bulk_download_media', array( $this, 'bulk_action_handler' ) ); // Top drowndown.
    add_action( 'admin_action_-1', array( $this, 'bulk_action_handler' ) ); // Bottom dropdown.
    add_action( 'admin_notices', array( $this, 'admin_notice_error' ) );
  }

  public function add_download_link_to_media_list_view($actions, $post){
    if( ! current_user_can( $this->capability_download ) ) {
      return $actions;
    }

    $actions['download_media'] = $this->generate_link_for_media($post);
    return $actions;
  }

  public function add_download_link_to_edit_media_modal_fields_area( $form_fields, $post ) {
    if( ! current_user_can( $this->capability_download ) ) {
      return $form_fields;
    }

    $form_fields['download_media'] = array(
      'label'         => '',
      'input'         => 'html',
      'html'          => $this->generate_link_for_media($post, 'button-secondary button-large'),
      'show_in_modal' => true,
      'show_in_edit'  => false,
    );
    return $form_fields;
  }

  public function generate_link_for_media($post, $class = ''){
    $title = apply_filters('the_title', $post->post_title);
    return '<a download="' . esc_attr( $title ) . '" href="' . esc_attr( wp_get_attachment_image_url( $post->ID, 'full' ) ) . '" title="' . esc_attr( __( 'Download this media', 'download-media' ) ) . '" class="' . $class . '">' . _x( 'Download this media', 'action for a single media', 'download-media' ) . '</a>';
  }

  public function add_bulk_actions_via_javascript() {
    if ( ! current_user_can( $this->capability_download ) || ! class_exists('ZipArchive') ) {
      return;
    }

    ?>
    <script type="text/javascript">
      jQuery(document).ready(function ($) {
        $('select[name^="action"] option:last-child').before(
          $('<option/>')
            .attr('value', 'bulk_download_media')
            .text('<?php echo esc_js( _x( 'Download the selected medias', 'bulk actions dropdown', 'download-media' ) ); ?>')
        );
      });
    </script>
    <?php
  }

  public function bulk_action_handler() {
    if (empty( $_REQUEST['action'] ) || empty( $_REQUEST['action2'] ) || ( 'bulk_download_media' != $_REQUEST['action'] && 'bulk_download_media' != $_REQUEST['action2'] ) || empty( $_REQUEST['media'] ) || ! is_array( $_REQUEST['media'] )) {
      return;
    }

    check_admin_referer( 'bulk-media' );

    $errors = new WP_Error();

    if( class_exists('ZipArchive') ){
      $errors->add( 'zip_archive_class', __('The ZipArchive PHP Library isn\'t installed.' , 'download-media') );
      $this->display_bulk_error( $errors );
    }

    $zip = new ZipArchive();
    $zip_path = __DIR__ . DIRECTORY_SEPARATOR . $this->prefix . time() . ".zip";

    if ( $zip->open( $zip_path, ZipArchive::CREATE ) !== true ) {
      /* translators: %s: Generated name of the zipfile */
      $errors->add( 'open_zip', sprintf( __('Can\'t open the zip file %' , 'download-media'), $zip_path ) );
      $this->display_bulk_error( $errors );
    }

    foreach($_REQUEST['media'] as $media){
      $title = get_the_title($media);
      $media_path = get_attached_file((int) $media);
      $extension = pathinfo($media_path);
      $extension = $extension['extension'];
      $filename = $title . '.' . $extension;

      if(file_exists($media_path)) {
        if(is_readable($media_path)) {
          $zip->addFile($media_path, $filename);
        }else{
          $errors->add( 'is_not_readable', sprintf( __('The file %s isn\'t readable' , 'download-media'), $filename ) );
        }
      }else{
        $errors->add( 'file_doesnt_exist', sprintf( __('The file %s doesn\'t exist.' , 'download-media'), $filename ) );
      }
    }

    if ( $errors->has_errors() ) {
      $this->display_bulk_error( $errors );
    }

    if( $zip->close() !== true){
      $errors->add( 'cant_create_zip', __('Something wrong appened when trying to create the ZIP file.' , 'download-media') );
      $this->display_bulk_error( $errors );
    }

    if(file_exists($zip_path)) {
      header('Content-Description: File Transfer');
      header('Content-Type: application/octet-stream');
      header('Content-Disposition: attachment; filename="' . basename($zip_path) . '"');
      header('Expires: 0');
      header('Cache-Control: must-revalidate');
      header('Pragma: public');
      header('Content-Length: ' . filesize($zip_path));
      flush(); // Flush system output buffer
      readfile($zip_path);
      exit();
    }else{
      $errors->add( 'cant_download_zip', __('Something wrong appened when trying to download the ZIP file.' , 'download-media') );
      $this->display_bulk_error( $errors );
    }

    exit();
  }

  public function display_bulk_error( $errors ){
    set_transient( 'download_media_error_notice', $errors );
    wp_safe_redirect( admin_url( 'upload.php' ) );
    die;
  }

  public function admin_notice_error() {
    $error = get_transient('download_media_error_notice');
    if(is_wp_error($error)):
      $error_messages = $error->get_error_messages();
      delete_transient('download_media_error_notice');

      foreach($error_messages as $error_message): ?>
      <div class="notice notice-error is-dismissible">
        <p><?php echo $error_message; ?></p>
      </div>
      <?php
      endforeach;
    endif;
  }
}
