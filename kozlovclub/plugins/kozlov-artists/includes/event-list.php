<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Афиша на мобильном (главная, поиск, фильтры): если в месяце виден один концерт,
 * его карточка занимает всю ширину, а не левую половину сетки.
 * Считаем именно видимые карточки, потому что фильтры афиши прячут остальные, но оставляют их в разметке.
 */
add_action( 'wp_footer', function () {
	if ( is_admin() || is_singular( 'kozlov_artist' ) || is_singular( 'concert' ) ) { return; }
	?>
<style>
@media (max-width:719px){.events_wrap.kaf-compact .events.view_cell .event.ka-solo{grid-column:1/-1}}
</style>
<script>
(function(){
var mq=window.matchMedia?window.matchMedia('(max-width:719px)'):null,tm;
function run(){
 var gs=document.querySelectorAll('.events_wrap .events.view_cell');
 for(var i=0;i<gs.length;i++){
  var ev=gs[i].querySelectorAll(':scope > .event'),vis=[];
  for(var j=0;j<ev.length;j++){if(ev[j].offsetParent!==null||ev[j].getClientRects().length){vis.push(ev[j])}}
  var solo=(mq&&mq.matches&&vis.length===1)?vis[0]:null;
  for(var k=0;k<ev.length;k++){
   var on=ev[k]===solo;
   if(ev[k].classList.contains('ka-solo')!==on){ev[k].classList.toggle('ka-solo',on)}
  }
 }
}
function sched(){clearTimeout(tm);tm=setTimeout(run,100)}
run();
var w=document.querySelector('.events_wrap');
if(w){new MutationObserver(sched).observe(w,{childList:true,subtree:true,attributes:true,attributeFilter:['class','style','hidden']})}
window.addEventListener('resize',sched);
setTimeout(run,800);setTimeout(run,2500);
})();
</script>
	<?php
}, 98 );
