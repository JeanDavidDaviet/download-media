<?php
/*
Plugin Name: Download Media
Plugin URI: http://wordpress.org/plugins/download-media/
Description: Allows medias in the media library to be direclty download one by one or in bulk.
Author: Jean-David Daviet
Version: 1.1
Author URI: https://jeandaviddaviet.fr
Text Domain: download-media
*/

namespace JDD;

defined( 'ABSPATH' ) || die();

/**
 * Main plugin class.
 *
 * @since 1.0.0
 */
class DownloadMedia {
  /**
   * This plugin's version number. Used for busting caches.
   *
   * @var string
   */
  public $version = '1.1.1';

  /**
   * This plugin's prefix
   *
   * @var string
   */
  private $prefix = 'dm_';

  /**
   * The capability required to access this plugin's settings.
   * Please don't change this directly. Use the "download_media_settings_cap" filter instead.
   *
   * @var string
   */
  public $capability_settings = 'manage_options';

  /**
   * The capability required to download a media.
   * Please don't change this directly. Use the "download_media_download_cap" filter instead.
   *
   * @var string
   */
  public $capability_download = 'upload_files';

  /**
   * The intervals in seconds for the cron jobs
   *
   * @var array
   */
  public $cron_intervals = array();


  /**
   * The name of the cron hook
   *
   * @var string
   */
  private $cron_hook_name = 'dm_cron_hook';

  public function __construct() {
    register_activation_hook( __FILE__, array( $this , 'activate' ) );
    register_deactivation_hook( __FILE__, array( $this , 'deactivate' ) );

    // Allow people to change what capability is required to use this plugin.
    $this->capability_settings = apply_filters( 'download_media_settings_cap', $this->capability_settings );
    $this->capability_download = apply_filters( 'download_media_download_cap', $this->capability_download );

    // Set the duration of the intervals in seconds
    $this->cron_intervals[$this->prefix . 'daily'] = apply_filters( 'download_media_cron_daily_second', 60 * 60 * 24 );
    $this->cron_intervals[$this->prefix . 'weekly'] = apply_filters( 'download_media_cron_weekly_second', 60 * 60 * 24 * 7 );
    $this->cron_intervals[$this->prefix . 'monthly'] = apply_filters( 'download_media_cron_monthly_second', 60 * 60 * 24 * 30 );

    add_filter( 'media_row_actions', array( $this, 'add_download_link_to_media_list_view' ), 10, 2 );
    add_filter( 'attachment_fields_to_edit', array( $this, 'add_download_link_to_edit_media_modal_fields_area' ), 10, 2 );

    // For the bulk action dropdowns.
    add_action( 'admin_head-upload.php', array( $this, 'add_bulk_actions_via_javascript' ) );
    add_action( 'admin_action_bulk_download_media', array( $this, 'bulk_action_handler' ) ); // Top drowndown.
    add_action( 'admin_action_-1', array( $this, 'bulk_action_handler' ) ); // Bottom dropdown.
    add_action( 'admin_notices', array( $this, 'admin_notice_error' ) );

    add_action('admin_init', array( $this, 'settings_init' ) );
    add_action('admin_menu', array( $this, 'register_settings_page' ) );

    add_filter( 'update_option_download_media_should_delete', array( $this, 'update_option_download_media_should_delete' ), 10, 2 );
    add_filter( 'update_option_download_media_recurrence', array( $this, 'update_option_download_media_recurrence' ), 10, 2 );
    add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );
    add_action( $this->cron_hook_name, array( $this, 'cron_exec' ) );
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

  public function register_settings_page() {
    add_options_page(
      'Download Media Settings Page',
      'Download Media',
      $this->capability_settings,
      'download-media',
      array( $this, 'display_download_media_setting_page_callback' ) );
  }

  public function scan_for_zip_files($dir){
    $scan_plugin_dir = scandir($dir);
    return preg_grep ('#^' . $this->prefix . '.*\.zip$#', $scan_plugin_dir);
  }

  public function display_download_media_setting_page_callback() {
    if ( ! current_user_can( $this->capability_settings ) ) {
      return;
    }

    $found_zips = $this->get_found_zips();

    if ((isset($_POST['action']) && $_POST['action'] === 'delete') &&
      (isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'delete_zip_files'))) {

        $errors = $this->delete_all_zip_files($found_zips);
        $this->display_settings_error($errors);
        if ( ! $errors->has_errors() ) {
          $found_zips = array();
        }
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

  public function settings_init()
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
          array( $this, 'should_delete_cb' ),
          'download-media',
          'download_media_section1'
      );
      register_setting('download_media_section1', 'download_media_should_delete');

