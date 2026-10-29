(function () {
  function norm(s) {
    return (s || '').trim().toLocaleLowerCase('tr-TR');
  }

  function fillDistricts(selDist, list, selected) {
    selDist.innerHTML = '';
    var ph = document.createElement('option');
    ph.value = '';
    ph.textContent = 'Seçin';
    selDist.appendChild(ph);
    list.forEach(function (d) {
      var o = document.createElement('option');
      o.value = d.name;
      o.textContent = d.name;
      o.dataset.postcode = d.postcode || '';
      if (selected && norm(selected) === norm(d.name)) {
        o.selected = true;
      }
      selDist.appendChild(o);
    });
    selDist.disabled = list.length === 0;
  }

  function applyPostcode(selDist, inputZip) {
    var opt = selDist.options[selDist.selectedIndex];
    var zip = opt && opt.dataset ? opt.dataset.postcode : '';
    inputZip.value = zip || '';
  }

  function init(root) {
    var url = root.getAttribute('data-src');
    if (!url) {
      return;
    }
    var selCity = root.querySelector('select[name="city"]');
    var selDist = root.querySelector('select[name="district"]');
    var inputZip = root.querySelector('input[name="postcode"]');
    if (!selCity || !selDist || !inputZip) {
      return;
    }
    var initCity = root.getAttribute('data-city') || '';
    var initDist = root.getAttribute('data-district') || '';
    var initZip = root.getAttribute('data-postcode') || '';
    var byName = {};

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        (data.provinces || []).forEach(function (p) {
          byName[norm(p.name)] = p.districts || [];
        });
        function onCity() {
          var list = byName[norm(selCity.value)] || [];
          fillDistricts(selDist, list, '');
          inputZip.value = '';
          if (list.length) {
            selDist.disabled = false;
          }
        }
        selCity.addEventListener('change', onCity);
        selDist.addEventListener('change', function () {
          applyPostcode(selDist, inputZip);
        });
        if (initCity && byName[norm(initCity)]) {
          fillDistricts(selDist, byName[norm(initCity)], initDist);
          if (initDist) {
            applyPostcode(selDist, inputZip);
          } else if (initZip) {
            inputZip.value = initZip;
          }
        } else if (initCity) {
          onCity();
        }
      })
      .catch(function () {
        /* PHP-rendered options remain */
      });
  }

  document.querySelectorAll('[data-hfs-tr-address]').forEach(init);
})();
