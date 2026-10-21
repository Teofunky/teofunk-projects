<?php
/*
Template Name: Restaurant Menu Page
*/
?>


<?php

if (!session_id()) {
  session_start();
}

function mypage_head() {
  echo '<link rel="stylesheet" href="//cdn.jsdelivr.net/npm/swiper@10/swiper-bundle.min.css" />'."\n";
  echo '<script src="//cdn.jsdelivr.net/npm/swiper@10/swiper-bundle.min.js"></script>'."\n";

  echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jquery-modal/0.9.1/jquery.modal.min.css" />'."\n";
  echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-modal/0.9.1/jquery.modal.min.js"></script>'."\n";

}
add_action('wp_head', 'mypage_head');

$upload_path = '/wp-content/themes/KozlovCLub/assets/images/menu';

?>

<?php get_header(); ?>

<div class="content content-page-menu">
  <div class="inner">

    <?
    $banners = get_posts([
      'post_type' => 'banner',
      'post_status' => 'publish',
      'numberposts' => -1,
      'orderby'    => 'menu_order',
      'order'    => 'asc',
    ]);
    ?>

    <? if (!empty($banners)) { ?>
      <div class="banners">
        <div class="banners-swiper swiper">
          <!-- Additional required wrapper -->
          <div class="swiper-wrapper">
            <!-- Slides -->
            <?php foreach ($banners as $banner) { ?>

              <?
              $meta_b = get_post_meta($banner->ID);
              $img_url = get_the_post_thumbnail_url($banner->ID, 'full');
              ?>

              <div class="banners-swiper-slide swiper-slide">
                <div>
                  <a <? if (!empty($meta_b['menu_banner_link'][0])) { ?> href="<?= $meta_b['menu_banner_link'][0] ?>" <? } ?> class="d-block">
                    <img src="<?= $img_url ?>" />
                  </a>
                </div>
              </div>
            <?php } ?>
          </div>

          <!-- If we need navigation buttons -->
          <button type="button" class="banner-swiper-button-nav banner-swiper-button-prev swiper-button-prev">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none">
              <path d="M14.4118 18.9831C14.6462 18.7487 14.7778 18.4308 14.7778 18.0993C14.7778 17.7679 14.6462 17.45 14.4118 17.2156L8.22435 11.0281L14.4118 4.84058C14.6395 4.60482 14.7655 4.28907 14.7627 3.96132C14.7598 3.63358 14.6284 3.32006 14.3966 3.0883C14.1649 2.85654 13.8513 2.72508 13.5236 2.72223C13.1959 2.71939 12.8801 2.84538 12.6443 3.07308L5.5731 10.1443C5.33876 10.3787 5.20711 10.6966 5.20711 11.0281C5.20711 11.3595 5.33876 11.6774 5.5731 11.9118L12.6443 18.9831C12.8788 19.2174 13.1966 19.3491 13.5281 19.3491C13.8596 19.3491 14.1774 19.2174 14.4118 18.9831Z" fill="#424242"/>
            </svg>
          </button>
          <button type="button" class="banner-swiper-button-nav banner-swiper-button-next swiper-button-next">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none">
              <path d="M5.57301 3.08815C5.33868 3.32256 5.20703 3.64045 5.20703 3.9719C5.20703 4.30336 5.33868 4.62124 5.57301 4.85565L11.7605 11.0432L5.57301 17.2307C5.34532 17.4664 5.21932 17.7822 5.22217 18.1099C5.22502 18.4376 5.35648 18.7512 5.58824 18.9829C5.82 19.2147 6.13352 19.3461 6.46126 19.349C6.78901 19.3518 7.10476 19.2258 7.34051 18.9981L14.4118 11.9269C14.6461 11.6925 14.7777 11.3746 14.7777 11.0432C14.7777 10.7117 14.6461 10.3938 14.4118 10.1594L7.34051 3.08815C7.1061 2.85381 6.78822 2.72217 6.45676 2.72217C6.12531 2.72217 5.80742 2.85381 5.57301 3.08815Z" fill="#424242"/>
            </svg>
          </button>

        </div>
      </div>
    <? } ?>


    <div class="menu-info">
      <div class="menu-info-body">
        <div class="menu-info-content row">
          <div class="col col-auto">
            <div class="menu-info-icon">
              <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32" fill="none">
                <path d="M29 21.5039C29 14.6722 23.7028 9.05438 17 8.54206V5H15V8.54206C8.29719 9.05456 3 14.6722 3 21.5039V23.5731H29V21.5039ZM27 21.5731H5V21.5039C5 15.4386 9.93456 10.5039 16 10.5039C22.0654 10.5039 27 15.4385 27 21.5039V21.5731ZM1 26H31V28H1V26Z" fill="#424242"/>
              </svg>
            </div>
          </div>
          <div class="col">
            <div class="menu-info-text">
              <div>Уважаемые гости, во время концертов обслуживание происходит в перерывах между композициями и отделениями.</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="menu-header">
      <div class="row align-items-center">
        <div class="col">
          <div class="title">
        		<h1>Меню</h1>
        	</div>
        </div>
        <? /*
        <div class="col col-auto">
          <div>
            <div class="menu-card row align-items-center">
              <div class="col col-auto">
                <div>
                  <svg xmlns="http://www.w3.org/2000/svg" width="32" height="33" viewBox="0 0 32 33" fill="none">
                    <path d="M18.6347 3.16663H7.99999C7.7523 3.16688 7.50958 3.23613 7.29904 3.3666C7.08849 3.49707 6.91846 3.6836 6.80799 3.90529L2.80799 11.9053C2.69749 12.1265 2.6507 12.3741 2.67283 12.6204C2.69497 12.8667 2.78516 13.102 2.93332 13.3L14.9147 29.2733C15.0864 29.5165 15.335 29.6948 15.6204 29.7794C15.9058 29.8641 16.2114 29.8502 16.488 29.74C16.7279 29.6466 16.9355 29.4854 17.0853 29.276L29.0667 13.3C29.2153 13.1017 29.3058 12.8659 29.328 12.6191C29.3501 12.3723 29.303 12.1242 29.192 11.9026L25.192 3.90263C25.0811 3.68143 24.911 3.49544 24.7004 3.36546C24.4899 3.23548 24.2474 3.16663 24 3.16663H18.6347ZM17.7067 5.83329L19.4827 11.1666H12.5173L14.2933 5.83329H17.7067ZM22.2933 11.1666L20.5173 5.83329H23.176L25.8427 11.1666H22.296H22.2933ZM19.4827 13.8333L16 24.2866L12.5173 13.8333H19.4827ZM19.864 21.1266L22.2933 13.8333H25.3333L19.864 21.1266ZM12.136 21.1266L6.66665 13.8333H9.70666L12.136 21.1266ZM9.70666 11.1666H6.15732L8.82399 5.83329H11.4827L9.70666 11.1666Z" fill="#9E9E9E"/>
                  </svg>
                </div>
              </div>
              <div class="col">
                <div class="menu-card-link">
                  <div>
                    <a href="https://publifegroup.ru/loyaltycard" target="_blank">Карта привилегий</a>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        */ ?>
      </div>
    </div>
  </div>

  <?
  $posts = get_posts([
    'post_type' => 'menu',
    'post_status' => 'publish',
    'numberposts' => -1,
    'orderby'    => 'menu_order',
    'order'    => 'asc',
  ]);
  ?>

  <?php
  // $arMenu1 = [
  //   'бранч-меню',
  //   'блюда на компанию',
  //   'салаты и закуски',
  //   'супы',
  //   'горячие блюда',
  //   'паста',
  //   'гриль',
  //   'гарниры',
  //   'соусы',
  //   'десерты',
  //   'б/а напитки',
  //   'алк.коктейли',
  // ];
  // $arMenu2 = [
  //   'виски',
  //   'коньяк',
  //   'ром',
  //   'ликёр',
  //   'биттеры',
  //   'портвейн',
  //   'водка',
  //   'текила',
  //   'джин',
  //   'кальвадос',
  //   'вермуты',
  //   'вино',
  //   'пиво',
  // ];
  ?>

  <? if (!empty($posts)) { ?>
    <div class="menu-nav">
      <div class="inner">
        <div class="menu-nav-items">
          <div class="menu-nav-items-swiper swiper">
            <!-- Additional required wrapper -->
            <div class="swiper-wrapper">
              <!-- Slides -->
              <? foreach ($posts as $k => $post) { ?>
                <div class="menu-nav-items-swiper-slide swiper-slide">
                  <a href="#<?= $post->post_name ?>" class="menu-nav-items-swiper-slide-link" data-nav="<?= $k ?>">
                    <span><?= $post->post_title ?></span>
                  </a>
                </div>
              <?php } ?>
            </div>

            <!-- If we need navigation buttons -->
            <button type="button" class="menu-nav-items-swiper-button-nav menu-nav-items-swiper-button-prev swiper-button-prev">
              <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none">
                <path d="M14.4118 18.9831C14.6462 18.7487 14.7778 18.4308 14.7778 18.0993C14.7778 17.7679 14.6462 17.45 14.4118 17.2156L8.22435 11.0281L14.4118 4.84058C14.6395 4.60482 14.7655 4.28907 14.7627 3.96132C14.7598 3.63358 14.6284 3.32006 14.3966 3.0883C14.1649 2.85654 13.8513 2.72508 13.5236 2.72223C13.1959 2.71939 12.8801 2.84538 12.6443 3.07308L5.5731 10.1443C5.33876 10.3787 5.20711 10.6966 5.20711 11.0281C5.20711 11.3595 5.33876 11.6774 5.5731 11.9118L12.6443 18.9831C12.8788 19.2174 13.1966 19.3491 13.5281 19.3491C13.8596 19.3491 14.1774 19.2174 14.4118 18.9831Z" fill="#424242"/>
              </svg>
            </button>
            <button type="button" class="menu-nav-items-swiper-button-nav menu-nav-items-swiper-button-next swiper-button-next">
              <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none">
                <path d="M5.57301 3.08815C5.33868 3.32256 5.20703 3.64045 5.20703 3.9719C5.20703 4.30336 5.33868 4.62124 5.57301 4.85565L11.7605 11.0432L5.57301 17.2307C5.34532 17.4664 5.21932 17.7822 5.22217 18.1099C5.22502 18.4376 5.35648 18.7512 5.58824 18.9829C5.82 19.2147 6.13352 19.3461 6.46126 19.349C6.78901 19.3518 7.10476 19.2258 7.34051 18.9981L14.4118 11.9269C14.6461 11.6925 14.7777 11.3746 14.7777 11.0432C14.7777 10.7117 14.6461 10.3938 14.4118 10.1594L7.34051 3.08815C7.1061 2.85381 6.78822 2.72217 6.45676 2.72217C6.12531 2.72217 5.80742 2.85381 5.57301 3.08815Z" fill="#424242"/>
              </svg>
            </button>

          </div>
        </div>
      </div>
    </div>

    <div class="menu-content">
      <div class="inner">
        <div>
          <?php
          // $arProdudctTitles = [
          //   'Сет к вину: артишоки, маслины, оливки, вяленые томаты, ассорти сыров',
          //   'Буррата с томатами и щавелём',
          //   'Тартар из тунца',
          // ];
          // $arProdudctTitles2 = [
          //   'Oakheart Orginal',
          //   'Mossburn Signature Casks Speyside',
          //   'Sambuca',
          // ];
          // $arProdudctInfos = [
          //   'Буррата (молодой сыр из сливок и молока), семечки тыквенные, щавель свежийсладкие узбекские томаты, соус песто трюфельное (базилик свежий, масло оливковое, трюфельное масло, орех кедровый, пармезан, сок лимона, соль, перец), розовый перец',
          //   'Сёмга, дорадо, клубника, чили перец, авокадо, мини шпинат, соус ( манго, маракуйя, кунжутное масло)',
          //   'Соусы: брусничный, айоли, Блю Чиз',
          // ];
          // $arStatus = [
          //   'top',
          //   'like',
          //   'plus',
          //   'promo',
          //   'sale',
          //   'special',
          // ];
          ?>
          <? foreach ($posts as $k => $post) { ?>

            <?
            $meta = get_post_meta($post->ID);
            ?>

            <div class="menu-block-wrapper position-relative">
              <a id="<?= $post->post_name ?>" class="anchor"></a>
              <div class="menu-block <? if ($meta['category_accordeon'][0] == 1) echo 'menu-block-accordion' ?>" data-slide="<?= $k ?>" data-alias="<?= $post->post_name ?>">
                <div class="menu-block-title <? if ($meta['category_accordeon'][0] == 1) echo 'menu-block-title-accordion' ?>" data-slide="<?= $k ?>" data-alias="<?= $post->post_name ?>">
                  <div>
                    <h2><?= $post->post_title ?></h2>
                    <? if ($meta['category_accordeon'][0] == 1) { ?>
                      <span class="accordion-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="25" viewBox="0 0 24 25" fill="none">
                          <path d="M2.45407 8.43942C2.73536 8.15822 3.11683 8.00024 3.51457 8.00024C3.91232 8.00024 4.29378 8.15822 4.57507 8.43942L12.0001 15.8644L19.4251 8.43942C19.708 8.16619 20.0869 8.01499 20.4802 8.01841C20.8735 8.02183 21.2497 8.17958 21.5278 8.4577C21.8059 8.73581 21.9637 9.11203 21.9671 9.50532C21.9705 9.89862 21.8193 10.2775 21.5461 10.5604L13.0606 19.0459C12.7793 19.3271 12.3978 19.4851 12.0001 19.4851C11.6023 19.4851 11.2209 19.3271 10.9396 19.0459L2.45407 10.5604C2.17287 10.2791 2.01489 9.89767 2.01489 9.49992C2.01489 9.10218 2.17287 8.72072 2.45407 8.43942Z" fill="black"/>
                        </svg>
                      </span>
                    <? } ?>
                  </div>
                </div>
                <div class="menu-block-content <? if ($meta['category_accordeon'][0] == 1) echo 'accordion-content' ?>">
                  <div class="menu-block-items <? if ($meta['category_accordeon'][0] == 1) echo 'menu-block-items-slide' ?> row">
                    <? if (!empty($meta['category_product_title'][0])) foreach ($meta['category_product_title'] as $pk => $pv) { ?>
                      <? if ($meta['category_product_hide'][$pk] != 1) { ?>

                        <div class="col col-4">
                          <div class="menu-block-product">

                            <?
                            // pre($pk);
                            // pre($meta['category_product_hide'][$pk]);
                            // pre($meta['category_product_title'][$pk]);
                            // pre($meta['category_product_image'][$pk]);
                            // pre($meta['category_product_description'][$pk]);
                            // pre($meta['category_product_params'][$pk]);
                            // pre($meta['category_product_kcal'][$pk]);

                            // pre($meta['category_product_label'][$pk]);
                            $product_label = unserialize($meta['category_product_label'][$pk]);
                            $product_label = $product_label[0];
                            $product_label = explode('|', $product_label)[1];
                            $product_label = mb_strtolower(trim($product_label));
                            // pre($product_label);
                            ?>

                            <? if ($meta['category_accordeon'][0] == 0) { ?>

                              <? if (!empty($meta['category_product_image'][$pk])) { ?>
                                <div class="menu-block-product-header">
                                  <div class="menu-block-product-image menu-block-modal-link">
                                    <img src="/wp-content/files_mf/<?= $meta['category_product_image'][$pk] ?>" />
                                    <?php
                                    $status = rand(0, 1);
                                    ?>
                                    <?php if ($product_label != 'no') { ?>
                                      <span class="menu-block-product-status">
                                        <img src="<?= $upload_path ?>/s-<?= $product_label ?>.svg" />
                                      </span>
                                    <?php } ?>
                                  </div>
                                </div>
                              <? } ?>
                            <? } ?>

                            <div class="menu-block-product-body">
                              <div class="menu-block-product-title menu-block-modal-link">
                                <div>
                                  <span><?= $meta['category_product_title'][$pk] ?></span>
                                  <? if ($meta['category_accordeon'][0] == 1 || empty($meta['category_product_image'][$pk])) { ?>
                                    <?php if ($product_label != 'no') { ?>
                                      <span class="menu-block-product-status">
                                        <img src="<?= $upload_path ?>/s-<?= $product_label ?>.svg" />
                                      </span>
                                    <? } ?>
                                  <? } ?>
                                </div>
                              </div>
                              <? if (!empty($meta['category_product_description'][$pk])) { ?>
                                <div class="menu-block-product-description">
                                  <div><?= $meta['category_product_description'][$pk] ?></div>
                                </div>
                              <? } ?>
                              <? if (!empty($meta['category_product_kcal'][$pk])) { ?>
                                <div class="menu-block-product-kcal">
                                  <div><?= $meta['category_product_kcal'][$pk] ?></div>
                                </div>
                              <? } ?>
                            </div>
                            <? if (!empty($meta['category_product_params'][$pk])) { ?>
                              <div class="menu-block-product-footer">

                                <?
                                // pre($meta['category_product_params'][$pk]);
                                $product_params = $meta['category_product_params'][$pk];
                                $product_params = explode('?', $product_params);
                                // pre($product_params);
                                ?>

                                <? foreach ($product_params as $param) { ?>
                                  <?
                                  $param = trim($param);
                                  $param = explode('|', $param);
                                  ?>

                                  <div class="row align-items-center">
                                    <div class="col">
                                      <div class="menu-block-product-label">
                                        <div><?= trim($param[0]) ?></div>
                                      </div>
                                    </div>
                                    <div class="col col-auto">
                                      <div class="menu-block-product-price">
                                        <? if (count($param) == 3) { ?>
                                          <div>
                                            <span class="menu-block-product-price-old"><?= trim($param[1]) ?>₽</span>
                                            <span><?= trim($param[2]) ?>₽</span>
                                          </div>
                                        <? } else { ?>
                                          <div>
                                            <span><?= trim($param[1]) ?>₽</span>
                                          </div>
                                        <? } ?>
                                      </div>
                                    </div>
                                  </div>
                                <? } ?>

                              </div>
                            <? } ?>
                          </div>
                        </div>
                      <? } ?>
                    <? } ?>
                  </div>
                </div>
              </div>
            </div>
          <?php } ?>

        </div>
      </div>
    </div>
  <? } ?>

  </div>