      add_settings_field(
          'download_media_recurrence',
          __('The zip files should be deleted every:', 'download-media'),
          array( $this, 'recurrence_cb' ),
          'download-media',
          'download_media_section1'
      );
      register_setting('download_media_section1', 'download_media_recurrence');
  }

  public function should_delete_cb(){
    $setting = get_option('download_media_should_delete', 1);
    ?>
    <p>
      <label><input name="download_media_should_delete" type="radio" value="1" <?php checked((int) $setting, 1); ?>> <?php _e( 'Yes' ); ?></label>
      <br />
      <label><input name="download_media_should_delete" type="radio" value="0" <?php checked((int) $setting, 0); ?>> <?php _e( 'No' ); ?></label>
    </p>
    <?php
  }

  public function recurrence_cb(){
    $setting = get_option('download_media_recurrence', $this->prefix . 'weekly');
    ?>
    <p>
      <label><input name="download_media_recurrence" type="radio" value="<?php echo $this->prefix; ?>daily" <?php checked($setting, $this->prefix . 'daily'); ?>> <?php _e( 'Day', 'download-media' ); ?></label>
      <br />
      <label><input name="download_media_recurrence" type="radio" value="<?php echo $this->prefix; ?>weekly" <?php checked($setting, $this->prefix . 'weekly'); ?>> <?php _e( 'Week', 'download-media' ); ?></label>
      <br />
      <label><input name="download_media_recurrence" type="radio" value="<?php echo $this->prefix; ?>monthly" <?php checked($setting, $this->prefix . 'monthly'); ?>> <?php _e( 'Month', 'download-media' ); ?></label>
    </p>
    <?php
  }

  public function update_option_download_media_recurrence($old, $new){
    $timestamp = wp_next_scheduled( $this->cron_hook_name );
    wp_unschedule_event( $timestamp, $this->cron_hook_name );

    if( ! (int) get_option('download_media_should_delete') ) {
      return;
    }

    if ( ! wp_next_scheduled( $this->cron_hook_name ) ) {
      wp_schedule_event( time() + $this->cron_intervals[$new] , $new, $this->cron_hook_name );
    }
  }

  public function update_option_download_media_should_delete($old, $new){
    $timestamp = wp_next_scheduled( $this->cron_hook_name );
    wp_unschedule_event( $timestamp, $this->cron_hook_name );

    $download_media_recurrence = isset($_POST['download_media_recurrence']) ? $_POST['download_media_recurrence'] : 1;

    if( (int) get_option('download_media_should_delete') ) {
      if ( ! wp_next_scheduled( $this->cron_hook_name )  ) {
        wp_schedule_event( time() + $this->cron_intervals[$download_media_recurrence] , $download_media_recurrence, $this->cron_hook_name );
      }
    }
  }

  public function get_current_dir(){
    return __DIR__;
  }

  public function get_found_zips(){
    $current_dir = $this->get_current_dir();
    return $this->scan_for_zip_files($current_dir);
  }

  public function delete_all_zip_files($found_zips){
    $errors = new WP_Error();
    $current_dir = $this->get_current_dir();
    if ( is_array($found_zips) && count($found_zips) > 0 ) {
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
    }
    return $errors;
  }

  public function display_settings_error(WP_Error $errors){
    if ( $errors->has_errors() ) {
      add_settings_error( 'download_media_message', 'download_media_message', $errors->get_error_message(), 'error' );
    } else {
      add_settings_error( 'download_media_message', 'download_media_message', __( 'Zip files successfully deleted.', 'download-media' ), 'success' );
    }
    settings_errors( 'download_media_message' );
  }

  public function cron_exec(){
    $found_zips = $this->get_found_zips();
    $this->delete_all_zip_files($found_zips);
  }

  public function add_cron_interval( $schedules ) {
      $schedules[$this->prefix . 'daily'] = array(
        'interval' => $this->cron_intervals[$this->prefix . 'daily'],
        'display'  => esc_html__( 'Every Day' ), 'download-media' );
    $schedules[$this->prefix . 'weekly'] = array(
        'interval' => $this->cron_intervals[$this->prefix . 'weekly'],
        'display'  => esc_html__( 'Every Week' ), 'download-media' );
    $schedules[$this->prefix . 'monthly'] = array(
        'interval' => $this->cron_intervals[$this->prefix . 'monthly'],
        'display'  => esc_html__( 'Every Month' ), 'download-media' );
    return $schedules;
  }

  public function activate() {
    add_option('download_media_should_delete', 1, '', false);
    add_option('download_media_recurrence', $this->prefix . 'weekly', '', false);

    if ( ! wp_next_scheduled( $this->cron_hook_name ) ) {
      wp_schedule_event( time(), $this->prefix . 'weekly', $this->cron_hook_name );
    }
  }

  public function deactivate() {
    delete_option('download_media_should_delete');
    delete_option('download_media_recurrence');

    $timestamp = wp_next_scheduled( $this->cron_hook_name );
    wp_unschedule_event( $timestamp, $this->cron_hook_name );
  }
}

new DownloadMedia();
