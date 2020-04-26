<?php
/*
Plugin Name: Download Media
Plugin URI: http://wordpress.org/plugins/download-media/
Description: Allows images in the media library to be direclty download one by one or in bulk.
Author: Jean-David Daviet
Version: 1.0
Author URI: https://jeandaviddaviet.fr
Text Domain: download-image
*/

add_filter( 'media_row_actions', 'dm_add_download_link_to_media_list_view', 10, 2 );
add_filter( 'attachment_fields_to_edit', 'dm_add_download_link_to_edit_media_modal_fields_area', 10, 2 );

// For the bulk action dropdowns.
add_action( 'admin_head-upload.php', 'dm_add_bulk_actions_via_javascript' );
add_action( 'admin_action_bulk_download_image', 'dm_bulk_action_handler' ); // Top drowndown.
add_action( 'admin_action_-1', 'dm_bulk_action_handler' ); // Bottom dropdown.
add_action( 'admin_notices', 'dm_admin_notice_error' );

add_action('admin_init', 'dm_settings_init');
add_action('admin_menu', 'dm_register_settings_page');


function dm_add_download_link_to_media_list_view($actions, $post){
  if( ! current_user_can( 'upload_files' ) ) {
    return $actions;
  }

  $actions['download_image'] = dm_generate_link_for_image($post);
  return $actions;
}


function dm_add_download_link_to_edit_media_modal_fields_area( $form_fields, $post ) {

  if( ! current_user_can( 'upload_files' ) ) {
    return $form_fields;
  }

  $form_fields['download_image'] = array(
    'label'         => '',
    'input'         => 'html',
    'html'          => dm_generate_link_for_image($post, 'button-secondary button-large'),
    'show_in_modal' => true,
    'show_in_edit'  => false,
  );
  return $form_fields;
}


function dm_generate_link_for_image($post, $class = ''){
  $title = apply_filters('the_title', $post->post_title);
  return '<a download="' . esc_attr( $title ) . '" href="' . esc_attr( wp_get_attachment_image_url( $post->ID, 'full' ) ) . '" title="' . esc_attr( __( 'Download this image', 'download-image' ) ) . '" class="' . $class . '">' . _x( 'Download this image', 'action for a single image', 'download-image' ) . '</a>';
}


function dm_add_bulk_actions_via_javascript() {
  if ( ! current_user_can( 'upload_files' ) || ! class_exists('ZipArchive') ) {
    return;
  }

  ?>
  <script type="text/javascript">
    jQuery(document).ready(function ($) {
      $('select[name^="action"] option:last-child').before(
        $('<option/>')
          .attr('value', 'bulk_download_image')
          .text('<?php echo esc_js( _x( 'Download the selected images', 'bulk actions dropdown', 'download-image' ) ); ?>')
      );
    });
  </script>
  <?php
}

function dm_bulk_action_handler() {
  if (empty( $_REQUEST['action'] ) || empty( $_REQUEST['action2'] ) || ( 'bulk_download_image' != $_REQUEST['action'] && 'bulk_download_image' != $_REQUEST['action2'] ) || empty( $_REQUEST['media'] ) || ! is_array( $_REQUEST['media'] )) {
    return;
  }

  check_admin_referer( 'bulk-media' );

  $errors = new WP_Error();

  if( class_exists('ZipArchive') ){
    $errors->add( 'zip_archive_class', __('The ZipArchive PHP Library isn\'t installed.' , 'download-media') );
    dm_displayError( $errors );
  }

  $zip = new ZipArchive();
  $zip_path = __DIR__ . DIRECTORY_SEPARATOR . "dm_" . time() . ".zip";

  if ( $zip->open( $zip_path, ZipArchive::CREATE ) !== true ) {
    /* translators: %s: Generated name of the zipfile */
    $errors->add( 'open_zip', sprintf( __('Can\'t open the zip file %' , 'download-media'), $zip_path ) );
    dm_displayError( $errors );
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
    dm_displayError( $errors );
  }

  if( $zip->close() !== true){
    $errors->add( 'cant_create_zip', __('Something wrong appened when trying to create the ZIP file.' , 'download-media') );
    dm_displayError( $errors );
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
    dm_displayError( $errors );
  }

  exit();
}

function dm_displayError( $errors ){
  set_transient( 'download_media_error_notice', $errors );
  wp_safe_redirect( admin_url( 'upload.php' ) );
  die;
}

