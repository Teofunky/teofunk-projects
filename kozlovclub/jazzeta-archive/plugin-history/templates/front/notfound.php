<?php
/**
 * Unknown address inside /gazeta/, withdrawn issue or an expired preview link.
 * @var array $v
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<section class="es-blank" aria-labelledby="es-blank-title">
    <h1 class="es-blank__title" id="es-blank-title">Такой страницы в газете нет</h1>
    <p class="es-blank__text">Возможно, адрес набран с ошибкой, выпуск снят с публикации или ссылка на предпросмотр устарела после правки.</p>
    <p class="es-blank__actions">
        <a class="es-cta" href="<?php echo esc_url($v['urls']['home']); ?>">Свежий номер</a>
        <a class="es-cta es-cta--quiet" href="<?php echo esc_url($v['urls']['archive']); ?>">Все выпуски</a>
    </p>
</section>
