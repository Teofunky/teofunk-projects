<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Афиша (главная, поиск, списки): в карточку концерта добавляется ссылка на страницу артиста,
 * а одинокая карточка месяца на мобильном растягивается на всю ширину.
 * Артист определяется по названию карточки (ключевые слова и исключения артиста).
 */
add_action( 'wp_footer', function () {
	if ( is_admin() || is_singular( 'kozlov_artist' ) ) { return; }
	$ids = get_posts( array( 'post_type' => 'kozlov_artist', 'post_status' => 'publish', 'numberposts' => 100, 'fields' => 'ids' ) );
	$map = array();
	foreach ( $ids as $aid ) {
		$kw = ka_lines( $aid, 'ka_keywords' );
		if ( ! $kw ) { continue; }
		$map[] = array(
			'n'  => get_the_title( $aid ),
			'u'  => get_permalink( $aid ),
			'kw' => array_values( $kw ),
			'ex' => array_values( ka_lines( $aid, 'ka_exclude' ) ),
		);
	}
	if ( ! $map ) { return; }
	?>
<style>
.ka-ca{display:block;margin-top:6px;font:500 11px/1.3 Roboto,sans-serif;letter-spacing:1.6px;text-transform:uppercase;color:#f34841!important;text-decoration:none!important}
.ka-ca:hover{text-decoration:underline!important}
@media (max-width:719px){.events_wrap.kaf-compact .events.view_cell .event:only-child{grid-column:1/-1}}
</style>
<script>
(function(){
var A=<?php echo wp_json_encode( $map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ); ?>;
function has(s,l){s=s.toLowerCase();for(var i=0;i<l.length;i++){if(s.indexOf(l[i].toLowerCase())>-1)return true}return false}
function run(){
 var ds=document.querySelectorAll('.events_wrap .event .desc, .events .event .desc');
 for(var i=0;i<ds.length;i++){
  var d=ds[i];if(d.querySelector('.ka-ca'))continue;
  var t=d.querySelector('.title');if(!t)continue;
  var s=t.textContent;
  for(var j=0;j<A.length;j++){
   if(has(s,A[j].kw)&&!has(s,A[j].ex)){
    var a=document.createElement('a');a.className='ka-ca';a.href=A[j].u;a.textContent='Артист: '+A[j].n;
    t.insertAdjacentElement('afterend',a);
    t.style.height='auto';
    break;
   }
  }
 }
}
var tm;function sched(){clearTimeout(tm);tm=setTimeout(run,120)}
run();
new MutationObserver(sched).observe(document.body,{childList:true,subtree:true});
})();
</script>
	<?php
}, 98 );
