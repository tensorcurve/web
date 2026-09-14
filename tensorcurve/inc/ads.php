<?php
if(!defined('ABSPATH'))exit;
function tc_ads_ready(){return get_theme_mod('tc_ads_enabled',false)&&tc_publisher(get_theme_mod('tc_ads_publisher',''));}
function tc_ad_markup($placement){
 if(!in_array($placement,array('home','article'),true)||is_feed()||is_preview()||post_password_required())return '';
 if($placement==='article'&&!is_single())return '';
 if($placement==='home'&&!is_front_page())return '';
 static $rendered=array();if(isset($rendered[$placement]))return '';
 $slot=tc_slot(get_theme_mod('tc_ads_'.$placement,''));
 if(tc_ads_ready()&&$slot){
  $rendered[$placement]=true;
  return '<aside class="ad-placement ad-'.esc_attr($placement).'"><span class="ad-label">Advertisements</span><ins class="adsbygoogle" style="display:block" data-ad-client="'.esc_attr(tc_publisher(get_theme_mod('tc_ads_publisher',''))).'" data-ad-slot="'.esc_attr($slot).'" data-ad-format="auto" data-full-width-responsive="true"></ins></aside>';
 }
 if(get_theme_mod('tc_ad_preview',false)){
  $rendered[$placement]=true;
  return '<aside class="ad-placement ad-'.esc_attr($placement).'" aria-label="Advertising layout preview"><span class="ad-label">Advertisements</span><div class="ad-placeholder"><span>Reserved advertising space<small>Layout preview · No live ad</small></span></div></aside>';
 }
 return '';
}
function tc_ad($placement){echo tc_ad_markup($placement); /* Sanitized and escaped by tc_ad_markup. */}
function tc_ad_shortcode(){return tc_ad_markup('article');}
add_shortcode('tensorcurve_ad','tc_ad_shortcode');
function tc_ads_assets(){
 if(!tc_ads_ready()||is_preview()||post_password_required()||(!is_single()&&!is_front_page()))return;
 $slot=tc_slot(get_theme_mod(is_single()?'tc_ads_article':'tc_ads_home',''));if(!$slot)return;
 if(is_front_page()&&!get_posts(array('numberposts'=>1,'fields'=>'ids','post_status'=>'publish')))return;
 wp_enqueue_script('tc-adsense','https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client='.rawurlencode(tc_publisher(get_theme_mod('tc_ads_publisher',''))),array(),null,array('strategy'=>'async','in_footer'=>true));
 wp_enqueue_script('tc-ad-init',get_template_directory_uri().'/assets/ads.js',array(),TC_VERSION,array('strategy'=>'defer','in_footer'=>true));
}
add_action('wp_enqueue_scripts','tc_ads_assets');
function tc_ad_script_tag($tag,$handle){return $handle==='tc-adsense'?str_replace('<script ','<script crossorigin="anonymous" ',$tag):$tag;}
add_filter('script_loader_tag','tc_ad_script_tag',10,2);
