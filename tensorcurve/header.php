<?php if(!defined('ABSPATH'))exit; ?><!doctype html>
<html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><?php if(!has_site_icon()): ?><link rel="icon" href="<?php echo esc_url(get_template_directory_uri().'/assets/favicon.svg'); ?>" type="image/svg+xml"><?php endif; wp_head(); ?></head>
<body <?php body_class(); ?>><?php wp_body_open(); ?><a class="skip" href="#main"><?php esc_html_e('Skip to content','tensorcurve'); ?></a>
<?php if(get_theme_mod('tc_demo_notice',false)): ?><div class="preview-strip">EDITORIAL PREVIEW <span>Source-backed articles and dated tariff calculations.</span></div><?php endif; ?>
<header class="masthead"><div class="masthead-top"><span>GPU ECONOMICS &amp; INFRASTRUCTURE</span><span>ENGLISH · GLOBAL EDITION</span></div>
<?php if(has_custom_logo()): the_custom_logo(); else: ?><a class="wordmark" href="<?php echo esc_url(home_url('/')); ?>"><?php echo esc_html(get_bloginfo('name')); ?></a><?php endif; ?>
<p><?php echo esc_html(get_bloginfo('description')); ?></p><nav aria-label="<?php esc_attr_e('Main navigation','tensorcurve'); ?>"><?php wp_nav_menu(array('theme_location'=>'primary','container'=>false,'depth'=>2,'fallback_cb'=>'tc_default_nav')); ?></nav></header>
