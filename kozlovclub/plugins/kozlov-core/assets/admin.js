/* Kozlov Core admin: выбор картинок из медиатеки, повторитель артистов */
(function ($) {
  function pick(wrap) {
    var frame = wp.media({ title: 'Выберите фото', multiple: false, library: { type: 'image' } });
    frame.on('select', function () {
      var a = frame.state().get('selection').first().toJSON();
      var url = (a.sizes && (a.sizes.medium || a.sizes.thumbnail) ? (a.sizes.medium || a.sizes.thumbnail).url : a.url);
      wrap.find('input[type=hidden]').first().val(a.id);
      wrap.find('.kc-image-preview').first().html('<img src="' + url + '" alt="">');
    });
    frame.open();
  }
  $(document).on('click', '.kc-pick', function (e) { e.preventDefault(); pick($(this).closest('.kc-image')); });
  $(document).on('click', '.kc-clear', function (e) { e.preventDefault(); var w = $(this).closest('.kc-image'); w.find('input[type=hidden]').first().val(0); w.find('.kc-image-preview').first().empty(); });
  $(document).on('click', '.kc-remove', function (e) { e.preventDefault(); $(this).closest('.kc-artist').remove(); });
  $('#kc_add_artist').on('click', function () {
    var i = Date.now();
    var html = $('#kc_artist_tpl').html().replace(/__i__/g, i);
    $('#kc_artists').append(html);
  });
  function syncPrice() {
    var t = $('#kc_price_type').val();
    $('input[name=kc_price]').closest('p').toggle(t === 'ticket' || t === 'deposit');
    $('input[name=kc_tc_event]').closest('p').toggle(t === 'ticket');
  }
  $('#kc_price_type').on('change', syncPrice); syncPrice();
})(jQuery);
