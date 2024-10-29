<?php
/*
Plugin Name: Admin Menu Post List
Plugin URI: http://wordpress.org/plugins/admin-menu-post-list/
Description: Display a post list in the admin menu
Version: 2.0.7
Author: Eliot Akira
Author URI: https://eliotakira.com
License: GPL2
*/


class AdminMenuPostList {

  private $current_indent_level;
  private $max_trim_length;

  private $hide_child_posts;

  private $current_list_count;
  private $max_list_count;

  private $saved_notice;

  function __construct() {

    // Admin frontend
    add_action( 'admin_menu', array($this,'add_post_list_view'), 11 );
    add_action( 'admin_head', array($this,'post_list_css'));
    add_action( 'admin_footer', array($this,'admin_footer_scripts') );

    // Admin backend
    add_action( 'admin_init', array($this,'register_settings') );
    add_action( 'admin_menu', array($this,'create_menu') );
    add_filter( 'plugin_action_links', array($this,'plugin_settings_link'), 10, 4 );
    // Remove "Settings saved" message on admin page
    add_action( 'admin_notices', array($this, 'remove_settings_saved_notice') );
    $this->saved_notice = false;
  }


/*========================================================================
 *
 * Admin frontend
 *
 *=======================================================================*/


  /*========================================================================
   *
   * Build each item
   *
   *=======================================================================*/

  function build_post_list_item($post_id, $post_type, $is_child, $child_query) {

    if ( !isset($_GET['post']) )
      $current_post_ID = -1;
    else
      $current_post_ID = $_GET['post']; /* Get current post ID on admin screen */

    $edit_link = get_edit_post_link($post_id);
    $title = get_the_title($post_id);
    $title = esc_html($title);

     // Limit title length

    $orig_title = $title;
    $title_trimmed = false;
    if (rtrim($title) == '') $title = $orig_title = '(no title)';
    elseif ($this->max_trim_length > 0 && strlen($title) > $this->max_trim_length) {
      if (function_exists('mb_substr')) {
        $title = mb_substr($title, 0, $this->max_trim_length);
      } else {
        $title = substr($title, 0, $this->max_trim_length);
      }
      $title_trimmed = $title!==$orig_title;
      if ($title_trimmed) $title .= '..';
    }


    $output = '<div class="';

    if ($is_child != 'child') {
      $output .= 'post_list_view_indent';
    } else {
      $output .= 'post_list_view_child';
    }

    if ($current_post_ID == ($post_id)) $output .= ' post_current';

    $output .= '">';

    $output .= '<a href="' . $edit_link . '"';

    // Show full title on hover
    if ($title_trimmed) $output .= ' title="'.$orig_title.'"';

    $output .= '>';


    /*========================================================================
     *
     * Indent child posts
     *
     *=======================================================================*/

    if ($is_child == 'child') {
      if ($this->current_indent_level>0) {
        $output .= '<div class="ampl-child-dash">';
        for ($i=0; $i < $this->current_indent_level; $i++) {
          $output .= '&ndash;';
        }
        $output .= '</div>';
        $output .= ' ';
      }
    }

    /*========================================================================
     *
     * Post status
     *
     *=======================================================================*/

    $post_status = get_post_status($post_id);
    $not_published = in_array($post_status, array('draft', 'pending', 'future'));

    $is_current = $current_post_ID == $post_id;

    if ($not_published) $output .= '<i>';
    if ($is_current) $output .= '<b>';

    $output .= $title;

    if ($is_current) $output .= '</b>';
    if ($not_published) $output .= '</i>';

    $output .= '</a>';


    /*========================================================================
     *
     * Search for children
     *
     *=======================================================================*/

    $children = get_posts(array(
      'post_parent' => $post_id,
      'post_type' => $post_type,
      'orderby' => $child_query['orderby'],
      'order' => $child_query['order'],
      'post_status' => $child_query['post_status'],
      'posts_per_page' => -1,
    ));

    // Output child posts recursively

    if ($children && !$this->hide_child_posts) {

      $this->current_indent_level++;

      foreach($children as $child) {

        // Count children?
        $this->current_list_count++;

        if (($this->max_list_count==0) || ($this->current_list_count <= $this->max_list_count)) {
          $output .= $this->build_post_list_item($child->ID, $post_type, 'child', $child_query);
        } else {
          break;
        }
      }

      $this->current_indent_level--;
    }

    $output .= '</div>';

    return $output;

  } // End: function build_post_list_item



