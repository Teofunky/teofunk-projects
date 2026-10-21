<?php
/*
Template Name: Tilda
*/
?>

<?php

get_header('tilda');
$id = get_the_ID();
$meta = get_post_meta($id);
echo $meta['html'][0];
get_footer('tilda');

?>