</div>


<? if (!isset($_COOKIE['pegi'])) { ?>
  <div id="pegiModal" class="modal pegi-modal">
    <div class="pegi-modal-inner">
      <div>
        <div class="pegi-modal-title">
          <div>Внимание</div>
        </div>
        <div class="pegi-modal-content">
          <div>
            <p>Сайт содержит информацию, не рекомендованную для лиц, не достигших совершеннолетнего возраста. Алкоголь противопоказан лицам до 18 лет, беременным и кормящим женщинам, лицам с заболеваниями центральной нервной системы и органов пищеварения.</p>
            <p>Содержащаяся на сайте справочная информация об ассортименте алкогольной продукции не является предложением о приобретении и доводится до сведения посетителей в соответствии с требованиями Закона РФ от 07.02.1992 № 381-ФЗ.</p>
          </div>
        </div>
        <div class="pegi-modal-footer">
          <div>
            <a href="#" id="pegiAccept" class="pegi-modal-close" rel="modal:close">Мне есть 18 лет</a>
          </div>
        </div>
      </div>
    </div>
  </div>
<? } ?>


<div id="productModal" class="modal product-modal"> <? // product-modal-single ?>
  <div class="product-modal-inner">
    <div>
      <div class="product-modal-content">
        <div class="product-modal-row row">
          <div class="col col-6">
            <div class="product-modal-image">
              <? /*
              <div class="menu-block-product-image">
                <img src="<?= $upload_path ?>/p<?= rand(1, 3) ?>.jpeg" />
                <span class="menu-block-product-status">
                  <img src="<?= $upload_path ?>/s-<?= $arStatus[rand(0, 5)] ?>.svg" />
                </span>
              </div>
              */ ?>
            </div>
          </div>
          <div class="col col-6">
            <div class="product-modal-info">
              <? /*
              <div class="menu-block-product-body">
                <div class="menu-block-product-title">
                  <div><?= $arProdudctTitles[rand(0, 2)] ?></div>
                </div>
                <div class="menu-block-product-description">
                  <div><?= $arProdudctInfos[rand(0, 2)] ?></div>
                </div>
                <div class="menu-block-product-kcal">
                  <div>В порции: К 461, Б 16.74, Ж 30.21, У 30.46</div>
                </div>
              </div>
              <div class="menu-block-product-footer">
                <div class="row align-items-center">
                  <div class="col">
                    <div class="menu-block-product-label">
                      <div>350 гр</div>
                    </div>
                  </div>
                  <div class="col col-auto">
                    <div class="menu-block-product-price">
                      <div>750₽</div>
                    </div>
                  </div>
                </div>
              </div>
              */ ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>


