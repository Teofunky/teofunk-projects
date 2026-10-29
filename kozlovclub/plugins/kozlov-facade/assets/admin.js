/* Живой фасад: slide list in the admin (add, remove, reorder, type switch, image picker). */
(function () {
  "use strict";
  var list = document.getElementById("kzf-list");
  var tpl = document.getElementById("kzf-tpl");
  var add = document.getElementById("kzf-add");
  if (!list || !tpl || !add) return;
  var seq = Date.now();

  function renumber() {
    Array.prototype.forEach.call(list.querySelectorAll(".kzf-row-admin"), function (row, k) {
      var n = row.querySelector(".kzf-num");
      if (n) n.textContent = String(k + 1);
    });
  }

  function bind(row) {
    var type = row.querySelector(".kzf-type");
    type.addEventListener("change", function () { row.setAttribute("data-type", type.value); });
    row.querySelector(".kzf-del").addEventListener("click", function () { row.remove(); renumber(); });
    row.querySelector(".kzf-up").addEventListener("click", function () {
      var prev = row.previousElementSibling;
      if (prev) { list.insertBefore(row, prev); renumber(); }
    });
    row.querySelector(".kzf-down").addEventListener("click", function () {
      var next = row.nextElementSibling;
      if (next) { list.insertBefore(next, row); renumber(); }
    });
    var pick = row.querySelector(".kzf-pick");
    var clear = row.querySelector(".kzf-clear");
    var idField = row.querySelector(".kzf-image-id");
    var thumb = row.querySelector(".kzf-thumb");
    if (pick && window.wp && window.wp.media) {
      pick.addEventListener("click", function () {
        var frame = window.wp.media({ title: "Картинка для карточки", library: { type: "image" }, multiple: false, button: { text: "Выбрать" } });
        frame.on("select", function () {
          var a = frame.state().get("selection").first().toJSON();
          idField.value = a.id;
          var src = (a.sizes && (a.sizes.thumbnail || a.sizes.medium)) ? (a.sizes.thumbnail || a.sizes.medium).url : a.url;
          thumb.src = src;
          thumb.hidden = false;
          clear.hidden = false;
        });
        frame.open();
      });
    }
    if (clear) {
      clear.addEventListener("click", function () {
        idField.value = "";
        thumb.hidden = true;
        thumb.removeAttribute("src");
        clear.hidden = true;
      });
    }
  }

  Array.prototype.forEach.call(list.querySelectorAll(".kzf-row-admin"), bind);
  renumber();

  add.addEventListener("click", function () {
    seq += 1;
    var html = tpl.innerHTML.split("__i__").join(String(seq));
    var box = document.createElement("div");
    box.innerHTML = html.trim();
    var row = box.firstElementChild;
    list.appendChild(row);
    bind(row);
    renumber();
    var first = row.querySelector("input[type=url], input[type=text]");
    if (first) first.focus();
  });
})();
