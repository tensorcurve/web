<?php
if(!defined('ABSPATH'))exit;
function tc_bool($value){return (bool)$value;}
function tc_publisher($value){return preg_match('/^ca-pub-[0-9]{16}$/',trim($value))?trim($value):'';}
function tc_slot($value){return preg_match('/^[0-9]{5,20}$/',trim($value))?trim($value):'';}
function tc_customize($wp_customize){
 $wp_customize->add_section('tc_publication',array('title'=>'TensorCurve — Publication','priority'=>30));
 $settings=array(
 'tc_footer_note'=>array('Footer description','Understanding the cost of compute.','text','sanitize_text_field'),
 'tc_demo_notice'=>array('Show editorial preview banner',false,'checkbox','tc_bool'),
 'tc_contact_email'=>array('Public editorial email (visible to visitors)','','email','sanitize_email'),
 );
 foreach($settings as $key=>$item){$wp_customize->add_setting($key,array('default'=>$item[1],'sanitize_callback'=>$item[3]));$wp_customize->add_control($key,array('label'=>$item[0],'section'=>'tc_publication','type'=>$item[2]));}
 $choices=array(0=>'Latest published article');foreach(get_posts(array('numberposts'=>100,'post_status'=>'publish')) as $p)$choices[$p->ID]=$p->post_title;
 $wp_customize->add_setting('tc_featured_post',array('default'=>0,'sanitize_callback'=>'absint'));
 $wp_customize->add_control('tc_featured_post',array('label'=>'Featured homepage article','section'=>'tc_publication','type'=>'select','choices'=>$choices));
 $wp_customize->add_section('tc_advertising',array('title'=>'TensorCurve — Advertising','description'=>'Ads are off by default. Use your own approved publisher and display-unit IDs. Configure privacy and required consent before enabling ads. Do not also insert these same units with another plugin.','priority'=>31));
 $settings=array(
 'tc_ad_preview'=>array('Show non-clickable ad layout placeholders',false,'checkbox','tc_bool'),
 'tc_ads_enabled'=>array('Enable real AdSense display units',false,'checkbox','tc_bool'),
 'tc_ads_publisher'=>array('Publisher ID (ca-pub- followed by 16 digits)','','text','tc_publisher'),
 'tc_ads_home'=>array('Homepage display-unit slot ID','','text','tc_slot'),
 'tc_ads_article'=>array('Article display-unit slot ID','','text','tc_slot'),
 );
 foreach($settings as $key=>$item){$wp_customize->add_setting($key,array('default'=>$item[1],'sanitize_callback'=>$item[3]));$wp_customize->add_control($key,array('label'=>$item[0],'section'=>'tc_advertising','type'=>$item[2]));}
}
add_action('customize_register','tc_customize');