<?php get_footer(); ?>

<style>

img, svg {
  vertical-align: middle;
  -webkit-user-drag: none;
}

.position-relative {
  position: relative !important;
}

.d-block {
  display: block !important;
}

.row {
  display: flex;
  flex-wrap: wrap;
}

.align-items-center {
  align-items: center !important;
}

.row > * {
  flex-shrink: 0;
  width: 100%;
  max-width: 100%;
}

.col {
  flex: 1 0 0%;
}

.col-auto {
  flex: 0 0 auto;
  width: auto;
}

.col-4 {
  flex: 0 0 auto;
  width: 33.33333333%;
}

.col-6 {
  flex: 0 0 auto;
  width: 50%;
}

.banners {
  padding-top: 1rem;
  padding-bottom: 1rem;
}

.banners-swiper-slide img {
  width: 100%;
  max-width: 100%;
  border-radius: 24px;
}

.banner-swiper-button-nav {
  padding: 0;
  margin: 0;
  border: 0;
  border-radius: 50%;
  background-color: #FFF;
  width: 32px;
  height: 32px;
  top: 50%;
  transform: translateY(-50%);
}

.banner-swiper-button-nav svg {
  width: 20px;
  height: 20px;
}

.swiper-button-next:after,
.swiper-button-prev:after {
  display: none;
}

