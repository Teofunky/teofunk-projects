<?php
if (PHP_SAPI!=='cli') { exit; }
use Kozlov\Standard\Repository as R;
function fixture($date='2026-10-20',$suffix='base') {
    $d=R::empty_doc($date); $d['import_id']='test-'.$suffix; $d['editorial']['ready_for_human_review']=true;
    $d['editorial']['source_scan_window_start']='2026-10-01T10:00:00+03:00'; $d['editorial']['source_scan_window_end']='2026-10-20T10:00:00+03:00';
    $d['editorial']['generation_notes']=['PRIVATE_SENTINEL_9a921'];
    $titles=['Сцена и пауза: устройство первой полосы','Четыре истории на одной газетной странице','От источника к тексту: редакционная работа','Почему старые выпуски сохраняют свои адреса'];
    for($n=0;$n<4;$n++) {
        $a=R::blank_article();$a['id']='article-'.$n;$a['event_id']=$a['story_group_id']=$suffix.'-event-'.$n;$a['headline']=$titles[$n].' — тест '.$suffix;
        $a['lead']='Демонстрационный материал для проверки газетной вёрстки. Это тест системы, а не музыкальная новость.';
        $a['body_paragraphs']=['<p>Это первый абзац <strong>тестового материала '.$n.'</strong> для серии '.$suffix.'. Проверяются редактор, форматирование и постоянный адрес.</p>','Второй абзац сохраняет собственные границы. Он нужен для проверки текста, который читатель увидит после ручного одобрения.','<ul><li>Тестовый пункт списка.</li><li>Вторая строка списка.</li></ul>'];
        $a['summary']='Тест '.$n.' — проверяем сохранение абзацев и адресов публикации.';$a['region']=$n<2?'russia':'world';$a['placement']=$n===0?'lead':($n===1?'feature':'regular');
        $a['sources'][0]=['id'=>'s1','title'=>'Тестовый источник '.$n,'publisher'=>'WordPress — техническая фикстура','url'=>'https://developer.wordpress.org/reference/functions/test-'.$suffix.'-'.$n.'/','published_at'=>'2026-10-01','type'=>'official','verification_checked_at'=>'2026-10-20T10:00:00+03:00','dependency_group'=>'origin-'.$n,'publication_id'=>$suffix.'-publication-'.$n,'content_hash'=>hash('sha256',$suffix.'-'.$n)];
        $a['claims']=[['id'=>'f1','statement'=>'Тестовое утверждение '.$n,'verification'=>'verified_primary','source_ids'=>['s1'],'editorial_note'=>'PRIVATE_CLAIM_NOTE']];$a['editorial_notes']=['PRIVATE_ARTICLE_NOTE'];$d['articles'][]=$a;
        $d['social'][]=['article_id'=>$a['id'],'telegram_sentence'=>$a['summary'],'instagram_sentence'=>$a['summary'],'visual_format'=>'1080x1350'];
    }
    return $d;
}