  /*========================================================================
   *
   * Add post list to all enabled post types
   *
   *=======================================================================*/

  function add_post_list_view() {

    // Get settings

    $settings = get_option( 'ampl_settings' );

    $this->max_trim_length = isset($settings['max_trim']) ? $settings['max_trim'] : 0;
    $this->hide_child_posts = isset($settings['hide_child_posts']) ? $settings['hide_child_posts']==="on" : false;

    // Get all post types

    $post_types = $this->get_all_post_types();
    foreach ($post_types as $post_type) {

      $post_types_setting = isset($settings['post_types'][$post_type]) ?
        $settings['post_types'][$post_type] : 'off';

      // If enabled

      if ($post_types_setting == 'on' ) {

        /*========================================================================
         *
         * Get display options
         *
         *=======================================================================*/


        $this->max_list_count = isset($settings['max_limit'][$post_type]) ?
          $settings['max_limit'][$post_type] : 0;

        $post_orderby = isset($settings['orderby'][$post_type]) ?
          $settings['orderby'][$post_type] : 'date';

        $post_order = isset($settings['order'][$post_type]) ?
          $settings['order'][$post_type] : 'ASC';

        $post_exclude = isset($settings['exclude_status'][$post_type]) ?
          $settings['exclude_status'][$post_type] : 'off';

        if ($post_exclude=='on')
          $post_exclude = 'publish';
        else
          $post_exclude = 'any';

        $custom_menu_slug = $post_type;
        $output = '';
        if ($this->max_list_count==0) {
          $max_numberposts = 999;
        } else {
          $max_numberposts = $this->max_list_count;
        }

        $args = apply_filters('AdminMenuPostList_get_posts_args', array(
          'post_type' => $post_type,
          'post_parent' => 0,
          'numberposts' => $max_numberposts,
          'orderby' => $post_orderby,
          'order' => $post_order,
          'post_status' => $post_exclude,
          'suppress_filters' => 0
        ));

        $child_query = apply_filters('AdminMenuPostList_child_query_args', array(
          'orderby' => $post_orderby,
          'order' => $post_order,
          'post_status' => $post_exclude,
        ));

        // Support bbPress topics and replies
        if (in_array($post_type, array('topic', 'reply')) && class_exists('bbPress')) {
          unset($args['post_parent']);
        }

        $posts = get_posts($args);

        if ($posts) {

          $output .= '</a>';
          $output .= '<div class="ampl post_list_view">'
                . '<div class="post_list_view_headline">' . '<hr>' . '</div>';

          $this->current_list_count = 0;

          foreach ($posts as $post) {

            $this->current_indent_level = 0; // Start all parents at 0
            $this->current_list_count++;

            if ($this->max_list_count==0
              || $this->current_list_count <= $this->max_list_count
            ) {
              $output .= $this->build_post_list_item(
                $post->ID, $post_type, 'parent', $child_query
              );
            } else break;
          }

          $output .= '</div>';
          $output .= '<a class="ampl-empty">';

          if ($post_type == 'post') {
            add_posts_page('Title', $output, 'edit_posts', $custom_menu_slug, array($this, 'empty_page'));
          } elseif ($post_type == 'page') {
            add_pages_page('Title', $output, 'edit_pages', $custom_menu_slug, array($this, 'empty_page'));
          } elseif ($post_type == 'attachment') {
            add_media_page('Title', $output, 'edit_posts', $custom_menu_slug, array($this, 'empty_page'));
          } else {
            add_submenu_page('edit.php?post_type=' . $post_type, 'Title', $output, 'edit_posts', $custom_menu_slug, array($this, 'empty_page'));
          }

        } // if post

      } // if enabled for a post type

    } // for each post type

  } // End: function add_post_list_view

  function empty_page() { /* Empty */ }


  /*========================================================================
   *
   * Post list CSS
   *
   *=======================================================================*/

  function post_list_css() {

    ?><style><?php

    include('ampl.css');

    if (version_compare(get_bloginfo('version'), '5.7', '>=') ) {
      ?>
#adminmenu .wp-submenu .post_list_view a {
  display: block !important;
  padding: 0 0 0 10px !important;
  margin-left: -10px;
}
      <?php
    }