.content-page-menu .title h1 {
  font-size: 3.5rem;
  text-transform: uppercase;
  line-height: 1.2;
  font-weight: 500;
}

@media screen and (max-width: 576px) {
  .content-page-menu .title h1 {
    font-size: 2rem;
  }
}

.menu-card {
  margin: 0 -0.25rem;
}

@media screen and (max-width: 576px) {
  .menu-card svg {
    width: 1.5rem;
    height: 1.5rem;
  }
}

.menu-card .col {
  padding: 0 0.25rem;
}

.menu-card-link {
  font-size: 1.125rem;
  line-height: normal;
  font-weight: 500;
  text-transform: uppercase;
}

@media screen and (max-width: 576px) {
  .menu-card-link {
    font-size: 1rem;
  }
}

.menu-card-link a {
  transition: color .15s ease-in-out;
}

.menu-card-link a:hover {
  color: #9E9E9E;
}

.menu-info {
  padding: 1.25rem 1.5rem;
  margin: 1.5rem 0;
  border-radius: 24px;
  background-color: #F8F8F8;
}

.menu-info-body {
  max-width: 680px;
  margin: 0 auto;
}

.menu-info-content {
  align-items: center;
  margin: 0 -0.375rem;
}

.menu-info-content .col {
  padding: 0 0.375rem;
}