function dm_admin_notice_error() {
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

function dm_register_settings_page() {
  add_options_page(
    'Download Media Settings Page',
    'Download Media',
    'manage_options',
    'download-media',
    'dm_display_download_media_setting_page_callback' );
}

function dm_scan_for_zip_files($dir){
  $scan_dm_dir = scandir($dir);
  return preg_grep ('#^dm_.*\.zip$#', $scan_dm_dir);
}

function dm_display_download_media_setting_page_callback() {
  if ( ! current_user_can( 'manage_options' ) ) {
    return;
  }

  $current_dir = __DIR__;
  $found_zips = dm_scan_for_zip_files($current_dir);

  if ((isset($_POST['action']) && $_POST['action'] === 'delete') &&
    (isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'delete_zip_files'))) {

      $errors = new WP_Error();
      foreach($found_zips as $found_zip){
        $filename = $current_dir . DIRECTORY_SEPARATOR . $found_zip;

        if(file_exists($filename)) {
          if(is_writable(dirname($filename))) {
            unlink( $filename );
          }else{
            $errors->add( 'is_not_deletable', sprintf( __('The file %s isn\'t deletable' , 'download-media'), $filename ) );
          }
        }else{
          $errors->add( 'file_doesnt_exist', sprintf( __('The file %s doesn\'t exist.' , 'download-media'), $filename ) );
        }
      }

      if ( $errors->has_errors() ) {
        add_settings_error( 'download_media_message', 'download_media_message', $errors->get_error_message(), 'error' );
      } else {
        $found_zips = [];
        add_settings_error( 'download_media_message', 'download_media_message', __( 'Zip files successfully deleted.', 'download-media' ), 'success' );
      }
      settings_errors( 'download_media_message' );
    }
  ?>
  <div class="wrap">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

    <form action="<?php echo admin_url( 'options.php'); ?>" method="post">
    <?php
      do_settings_sections( 'download-media' );
      settings_fields( 'download_media_section1' );
      submit_button( 'Save Settings' );
    ?>
    </form>

      <?php $found_zips_count = count($found_zips);
      if ( $found_zips_count ) : ?><form action="<?php echo admin_url( 'options-general.php?page=download-media'); ?>" method="post"><?php endif; ?>
        <h2><?php _e( 'Download Media Zip Files Deletion' , 'download-media' ); ?></h2>
        <p><?php if ( ! $found_zips_count ) :
          _e( 'There are no zip files to delete.', 'download-media' );
        else:
          printf( _n( 'There is %s zip file to delete.',  'There are %s zip files to delete.', $found_zips_count, 'download-media' ), $found_zips_count );
        endif; ?></p>
        <?php if ( $found_zips_count ) : ?>
        <table class="form-table" role="presentation">
          <tbody>
            <tr>
              <th scope="row"><?php _e( 'Delete all the generated zip files now' , 'download-media' ); ?></th>
              <td><input type="submit" name="submit" id="submit" class="button" value="<?php _e( 'Delete files now' , 'download-media' ); ?>"></td>
            </tr>
          </tbody>
        </table>
        <input type="hidden" name="action" value="delete">
        <?php wp_nonce_field('delete_zip_files'); ?>
      </form>
      <?php endif; ?>
  </div>
<?php
}

function dm_settings_init()
{
    add_settings_section(
        'download_media_section1',
        'Download Media Settings',
        '__return_false',
        'download-media'
    );

    add_settings_field(
        'download_media_should_delete',
        __('Should the zip files be deleted automatically ?', 'download-media'),
        'download_media_should_delete_cb',
        'download-media',
        'download_media_section1'
    );
    register_setting('download_media_section1', 'download_media_should_delete');

    add_settings_field(
        'download_media_recurrence',
        __('The zip files should be deleted every:', 'download-media'),
        'download_media_recurrence_cb',
        'download-media',
        'download_media_section1'
    );
    register_setting('download_media_section1', 'download_media_recurrence');
}

function download_media_should_delete_cb(){
  $setting = get_option('download_media_should_delete', 1);
  ?>
  <p>
    <label><input name="download_media_should_delete" type="radio" value="1" <?php checked((int) $setting, 1); ?>> <?php _e( 'Yes' ); ?></label>
    <br />
    <label><input name="download_media_should_delete" type="radio" value="0" <?php checked((int) $setting, 0); ?>> <?php _e( 'No' ); ?></label>
  </p>
  <?php
}

function download_media_recurrence_cb(){
  $setting = get_option('download_media_recurrence', 'week');
  ?>
  <p>
    <label><input name="download_media_recurrence" type="radio" value="day" <?php checked($setting, 'day'); ?>> <?php _e( 'Day', 'download-image' ); ?></label>
    <br />
    <label><input name="download_media_recurrence" type="radio" value="week" <?php checked($setting, 'week'); ?>> <?php _e( 'Week', 'download-image' ); ?></label>
    <br />
    <label><input name="download_media_recurrence" type="radio" value="month" <?php checked($setting, 'month'); ?>> <?php _e( 'Month', 'download-image' ); ?></label>
  </p>
  <?php
}