    ?></style><?php

  }


  /*========================================================================
   *
   * Footer scripts: Settings page
   *
   *=======================================================================*/

  function admin_footer_scripts() {

    if ( ! $this->is_plugin_page() ) return;

?>
<script type="text/javascript">
jQuery(document).ready(function($){

  // Smart defaults for orderby -> order

  $('.ampl-settings-page .ampl-select-orderby').on('change', function() {

    var $el = $(this)
    var $row = $el.closest('tr')
    var radioKey = $el.prop('name').replace('[orderby]','[order]');
    var value = $el.prop('value');
    var $options = $row.find('select[name="'+radioKey+'"] option')

    switch(value) {
      case 'date' :
      case 'modified' :
        $options.each(function() {
          var $option = $(this)
          $option.prop('selected', $option.val()==='DESC');
        })
      break;
      case 'title' :
      case 'menu_order' :
        $options.each(function() {
          var $option = $(this)
          $option.prop('selected', $option.val()==='ASC');
        })
      break;
    }
  });
});
</script>
<?php

  }



/*========================================================================
 *
 * Admin backend
 *
 *=======================================================================*/


  /*========================================================================
   *
   * Settings page
   *
   *=======================================================================*/

  function create_menu() {
    add_options_page('Post List', 'Post List', 'manage_options', 'admin_menu_post_list_settings_page', array($this,'ampl_settings_page'));
  }

  // Add settings link on plugin list page

  function plugin_settings_link( $links, $file ) {
    $plugin_file = 'admin-menu-post-list/admin-menu-post-list.php';
    //make sure it is our plugin we are modifying
    if ( $file == $plugin_file ) {
      $settings_link = '<a href="' .
        admin_url( 'options-general.php?page=admin_menu_post_list_settings_page' ) . '">' .
        __( 'Settings', 'admin_menu_post_list_settings_page' ) . '</a>';
      array_unshift( $links, $settings_link );
    }
    return $links;
  }

  function register_settings() {
    register_setting( 'ampl_settings_field', 'ampl_settings', array($this,'settings_field_validate'));
    add_settings_section('ampl_settings_section', '', array($this,'empty_page'), 'ampl_settings_section_page_name');
    add_settings_field('ampl_settings_field_string', '', array($this,'settings_field_input'), 'ampl_settings_section_page_name', 'ampl_settings_section');
  }

  function settings_field_validate($input) { return $input; }


  function ampl_settings_page() {
    ?>
    <div class="wrap ampl-settings-page">
    <h2>Admin Menu Post List</h2>
    <form method="post" action="options.php" style="margin-top:-30px;">
        <?php settings_fields( 'ampl_settings_field' ); ?>
        <?php do_settings_sections( 'ampl_settings_section_page_name' ); ?>
        <?php submit_button(); ?>
    </form>
    <?php
      if ($this->saved_notice)
        echo '<div class="saved-notice">Settings saved.</div>';
    ?>
    </div>
    <?php
  }

  /*========================================================================
   *
   * Settings field input
   *
   *=======================================================================*/

  function settings_field_input() {

    $settings = get_option( 'ampl_settings');

    ?>
    <tr class="ampl-border-top ampl-border-bottom">
      <td><b>Post type</b></td>
      <td><b>Max items</b> (0=all)</td>
      <td><b>Order by</b></td>
      <td><b>Order</b></td>
      <td><b>Show only published</b></td>
    </tr>
    <?php

    $all_post_types = $this->get_all_post_types();
    $exclude_types = array('attachment');
    ksort($all_post_types);

    // Global settings

    if (isset( $settings['max_trim'] ) )
      $max_trim = $settings['max_trim'];
    else
      $max_trim =  '20';

    $hide_child_posts = isset( $settings['hide_child_posts'] ) ? $settings['hide_child_posts'] : 'off';

    $non_public_post_types = isset($settings['non_public_post_types'])
      ? $settings['non_public_post_types']
      : 'off';

    // Post type specific settings

    foreach ($all_post_types as $key) {

      if (!in_array($key, $exclude_types)) {

        $post_types = isset( $settings['post_types'][ $key ] ) ? esc_attr( $settings['post_types'][ $key ] ) : '';

        $post_type_object = get_post_type_object( $key );
        $post_type_label = $post_type_object->labels->name;

        if (isset( $settings['max_limit'][ $key ] ) )
          $max_number = $settings['max_limit'][ $key ];
        else
          $max_number = '0';

        if (isset( $settings['orderby'][ $key ] ) )
          $post_orderby = $settings['orderby'][ $key ];
        else
          $post_orderby = 'date';

        if (isset( $settings['order'][ $key ] ) )
          $post_order = $settings['order'][ $key ];
        else
          $post_order = 'DESC';

        if (isset( $settings['exclude_status'][ $key ] ) )
          $post_exclude = $settings['exclude_status'][ $key ];
        else
          $post_exclude = 'off';

        ?>
        <tr>
          <td>
            <input type="checkbox" id="<?php echo $key; ?>" name="ampl_settings[post_types][<?php echo $key; ?>]" <?php checked( $post_types, 'on' ); ?>/>
            <?php echo '&nbsp;' . ucwords($post_type_label); ?>
          </td>
          <td>
            <input type="text" size="3"
              id="ampl_settings_field_max_limit"
              name="ampl_settings[max_limit][<?php echo $key; ?>]"
              value="<?php echo $max_number; ?>" />
          </td>
          <td>
            <select class="ampl-select-orderby" name="ampl_settings[orderby][<?php echo $key; ?>]" autocomplete="off">
              <?php
              foreach (array(
                'date' => 'Created date',
                'modified' => 'Modified date',
                'title' => 'Title',
                'menu_order' => 'Menu order',
              ) as $orderby_value => $orderby_label) {
                ?><option value="<?php echo $orderby_value; ?>"<?php
                  if ($orderby_value===$post_orderby) {
                    echo ' selected="selected"';
                  }
                ?>><?php
                  echo $orderby_label;
                ?></option><?php
              }
              ?>
            </select>
          </td>
          <td>
            <select name="ampl_settings[order][<?php echo $key; ?>]" autocomplete="off">
              <option value="ASC" <?php echo selected($post_order, 'ASC'); ?>>ASC - alphabetical</option>
              <option value="DESC" <?php echo selected($post_order, 'DESC'); ?>>DESC - new to old</option>
            </select>
          </td>
          <td>
            <input type="checkbox" name="ampl_settings[exclude_status][<?php echo $key; ?>]" <?php checked( $post_exclude, 'on' ); ?>/>
          </td>
        </tr>
        <?php
      } // if not excluded
    } // foreach post type
    ?>

    <tr class="ampl-border-top">
      <td></td><td></td><td></td><td></td><td></td>
    </tr>
    <tr>
      <td>
        Limit title length
      </td>
      <td>
        <input type="text" size="3"
          name="ampl_settings[max_trim]"
          value="<?php echo $max_trim; ?>" />
      </td>
    </tr>

    <tr>
      <td style="min-width: 120px">
        Hide child posts
      </td>
      <td>
        &nbsp;<input type="checkbox" name="ampl_settings[hide_child_posts]" <?php checked( $hide_child_posts, 'on' ); ?>/>
      </td>
    </tr>

    <tr>
      <td>
        Include non-public post types
      </td>
      <td>
        &nbsp;<input type="checkbox" name="ampl_settings[non_public_post_types]" <?php checked( $non_public_post_types, 'on' ); ?>/>
      </td>
    </tr>
    <?php

  }


/*========================================================================
 *
 * Helper functions
 *
 *=======================================================================*/

  function get_all_post_types() {

    $args = array(
      'public' => true
    );

    $settings = get_option( 'ampl_settings');
    $non_public_post_types = isset($settings['non_public_post_types'])
      ? $settings['non_public_post_types']
      : 'off';

    if ($non_public_post_types==='on') unset($args['public']);

    return get_post_types(apply_filters('AdminMenuPostList_post_type_args', $args));
  }

  function is_plugin_page() {
    global $pagenow;
    $page = isset($_GET['page']) ? $_GET['page'] : null;
    return ($pagenow == 'options-general.php' && $page == 'admin_menu_post_list_settings_page');
  }

  function remove_settings_saved_notice(){
    if ($this->is_plugin_page()) {
      if ( (isset($_GET['updated']) && $_GET['updated'] == 'true') ||
        (isset($_GET['settings-updated']) && $_GET['settings-updated'] == 'true') ) {
          //this will clear the update message "Settings Saved" totally
        unset($_GET['settings-updated']);
        $this->saved_notice = true;
      }
    }
  }
}

new AdminMenuPostList;