@media screen and (max-width: 576px) {
  .menu-info-content .col {
    width: 100%;
  }
  .menu-info-content .col + .col {
    margin-top: 0.5rem;
  }
}

.menu-info-content-auto {
  flex: 0 0 auto;
  width: auto;
}

.menu-info-icon {
  border-radius: 50%;
  width: 48px;
  height: 48px;
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: #E7E7E7;
}

.menu-info-text {
  line-height: normal;
}

.menu-header {
  padding: 2.5rem 0 1.5rem;
}

.menu-nav {
  padding: 0.5rem 0;
  position: -webkit-sticky;
  position: sticky;
  top: 99px;
  background-color: #FFFFFF;
  z-index: 99;
}

@media screen and (max-width: 1024px) {
  .menu-nav {
    top: 68px;
  }
}

.menu-nav-items-swiper-slide {
  width: auto;
}

.menu-nav-items-swiper-slide-link {
  display: block;
  padding: 1rem 1.5rem;
  text-transform: uppercase;
  border: 1px solid #9E9E9E;
  cursor: pointer;
  border-radius: 500px;
  font-weight: 500;
  background-color: #FFFFFF;
  transition: all .15s ease-in-out;
  opacity: .5;
}

/* .menu-nav-items-swiper-slide.swiper-slide-active .menu-nav-items-swiper-slide-link */
.menu-nav-items-swiper-slide-link:hover,
.menu-nav-items-swiper-slide-link.active {
  background-color: #FAFAFA;
  opacity: 1;
}

@media screen and (max-width: 576px) {
  .menu-nav-items-swiper-slide-link {
    font-size: 0.875rem;
  }
}

