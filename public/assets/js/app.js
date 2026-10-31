// Crownd – small progressive enhancements. Everything works without JavaScript.
(function () {
  'use strict';

  var each = function (selector, fn, root) {
    Array.prototype.forEach.call((root || document).querySelectorAll(selector), fn);
  };

  // Confirm dialogs: <form data-confirm="Question?">
  each('form[data-confirm]', function (form) {
    form.addEventListener('submit', function (event) {
      var question = form.getAttribute('data-confirm');
      if (question && !window.confirm(question)) { event.preventDefault(); }
    });
  });

  // Copy buttons: data-copy-target="<input id>"
  each('[data-copy-target]', function (button) {
    button.addEventListener('click', function () {
      var target = document.getElementById(button.getAttribute('data-copy-target'));
      if (!target) { return; }
      var original = button.textContent;
      var done = function () {
        button.textContent = button.getAttribute('data-copied') || original;
        setTimeout(function () { button.textContent = original; }, 2000);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(target.value).then(done);
      } else {
        target.select();
        document.execCommand('copy');
        done();
      }
    });
  });

  each('[data-print]', function (button) {
    button.addEventListener('click', function () { window.print(); });
  });

  // Ballot: count the chosen players and enable the button once one is chosen.
  var ballot = document.querySelector('form[data-ballot]');
  if (ballot) {
    var status = ballot.querySelector('[data-ballot-status]');
    var submit = ballot.querySelector('[type=submit]');
    var groups = ballot.querySelectorAll('[data-team-group]');
    var update = function () {
      var chosen = 0;
      Array.prototype.forEach.call(groups, function (g) { if (g.querySelector('input:checked')) { chosen++; } });
      if (status) {
        status.textContent = (status.getAttribute('data-template') || '{n}/{total}')
          .replace('{n}', chosen).replace('{total}', groups.length);
      }
      if (submit) { submit.disabled = chosen === 0; }
    };
    ballot.addEventListener('change', update);
    ballot.addEventListener('submit', function () {
      if (submit) { submit.disabled = true; }
    });
    update();
  }

  // Live results: refresh the block every few seconds while the voting runs.
  each('[data-live-url]', function (box) {
    var url = box.getAttribute('data-live-url');
    var tick = function () {
      if (document.hidden) { setTimeout(tick, 4000); return; }
      fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { return r.ok ? r.text() : null; })
        .then(function (html) {
          if (html === null) { setTimeout(tick, 15000); return; }
          box.innerHTML = html;
          var inner = box.querySelector('[data-status]');
          if (inner && inner.getAttribute('data-status') === 'open') { setTimeout(tick, 4000); }
        })
        .catch(function () { setTimeout(tick, 15000); });
    };
    var inner = box.querySelector('[data-status]');
    if (inner && inner.getAttribute('data-status') === 'open') { setTimeout(tick, 4000); }
  });

  // Waiting page: check every 20 seconds whether the voting has started.
  var waiting = document.querySelector('[data-auto-reload]');
  if (waiting) {
    setInterval(function () { if (!document.hidden) { window.location.reload(); } }, 20000);
  }

  // Projector: full screen
  each('[data-fullscreen]', function (button) {
    if (!document.documentElement.requestFullscreen) { button.hidden = true; return; }
    button.addEventListener('click', function () {
      if (document.fullscreenElement) { document.exitFullscreen(); } else { document.documentElement.requestFullscreen(); }
    });
  });

  // Line-up and templates: «all» / «none» and a live counter.
  each('form[data-checklist]', function (form) {
    var boxes = form.querySelectorAll('input[type=checkbox][name="players[]"]');
    var counter = form.querySelector('[data-check-count]');
    var count = function () {
      if (!counter) { return; }
      var n = 0;
      Array.prototype.forEach.call(boxes, function (b) { if (b.checked) { n++; } });
      counter.textContent = (counter.getAttribute('data-template') || '{n}/{total}').replace('{n}', n).replace('{total}', boxes.length);
    };
    each('[data-check-all]', function (button) {
      button.hidden = false;
      button.addEventListener('click', function () {
        var value = button.getAttribute('data-check-all') === '1';
        Array.prototype.forEach.call(boxes, function (b) { b.checked = value; });
        count();
      });
    }, form);
    form.addEventListener('change', count);
    count();
  });

  // Team color: show the picker only when «own color» is ticked.
  var useColor = document.getElementById('f-use_color');
  var colorRow = document.querySelector('[data-color-row]');
  if (useColor && colorRow) {
    var syncColor = function () { colorRow.hidden = !useColor.checked; };
    useColor.addEventListener('change', syncColor);
    syncColor();
  }

  // Club color preview in the settings
  var color = document.getElementById('f-primary_color');
  var sample = document.querySelector('[data-color-sample]');
  if (color && sample) {
    var preview = function () {
      var hex = color.value.replace('#', '');
      var rgb = [0, 2, 4].map(function (i) {
        var c = parseInt(hex.substr(i, 2), 16) / 255;
        return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
      });
      var l = 0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2];
      sample.style.setProperty('background', color.value);
      sample.style.setProperty('color', (1.05 / (l + 0.05)) >= ((l + 0.05) / 0.0632) ? '#FFFFFF' : '#14212E');
    };
    color.addEventListener('input', preview);
    preview();
  }

  // New match: only offer the templates of the chosen team.
  each('select[data-squad-for]', function (squadSelect) {
    var teamSelect = document.getElementById(squadSelect.getAttribute('data-squad-for'));
    if (!teamSelect) { return; }
    var sync = function () {
      var team = teamSelect.value;
      Array.prototype.forEach.call(squadSelect.options, function (o) {
        var t = o.getAttribute('data-team');
        o.hidden = t !== null && t !== team;
        if (o.hidden && o.selected) { squadSelect.value = ''; }
      });
    };
    teamSelect.addEventListener('change', sync);
    sync();
  });
})();

