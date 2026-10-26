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