.menu-nav-items-swiper-button-nav {
  padding: 0;
  margin: 0;
  border: 0;
  width: 48px;
  height: 100%;
  top: 50%;
  transform: translateY(-50%);
  display: flex;
  align-items: center;
  transition: opacity .3s ease-in-out;
  opacity: 1;
}

@media screen and (max-width: 768px) {
  .menu-nav-items-swiper-button-nav {
    display: none;
  }
}

.menu-nav-items-swiper-button-prev {
  left: 0;
  background: linear-gradient(to right, rgb(255, 255, 255) 0%, rgb(255, 255, 255) 38.67%, rgba(255, 255, 255, 0) 100%);
  justify-content: flex-start;
}

.menu-nav-items-swiper-button-next {
  right: 0;
  background: linear-gradient(to left, rgb(255, 255, 255) 0%, rgb(255, 255, 255) 38.67%, rgba(255, 255, 255, 0) 100%);
  justify-content: flex-end;
}

.menu-nav-items-swiper-button-nav svg {
  width: 20px;
  height: 20px;
}

.menu-nav-items-swiper-button-nav.swiper-button-disabled {
  opacity: 0;
}

/* .menu-nav-items-swiper-slide:first-child {
  margin-left: 3rem;
} */

/* .menu-nav-items-swiper-slide:last-child {
  margin-right: 3rem;
} */

.menu-content {
  margin: 1.5rem 0 2.5rem;
}

.menu-block-wrapper {
  margin: 1.5rem 0;
}

/* .menu-block {
  margin: 1.5rem 0;
} */

.menu-block-accordion {
  border: 1px solid #E3E3E3;
  border-radius: 8px;
  padding: 0 2rem;
}

@media screen and (max-width: 768px) {
  .menu-block-accordion {
    padding: 0 1.5rem;
  }
}

.menu-block-title-accordion {
  cursor: pointer;
}

.menu-block-title-accordion > div {
  position: relative;
}

.accordion-icon {
  display: block;
  position: absolute;
  top: 50%;
  right: 0;
  transition: transform .3s ease-in-out;
  transform: translateY(-50%) rotate(0deg);
}

.menu-block-title-accordion.active .accordion-icon {
  transform: translateY(-50%) rotate(180deg);
}

.menu-block-title {
  padding: 1.625rem 0;
}

/* line-height: 2.5; */
.menu-block-title h2 {
  font-size: 2.5rem;
  text-transform: uppercase;
  font-weight: 500;
  line-height: 1.25;
}

/* line-height: 2.8; */
@media screen and (max-width: 576px) {
  .menu-block-title h2 {
    font-size: 1.5rem;
  }
}

.menu-block-content {
  margin-top: 1.5rem;
}

.menu-block-product {
  height: 100%;
  display: flex;
  flex-direction: column;
}

.menu-block-product-image {
  cursor: pointer;
  margin-bottom: 1rem;
  position: relative;
}

.menu-block-product-status {
  position: absolute;
  top: 1rem;
  right: 1rem;
}

.menu-block-product-status img {
  width: auto !important;
  border-radius: 0;
  display: block;
}

