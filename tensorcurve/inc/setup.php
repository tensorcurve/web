<?php
if(!defined('ABSPATH'))exit;
function tc_setup_menu(){add_theme_page('TensorCurve Setup','TensorCurve Setup','manage_options','tensorcurve-setup','tc_setup_screen');}
add_action('admin_menu','tc_setup_menu');
function tc_setup_screen(){
 if(!current_user_can('manage_options'))return;
 ?><div class="wrap"><h1>TensorCurve Setup</h1><p>The theme is ready to use. This optional setup creates missing pages without replacing existing content.</p>
 <ul><li>Published pages: Home, Guides, Pricing Lab, Data Methodology.</li><li>Draft pages: About, Editorial Policy, Contact, Privacy. Review these before publishing.</li><li>Optional sample articles are created as <strong>drafts</strong>, never automatically published.</li><li>Advertising is disabled unless you enable it in Appearance → Customize.</li><li>Pricing Lab data refreshes automatically from the published daily feed; see Appearance → Customize → TensorCurve — Pricing data.</li></ul>
 <?php if(isset($_GET['tc_done'])): ?><div class="notice notice-success"><p>Setup completed. Review Pages and Posts, then configure Appearance → Customize.</p></div><?php endif; ?>
 <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="tc_setup_site"><?php wp_nonce_field('tc_setup_site'); ?><p><label><input type="checkbox" name="set_home" value="1" checked> Set created Home and Guides pages as front page and posts page.</label></p><p><label><input type="checkbox" name="set_brand" value="1" checked> Set site title to TensorCurve and tagline to Understanding the cost of compute.</label></p><p><label><input type="checkbox" name="samples" value="1"> Import four source-backed editorial articles as drafts.</label></p><?php submit_button('Create missing pages'); ?></form>
 <p>No domain, hosting, search visibility or privacy-consent settings are changed. Your existing site content is not deleted.</p>
 <h2>Next steps</h2><ol><li>Publish your articles and review their author profiles, excerpts and featured images.</li><li>Publish the reviewed information pages; they then appear in the fallback footer.</li><li>Use Appearance → Menus to customize the main and publication menus.</li><li>Use Appearance → Customize → TensorCurve to choose a lead story and configure advertising.</li><li>For an ad in the middle of an article, add a Shortcode block containing <code>[tensorcurve_ad]</code> between sections. Otherwise the article ad appears after the body. Only one article unit is shown.</li></ol>
 <p>한국어 설치·운영 안내는 테마 폴더의 INSTALL-KO.md 파일에 포함되어 있습니다.</p></div><?php
}
function tc_create_structure($samples=false,$set_home=false,$set_brand=false){
 $ids=get_option('tc_setup_pages',array());if(!is_array($ids))$ids=array();
 $method=file_get_contents(get_template_directory().'/inc/methodology.html');$method=str_replace(array('href="/data/pricing-snapshot.json"','href="/data/pricing-snapshot.csv"'),array('href="'.esc_url(tc_pricing_download_url('json')).'"','href="'.esc_url(tc_pricing_download_url('csv')).'"'),$method);
 $defs=array(
 'home'=>array('Home','','publish',''),
 'guides'=>array('Guides','','publish',''),
 'pricing'=>array('Pricing Lab','','publish','page-pricing.php'),
 'methodology'=>array('Data methodology',$method,'publish',''),
 'about'=>array('About TensorCurve','<p>TensorCurve covers GPU rental pricing, contract duration and future capacity for a global English-speaking audience.</p><h2>Publisher and author</h2><p>Before publishing, add your operator identity, relevant background and actual editorial process.</p>','draft',''),
 'editorial-policy'=>array('Editorial policy','<h2>Sources and data labels</h2><p>Distinguish observed provider prices, estimates, forecasts and synthetic examples. Include source links, observation dates and comparison assumptions.</p><h2>Review and corrections</h2><p>Before publishing, describe your actual review process, correction contact and disclosure policy.</p>','draft',''),
 'contact'=>array('Contact & corrections','<p>Send editorial questions or corrections to the contact below. Include the article address and supporting source.</p>','draft','page-contact.php'),
 'privacy'=>array('Privacy notice','<p>Draft: complete this notice for your actual operator, hosting, analytics, advertising, cookies, user choices, contact and retention practices before public launch.</p><p>Theme configuration alone does not provide a production privacy policy or consent platform.</p>','draft','')
 );
 foreach($defs as $slug=>$data){
  if(!empty($ids[$slug])&&get_post($ids[$slug])&&get_post_status($ids[$slug])!=='trash')continue;
  $existing=get_page_by_path($slug);if($existing&&$existing->post_status!=='trash'){$ids[$slug]=$existing->ID;continue;}
  $id=wp_insert_post(array('post_type'=>'page','post_title'=>$data[0],'post_name'=>$slug,'post_content'=>$data[1],'post_status'=>$data[2],'comment_status'=>'closed'),true);
  if(is_wp_error($id))continue;$ids[$slug]=$id;update_post_meta($id,'_tc_created',1);if($data[3])update_post_meta($id,'_wp_page_template',$data[3]);
 }
 update_option('tc_setup_pages',$ids);
 if($set_home&&!empty($ids['home'])&&!empty($ids['guides'])){update_option('show_on_front','page');update_option('page_on_front',$ids['home']);update_option('page_for_posts',$ids['guides']);}
 if($set_brand){update_option('blogname','TensorCurve');update_option('blogdescription','Understanding the cost of compute.');}
 if($samples){
  $created_posts=array();
  $items=json_decode(file_get_contents(get_template_directory().'/inc/sample-content.json'),true);
  foreach((array)$items as $item){
   $exists=get_posts(array('post_type'=>'post','post_status'=>'any','meta_key'=>'_tc_sample_slug','meta_value'=>$item['slug'],'fields'=>'ids','numberposts'=>1));if($exists)continue;
   $term=term_exists($item['category'],'category');if(!$term)$term=wp_insert_term($item['category'],'category');$term_id=is_wp_error($term)?0:(is_array($term)?(int)$term['term_id']:(int)$term);
   $id=wp_insert_post(array('post_type'=>'post','post_title'=>$item['title'],'post_name'=>$item['slug'],'post_excerpt'=>$item['excerpt'],'post_content'=>wp_kses_post($item['content']),'post_status'=>'draft','post_category'=>$term_id?array($term_id):array(),'comment_status'=>'closed'),true);
   if(!is_wp_error($id)){$created_posts[]=$id;update_post_meta($id,'_tc_sample',1);update_post_meta($id,'_tc_sample_slug',$item['slug']);}
  }
  // Resolve imported internal links against native WordPress permalinks.
  $links=array();foreach((array)$items as $item){$found=get_posts(array('post_type'=>'post','post_status'=>'any','meta_key'=>'_tc_sample_slug','meta_value'=>$item['slug'],'numberposts'=>1));if($found)$links['/guides/'.$item['slug'].'/']=get_permalink($found[0]->ID);}
  foreach($created_posts as $id){$body=get_post_field('post_content',$id);foreach($links as $old=>$url)$body=str_replace('href="'.$old.'"','href="'.esc_url($url).'"',$body);wp_update_post(array('ID'=>$id,'post_content'=>wp_slash($body)));}
 }
 return $ids;
}
function tc_setup_action(){
 if(!current_user_can('manage_options'))wp_die('Insufficient permissions.');check_admin_referer('tc_setup_site');
 tc_create_structure(!empty($_POST['samples']),!empty($_POST['set_home']),!empty($_POST['set_brand']));
 wp_safe_redirect(admin_url('themes.php?page=tensorcurve-setup&tc_done=1'));exit;
}
add_action('admin_post_tc_setup_site','tc_setup_action');