// Player photo: round crop tool with live preview (templates/partials/photo_field.html.twig).
// Writes the cut-out as fractions into crop_x / crop_y / crop_size; the server cuts the photo.
(function () {
  'use strict';

  var DEFAULT_TOP = 0.12;      // same as ImageUpload::DEFAULT_TOP
  var MAX_UPLOAD_EDGE = 2000;  // photos are scaled down in the browser before the upload

  Array.prototype.forEach.call(document.querySelectorAll('[data-cropper]'), function (root) {
    var input = root.querySelector('[data-cropper-input]');
    var ui = root.querySelector('[data-cropper-ui]');
    var stage = root.querySelector('[data-cropper-stage]');
    var img = root.querySelector('[data-cropper-img]');
    var zoom = root.querySelector('[data-cropper-zoom]');
    var openButton = root.querySelector('[data-cropper-open]');
    var fields = {};
    Array.prototype.forEach.call(root.querySelectorAll('[data-crop-field]'), function (f) { fields[f.getAttribute('data-crop-field')] = f; });
    var previews = Array.prototype.map.call(root.querySelectorAll('[data-cropper-preview] img'), function (el) { return el; });
    if (!input || !ui || !stage || !img || !zoom) { return; }

    var w = 0, h = 0;          // natural size of the image
    var scale = 1, minScale = 1, tx = 0, ty = 0;
    var inset = 0, diameter = 0;
    var src = '';
    var touched = false;       // only send a cut-out the admin actually chose

    // Name in the previews follows the name field
    var nameInput = document.getElementById(root.getAttribute('data-name-source') || '');
    var syncName = function () {
      Array.prototype.forEach.call(root.querySelectorAll('[data-preview-name]'), function (el) {
        el.textContent = nameInput ? nameInput.value : el.textContent;
      });
    };
    if (nameInput) { nameInput.addEventListener('input', syncName); }

    var measure = function () {
      var size = stage.clientWidth;
      inset = size * 0.07;
      diameter = size - 2 * inset;
      minScale = diameter / Math.min(w, h);
    };

    var clamp = function () {
      tx = Math.min(inset, Math.max(inset + diameter - w * scale, tx));
      ty = Math.min(inset, Math.max(inset + diameter - h * scale, ty));
    };

    var crop = function () {
      return {
        x: (inset - tx) / scale / w,
        y: (inset - ty) / scale / h,
        size: (diameter / scale) / Math.min(w, h)
      };
    };

    var render = function () {
      img.style.width = (w * scale) + 'px';
      img.style.height = (h * scale) + 'px';
      img.style.transform = 'translate(' + tx + 'px,' + ty + 'px)';
      zoom.value = String(Math.round(scale / minScale * 100));
      var c = crop();
      var sidePx = c.size * Math.min(w, h);
      previews.forEach(function (p) {
        var d = p.parentNode.clientWidth;
        if (!d) { return; }
        var f = d / sidePx;
        p.style.width = (w * f) + 'px';
        p.style.height = (h * f) + 'px';
        p.style.left = (-c.x * w * f) + 'px';
        p.style.top = (-c.y * h * f) + 'px';
      });
      if (touched) {
        fields.x.value = Math.max(0, c.x).toFixed(5);
        fields.y.value = Math.max(0, c.y).toFixed(5);
        fields.size.value = Math.min(1, c.size).toFixed(5);
      }
    };

    var setCrop = function (c) {
      scale = diameter / (c.size * Math.min(w, h));
      tx = inset - c.x * w * scale;
      ty = inset - c.y * h * scale;
      clamp();
      render();
    };

    var defaultCrop = function () {
      var s = Math.min(w, h);
      return { x: (w - s) / 2 / w, y: (h - s) * DEFAULT_TOP / h, size: 1 };
    };

    var zoomTo = function (newScale) {
      newScale = Math.max(minScale, Math.min(minScale * 4, newScale));
      var cx = inset + diameter / 2, cy = cx;
      var px = (cx - tx) / scale, py = (cy - ty) / scale;
      scale = newScale;
      tx = cx - px * scale;
      ty = cy - py * scale;
      touched = true;
      clamp();
      render();
    };

    var open = function (url, initial) {
      src = url;
      img.onload = function () {
        w = img.naturalWidth;
        h = img.naturalHeight;
        ui.hidden = false;
        if (openButton) { openButton.hidden = true; }
        measure();
        setCrop(initial || defaultCrop());
        syncName();
      };
      img.src = url;
      Array.prototype.forEach.call(previews, function (p) { p.src = url; });
    };

    // Existing photo with stored original: «Ausschnitt ändern»
    var original = root.getAttribute('data-original');
    if (openButton && original) {
      openButton.hidden = false;
      openButton.addEventListener('click', function () {
        var parts = (root.getAttribute('data-crop') || '').split(',').map(Number);
        open(original, parts.length === 3 && parts.every(isFinite) ? { x: parts[0], y: parts[1], size: parts[2] } : null);
        stage.focus();
      });
    }

    // New file: show it in the crop tool and scale it down before the upload
    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      var hint = root.querySelector('[data-cropper-hint]');
      if (hint) { hint.hidden = !!file; }
      Object.keys(fields).forEach(function (k) { fields[k].value = ''; });
      touched = false;
      if (!file || !/^image\/(png|jpeg|webp)$/.test(file.type)) { ui.hidden = true; return; }
      var reader = new FileReader();
      reader.onload = function () {
        var probe = new Image();
        probe.onload = function () {
          var edge = Math.max(probe.naturalWidth, probe.naturalHeight);
          if (edge > MAX_UPLOAD_EDGE && window.DataTransfer && HTMLCanvasElement.prototype.toBlob) {
            var f = MAX_UPLOAD_EDGE / edge;
            var canvas = document.createElement('canvas');
            canvas.width = Math.round(probe.naturalWidth * f);
            canvas.height = Math.round(probe.naturalHeight * f);
            var ctx = canvas.getContext('2d');
            ctx.fillStyle = '#fff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(probe, 0, 0, canvas.width, canvas.height);
            canvas.toBlob(function (blob) {
              if (blob) {
                try {
                  var dt = new DataTransfer();
                  dt.items.add(new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }));
                  input.files = dt.files;
                } catch (e) { /* keep the original file */ }
              }
            }, 'image/jpeg', 0.9);
          }
          open(reader.result, null);
          touched = true;
          render();
        };
        probe.src = reader.result;
      };
      reader.readAsDataURL(file);
    });

    zoom.addEventListener('input', function () { zoomTo(minScale * Number(zoom.value) / 100); });

    root.querySelector('[data-cropper-reset]').addEventListener('click', function () {
      touched = true;
      setCrop(defaultCrop());
    });

    // Drag with mouse or one finger, pinch with two fingers
    var pointers = {};
    var last = null;
    var pinch = null;
    var distance = function () {
      var p = Object.keys(pointers).map(function (k) { return pointers[k]; });
      return Math.hypot(p[0].x - p[1].x, p[0].y - p[1].y);
    };
    stage.addEventListener('pointerdown', function (e) {
      if (!w) { return; }
      stage.setPointerCapture(e.pointerId);
      pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
      var n = Object.keys(pointers).length;
      last = n === 1 ? { x: e.clientX, y: e.clientY } : null;
      pinch = n === 2 ? { d: distance(), scale: scale } : null;
      e.preventDefault();
    });
    stage.addEventListener('pointermove', function (e) {
      if (!pointers[e.pointerId]) { return; }
      pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
      if (pinch && Object.keys(pointers).length === 2) {
        zoomTo(pinch.scale * distance() / pinch.d);
      } else if (last) {
        tx += e.clientX - last.x;
        ty += e.clientY - last.y;
        last = { x: e.clientX, y: e.clientY };
        touched = true;
        clamp();
        render();
      }
    });
    var up = function (e) {
      delete pointers[e.pointerId];
      pinch = null;
      var rest = Object.keys(pointers);
      last = rest.length === 1 ? pointers[rest[0]] : null;
    };
    stage.addEventListener('pointerup', up);
    stage.addEventListener('pointercancel', up);
    stage.addEventListener('wheel', function (e) {
      if (!w) { return; }
      e.preventDefault();
      zoomTo(scale * (e.deltaY < 0 ? 1.08 : 1 / 1.08));
    }, { passive: false });
    stage.addEventListener('keydown', function (e) {
      if (!w) { return; }
      var step = e.shiftKey ? 30 : 8;
      var moves = { ArrowLeft: [step, 0], ArrowRight: [-step, 0], ArrowUp: [0, step], ArrowDown: [0, -step] };
      if (moves[e.key]) {
        tx += moves[e.key][0];
        ty += moves[e.key][1];
        touched = true;
        clamp();
        render();
      } else if (e.key === '+' || e.key === '=') {
        zoomTo(scale * 1.1);
      } else if (e.key === '-') {
        zoomTo(scale / 1.1);
      } else {
        return;
      }
      e.preventDefault();
    });

    // Keep the view when the window (or the phone orientation) changes
    window.addEventListener('resize', function () {
      if (!w || ui.hidden) { return; }
      var c = crop();
      measure();
      setCrop(c);
    });
  });
})();