@media screen and (max-width: 576px) {
  .menu-block-product .menu-block-product-status {
    top: 0.75rem;
    right: 0.75rem;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .menu-block-product .menu-block-product-status img {
    max-height: 24px;
  }
}


.menu-block-product-image img {
  width: 100%;
  max-width: 100%;
}

.menu-block-items {
  margin: -2rem -0.75rem 0;
}

.menu-block-items > .col {
  padding: 0 0.75rem;
  margin-top: 2rem;
}

@media screen and (max-width: 768px) {
  .menu-block-items > .col {
    width: 50%;
  }
}

@media screen and (max-width: 576px) {
  .menu-block-items > .col {
    padding-left: 0.5rem;
    padding-right: 0.5rem;
  }
  .menu-block-items {
    margin-left: -0.5rem;
    margin-right: -0.5rem;
  }
}

.menu-block-items-slide {
  margin-top: -2rem;
}

.menu-block-items-slide > .col {
  margin-top: 2rem;
}

@media screen and (max-width: 576px) {
  .menu-block-items-slide > .col {
    width: 100%;
  }
}

.menu-block-product-title {
  font-size: 1.5rem;
  line-height: normal;
  text-transform: uppercase;
  font-weight: 600;
  cursor: pointer;
  transition: color .15s ease-in-out;
}

.menu-block-product-title .menu-block-product-status {
  position: static;
  display: inline-block;
}

.menu-block-product-title .menu-block-product-status img {
  max-height: 20px;
}

@media screen and (max-width: 576px) {
  .menu-block-product-title .menu-block-product-status img {
    max-height: 18px;
  }
}

@media screen and (max-width: 992px) {
  .menu-block-product-title {
    font-size: 1.25rem;
  }
}

@media screen and (max-width: 768px) {
  .menu-block-product-title {
    font-size: 1.5rem;
  }
}

@media screen and (max-width: 576px) {
  .menu-block-product-title {
    font-size: 1.125rem;
    font-weight: 500;
  }
}

.menu-block-product-title:hover {
  color: #9E9E9E;
}

.menu-block-product-body {
  margin-bottom: 1rem;
}

.menu-block-product-description {
  margin-top: 0.5rem;
  line-height: normal;
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
  text-overflow: ellipsis;
}

.menu-block-product-kcal {
  font-weight: 500;
  font-style: normal;
  line-height: normal;
  margin-top: 1rem;
}

.menu-block-product .menu-block-product-kcal {
  display: none;
}

.menu-block-product-footer {
  margin-top: auto;
}

.menu-block-product-footer > .row + .row {
  margin-top: 0.25rem;
}

.menu-block-product-label {
  line-height: normal;
  font-size: 1.25rem;
  color: #9E9E9E;
  font-weight: 500;
}

.menu-block-product-price {
  line-height: normal;
  font-style: normal;
  font-size: 1.5rem;
  font-weight: 500;
  text-transform: uppercase;
}

.menu-block-product-price-old {
  font-weight: 400;
  text-decoration: line-through;
  color: #9E9E9E;
  margin-right: 0.625rem;
  font-size: 1.25rem;
}

@media screen and (max-width: 576px) {
  .menu-block-product-price-old {
    margin-right: 0.25rem;
    font-size: 0.875rem;
  }
}

@media screen and (max-width: 992px) {
  .menu-block-product-label {
    font-size: 1.125rem;
  }
  .menu-block-product-price {
    font-size: 1.25rem;
  }
}

@media screen and (max-width: 768px) {
  .menu-block-product-label {
    font-size: 1.25rem;
  }
  .menu-block-product-price {
    font-size: 1.5rem;
  }
}

@media screen and (max-width: 576px) {
  .menu-block-product-label {
    font-size: 1rem;
  }
  .menu-block-product-price {
    font-size: 1.125rem;
  }
}

.accordion-content {
  display: none;
  padding-bottom: 1.5rem;
}

.anchor {
  position: absolute;
  left: 0;
  top: -150px;
  display: block;
  visibility: hidden;
  opacity: 0;
  overflow: hidden;
  height: 0;
}

@media screen and (max-width: 1024px) {
  .anchor {
    top: -140px;
  }
}

@media screen and (max-width: 576px) {
  .anchor {
    top: -130px;
  }
}

.blocker {
  z-index: 9999;
  background-color: rgba(27, 26, 26, 0.50);
}

@media screen and (max-width: 576px) {
  .modal {
    width: 100%;
  }
}

.pegi-modal {
  border-radius: 32px;
  background-color: #FFFFFF;
  max-width: 640px;
  padding: 0;
  box-shadow: none;
}

.pegi-modal-inner {
  padding: 2.5rem 1.5rem;
}

@media screen and (max-width: 576px) {
  .pegi-modal-inner {
    padding: 1.5rem 1rem;
  }
  .pegi-modal {
    border-radius: 24px;
  }
}

.pegi-modal-title {
  text-align: center;
  font-size: 2rem;
  font-style: normal;
  font-weight: 600;
  line-height: normal;
  margin-bottom: 1rem;
}

@media screen and (max-width: 576px) {
  .pegi-modal-title {
    font-size: 1.5rem;
  }
}

.pegi-modal-content {
  text-align: center;
  font-style: normal;
  line-height: normal;
  margin-bottom: 1.5rem;
}

.pegi-modal-content p {
  margin-bottom: 1rem;
}

.pegi-modal-footer {
  text-align: center;
}

.pegi-modal-close {
  display: inline-block;
  border-radius: 8px;
  background-color: #FF5050;
  padding: 1rem 2rem;
  color: #FFFFFF;
  border: 0;
  margin: 0;
  font-size: 20px;
  font-weight: 500;
  line-height: normal;
  transition: all .15s ease-in-out;
}

.pegi-modal-close[disabled] {
  opacity: .5;
  cursor: default;
  pointer-events: none !important;
}

.pegi-modal-close:hover {
  color: #FFFFFF;
  background-color: #F66;
}

.product-modal {
  border-radius: 32px;
  background-color: #FFFFFF;
  max-width: 1060px;
  padding: 0;
  box-shadow: none;
}

@media screen and (max-width: 768px) {
  .product-modal {
    border-radius: 24px;
  }
}

@media screen and (max-width: 576px) {
  .product-modal {
    border-radius: 16px;
  }
}

.product-modal-inner {
  padding: 1.5rem;
}

@media screen and (max-width: 768px) {
  .product-modal-inner {
    padding-top: 4rem;
  }
}

@media screen and (max-width: 576px) {
  .product-modal-inner {
    padding-left: 1rem;
    padding-right: 1rem;
    padding-bottom: 1rem;
  }
}

.product-modal-row {
  margin: -1rem -0.75rem 0;
}

.product-modal-row > .col {
  padding: 0 0.75rem;
  margin-top: 1rem;
}

@media screen and (max-width: 768px) {
  .product-modal-row > .col {
    width: 100%;
  }
}

.product-modal-single {
  max-width: 680px;
}

.product-modal-single .product-modal-row > .col:first-of-type {
  display: none;
}

.product-modal-single .product-modal-row > .col {
  width: 100%;
}

.product-modal .menu-block-product-image {
  cursor: inherit;
  margin-bottom: 0;
}

.product-modal .menu-block-product-image img {
  border-radius: 16px;
}

@media screen and (max-width: 576px) {
  .product-modal .menu-block-product-image img {
    border-radius: 8px;
  }
  .product-modal .menu-block-product-status img {
    max-height: 24px;
  }
}

.product-modal-info {
  max-width: 380px;
}

.product-modal-single .product-modal-info {
  max-width: 100%;
}

@media screen and (max-width: 768px) {
  .product-modal-info {
    max-width: 100%;
  }
}

.product-modal .menu-block-product-title:hover {
  color: inherit;
  cursor: inherit;
}

.product-modal .menu-block-product-description {
  margin-top: 1rem;
  display: block;
}

.product-modal a.close-modal {
  opacity: .25;
  top: 1.5rem;
  right: 1.5rem;
  transition: opacity .15s ease-in-out;
}

.product-modal a.close-modal:hover {
  opacity: .5;
}

@media screen and (max-width: 576px) {
  .product-modal a.close-modal {
    top: 1.25rem;
    right: 1rem;
  }
}

</style>



<script type="text/javascript">

var banners_swiper;
var nav_swiper;
var block_show = null;

$(document).ready(function () {

  var pegi = $('#pegiModal');
  if (pegi.length > 0) {
    pegi.modal({
      escapeClose: false,
      clickClose: false,
      showClose: false,
      fadeDuration: 500,
      fadeDelay: 0.50,
    });
  }

  $('#pegiAccept').on('click', function (e) {

      e.preventDefault();

      var data = {
        agree: 'Y',
      };

      $.ajax({
        url: '/methods/pegi.restaurant.agree.php',
        type: 'POST',
        data: data,
        beforeSend: function () {
          $('#pegiAccept').attr('disabled', true);
        },
        success: function (res) {
          // console.log(res);
          if (res.status == 1) {
            $.modal.close();
          }
        },
        error: function (res) {
          // console.log(res);
          alert('Ошибка в выполнении запроса. Попробуйте позже.');
          $('#pegiAccept').attr('disabled', false);
        },
      });

      return false;
    });


  $('.menu-block-modal-link').on('click', function () {

    let $modal = $('#productModal');

    let product = $(this).parents('.menu-block-product');
    let product_image = product.find('.menu-block-product-image').clone();
    let product_info = product.find('.menu-block-product-body').clone();
    let product_footer = product.find('.menu-block-product-footer').clone();

    if (product_image.length > 0) {
      product_image.removeClass('menu-block-modal-link');
      $modal.removeClass('product-modal-single');
    } else {
      $modal.addClass('product-modal-single');
    }

    product_info.find('.menu-block-product-title').removeClass('menu-block-modal-link');

    $modal.find('.product-modal-image').empty().prepend(product_image);
    $modal.find('.product-modal-info').empty().prepend(product_info).append(product_footer);

    $modal.modal({
      fadeDuration: 500,
      fadeDelay: 0.50,
    });

  });

  banners_swiper = new Swiper('.banners-swiper', {
    // Optional parameters
    // loop: true,

    // Navigation arrows
    navigation: {
      nextEl: '.banner-swiper-button-next',
      prevEl: '.banner-swiper-button-prev',
    },
    breakpoints: {
      0: {
        slidesPerView: 2,
        spaceBetween: 8,
      },
      768: {
        slidesPerView: 3,
        spaceBetween: 16,
      },
      992: {
        slidesPerView: 4,
        spaceBetween: 24,
        centeredSlides: false,
      },
    }
  });

  nav_swiper = new Swiper('.menu-nav-items-swiper', {
    // Optional parameters
    slidesPerView: "auto",
    spaceBetween: 8,
    mousewheel: true,
    // loop: true,
    freeMode: {
      enabled: true,
      sticky: false,
    },
    navigation: {
      nextEl: '.menu-nav-items-swiper-button-next',
      prevEl: '.menu-nav-items-swiper-button-prev',
    },
  });

  $('.menu-block-title-accordion').on('click', function () {
    var $this = $(this);
    $this.toggleClass('active');
    $this.siblings('.accordion-content').slideToggle('slow');
  });

  $('.menu-nav-items-swiper-slide-link').on('click', function (e) {
    $('.menu-nav-items-swiper-slide-link').removeClass('active');
    $(this).addClass('active');
  });

});

function scrollTracking() {
  var el = $('.menu-block');
  var wt = $(window).scrollTop();
  var wh = $(window).height();
  el.each(function (key, val) {
    var et = $(this).offset().top;
  	var eh = $(this).outerHeight();
    if (wt + wh >= et && wt + wh - eh * 1.75 <= et + (wh - eh)) {
      var nav = parseInt($(this).data('slide'));
      nav_swiper.slideTo(nav);
      $('.menu-nav-items-swiper-slide-link').removeClass('active');
      $('.menu-nav-items-swiper-slide-link[data-nav="' + nav + '"]').addClass('active');
      block_show = true;
    } else {
      block_show = false;
    }
  });
}

$(window).scroll(function(){
	scrollTracking();
});

$(document).ready(function(){
	scrollTracking();
});

</script>
