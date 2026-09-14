<?php
if (!defined('ABSPATH')) { exit; }
define('TC_VERSION','1.4.0');
function tc_setup(){
 load_theme_textdomain('tensorcurve',get_template_directory().'/languages');
 add_theme_support('title-tag');add_theme_support('post-thumbnails');add_theme_support('automatic-feed-links');
 add_theme_support('html5',array('search-form','comment-form','comment-list','gallery','caption','style','script'));
 add_theme_support('custom-logo',array('height'=>100,'width'=>500,'flex-width'=>true,'flex-height'=>true));
 add_theme_support('responsive-embeds');add_theme_support('align-wide');add_theme_support('editor-styles');add_editor_style('assets/editor.css');
 register_nav_menus(array('primary'=>__('Main navigation','tensorcurve'),'footer'=>__('Publication links','tensorcurve')));
 $GLOBALS['content_width']=760;
}
add_action('after_setup_theme','tc_setup');
function tc_assets(){
 $uri=get_template_directory_uri();$deps=array();
 if(is_page_template('page-pricing.php')){wp_enqueue_style('tc-pricing',$uri.'/assets/pricing.css',array(),TC_VERSION);$deps[]='tc-pricing';}
 wp_enqueue_style('tc-editorial',$uri.'/assets/editorial.css',$deps,TC_VERSION);
 if(is_page_template('page-pricing.php')){wp_enqueue_style('tc-pricing-light',$uri.'/assets/pricing-light.css',array('tc-editorial'),TC_VERSION);}
 wp_enqueue_style('tc-wordpress',$uri.'/assets/wordpress.css',array('tc-editorial'),TC_VERSION);
 if(is_page_template('page-pricing.php')){wp_enqueue_script('tc-pricing',$uri.'/assets/pricing.js',array(),TC_VERSION,array('strategy'=>'defer','in_footer'=>true));wp_add_inline_script('tc-pricing','window.TENSORCURVE_PRICING='.wp_json_encode(tc_pricing_data(),JSON_HEX_TAG|JSON_HEX_AMP).';','before');}
 if(is_single())wp_enqueue_script('tc-reading',$uri.'/assets/reading.js',array(),TC_VERSION,array('strategy'=>'defer','in_footer'=>true));
 if(is_singular()&&comments_open()&&get_option('thread_comments'))wp_enqueue_script('comment-reply');
}
add_action('wp_enqueue_scripts','tc_assets');
function tc_page_url($slug){
 $ids=get_option('tc_setup_pages',array());$id=isset($ids[$slug])?absint($ids[$slug]):0;
 if($id&&get_post_status($id)==='publish')return get_permalink($id);
 $page=get_page_by_path($slug);return $page&&$page->post_status==='publish'?get_permalink($page):'';
}
function tc_guides_url(){ $id=absint(get_option('page_for_posts'));return $id?get_permalink($id):home_url('/'); }
function tc_default_nav(){
 $links=array(__('Home','tensorcurve')=>home_url('/'),__('Guides','tensorcurve')=>tc_guides_url());
 foreach(array('pricing'=>'Pricing Lab','methodology'=>'Methodology','about'=>'About') as $slug=>$label){$url=tc_page_url($slug);if($url)$links[$label]=$url;}
 echo '<ul class="menu">';foreach($links as $label=>$url)echo '<li><a href="'.esc_url($url).'">'.esc_html($label).'</a></li>';echo '</ul>';
}
function tc_topics(){
 $cats=get_categories(array('hide_empty'=>true,'number'=>6));if(!$cats)return;$order=array('Buyer guides'=>0,'Forward markets'=>1,'Cost analysis'=>2,'GPU pricing'=>3);usort($cats,function($a,$b)use($order){return ($order[$a->name]??99)<=>($order[$b->name]??99);});
 echo '<nav class="topic-nav" aria-label="'.esc_attr__('Explore topics','tensorcurve').'"><span>'.esc_html__('EXPLORE','tensorcurve').'</span>';
 foreach($cats as $cat)echo '<a href="'.esc_url(get_category_link($cat->term_id)).'">'.esc_html($cat->name).'</a>';echo '</nav>';
}
function tc_category(){ $cats=get_the_category();if($cats)echo '<a class="category" href="'.esc_url(get_category_link($cats[0]->term_id)).'">'.esc_html($cats[0]->name).'</a>'; }
function tc_meta(){
 $words=str_word_count(wp_strip_all_tags(get_post_field('post_content',get_the_ID())));$mins=max(1,(int)ceil($words/220));
 echo '<div class="story-meta">'.esc_html__('By','tensorcurve').' <a rel="author" href="'.esc_url(get_author_posts_url(get_the_author_meta('ID'))).'">'.esc_html(get_the_author()).'</a><span>·</span><time datetime="'.esc_attr(get_the_date(DATE_W3C)).'">'.esc_html(get_the_date()).'</time><span>·</span>'.esc_html(sprintf(__('%d min read','tensorcurve'),$mins));
 if(get_the_modified_time('U')>get_the_time('U')+86400)echo '<span>·</span>'.esc_html__('Updated','tensorcurve').' <time datetime="'.esc_attr(get_the_modified_date(DATE_W3C)).'">'.esc_html(get_the_modified_date()).'</time>';
 echo '</div>';
}
function tc_card(){get_template_part('template-parts/card');}
function tc_has_seo_plugin(){return defined('WPSEO_VERSION')||defined('RANK_MATH_VERSION')||defined('AIOSEO_VERSION')||defined('SEOPRESS_VERSION')||function_exists('the_seo_framework');}
function tc_seo(){
 if(tc_has_seo_plugin())return;
 $desc=is_singular()?get_the_excerpt(get_queried_object_id()):get_bloginfo('description');
 if(is_category()||is_tag()||is_tax())$desc=term_description();
 $desc=wp_trim_words(wp_strip_all_tags($desc), tc_description_words(), '…');
 if($desc)echo '<meta name="description" content="'.esc_attr($desc).'">' ."\n";
 if(is_singular()&&!post_password_required()){
  $id=get_queried_object_id();$url=get_permalink($id);$title=get_the_title($id);
  echo '<meta property="og:title" content="'.esc_attr($title).'"><meta property="og:description" content="'.esc_attr($desc).'"><meta property="og:url" content="'.esc_url($url).'"><meta property="og:type" content="'.(is_single()?'article':'website').'">';
  $image=get_the_post_thumbnail_url($id,'large');if($image)echo '<meta property="og:image" content="'.esc_url($image).'">';
  if(is_single()){
   $post=get_post($id);$author=(int)$post->post_author;
   $data=array('@context'=>'https://schema.org','@type'=>'Article','headline'=>$title,'mainEntityOfPage'=>$url,'datePublished'=>get_the_date(DATE_W3C,$id),'dateModified'=>get_the_modified_date(DATE_W3C,$id),'author'=>array('@type'=>'Person','name'=>get_the_author_meta('display_name',$author),'url'=>get_author_posts_url($author)),'publisher'=>array('@type'=>'Organization','name'=>get_bloginfo('name')));
   if($image)$data['image']=array($image);
   echo '<script type="application/ld+json">'.wp_json_encode($data,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).'</script>';
  }
 }
}
function tc_description_words(){return 30;}
add_action('wp_head','tc_seo',5);
require get_template_directory().'/inc/pricing-data.php';
require get_template_directory().'/inc/customizer.php';
require get_template_directory().'/inc/ads.php';
require get_template_directory().'/inc/setup.php';
