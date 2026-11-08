(function () {
    'use strict';

    // Marks that JS is driving, so CSS can hide the detail boxes that would
    // otherwise all be visible.
    document.documentElement.classList.add('js');
    document.body.classList.add('js');

    /* ------------------------------------------- show detail on a yes */
    document.querySelectorAll('[data-question]').forEach(function (block) {
        var detail = block.querySelector('[data-detail]');

        block.addEventListener('change', function (event) {
            if (event.target.name && event.target.name.indexOf('[answer]') !== -1) {
                var yes = event.target.value === 'yes' && event.target.checked;
                detail.hidden = !yes;
                if (yes) { detail.querySelector('textarea').focus(); }
            }
        });
    });

    /* --------------------------------------------------- signature pad */
    var pad = document.getElementById('pad');
    var canvas = document.getElementById('signature-canvas');
    var field = document.getElementById('signature');
    var context = canvas.getContext('2d');
    var drawing = false;
    var dirty = false;

    // The canvas is sized in CSS pixels but drawn in device pixels, or the
    // signature is a blurry mess on every phone made since about 2012.
    function resize() {
        var ratio = Math.max(window.devicePixelRatio || 1, 1);
        var rect = canvas.getBoundingClientRect();

        // Resizing clears the canvas, so an existing signature is redrawn
        // from the stored data URL rather than silently lost when the
        // keyboard opens and the viewport changes.
        var existing = dirty ? field.value : null;

        canvas.width = rect.width * ratio;
        canvas.height = rect.height * ratio;
        context.setTransform(ratio, 0, 0, ratio, 0, 0);
        context.lineWidth = 2.2;
        context.lineCap = 'round';
        context.lineJoin = 'round';
        context.strokeStyle = '#15161a';

        if (existing) {
            var image = new Image();
            image.onload = function () { context.drawImage(image, 0, 0, rect.width, rect.height); };
            image.src = existing;
        }
    }

    function position(event) {
        var rect = canvas.getBoundingClientRect();
        return { x: event.clientX - rect.left, y: event.clientY - rect.top };
    }

    canvas.addEventListener('pointerdown', function (event) {
        drawing = true;
        canvas.setPointerCapture(event.pointerId);
        var point = position(event);
        context.beginPath();
        context.moveTo(point.x, point.y);
        // A dot, so a single tap registers as a mark rather than nothing.
        context.lineTo(point.x + 0.1, point.y);
        context.stroke();
        mark();
    });

    canvas.addEventListener('pointermove', function (event) {
        if (!drawing) { return; }
        var point = position(event);
        context.lineTo(point.x, point.y);
        context.stroke();
    });

    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (name) {
        canvas.addEventListener(name, function () {
            if (!drawing) { return; }
            drawing = false;
            store();
        });
    });

    function mark() {
        dirty = true;
        pad.classList.add('signed');
    }

    function store() {
        field.value = dirty ? canvas.toDataURL('image/png') : '';
    }

    document.getElementById('clear-signature').addEventListener('click', function () {
        context.clearRect(0, 0, canvas.width, canvas.height);
        dirty = false;
        field.value = '';
        pad.classList.remove('signed');
    });

    window.addEventListener('resize', resize);
    resize();

    // A signature restored by the browser after a failed submit.
    if (field.value) {
        dirty = true;
        pad.classList.add('signed');
        var restored = new Image();
        restored.onload = function () {
            var rect = canvas.getBoundingClientRect();
            context.drawImage(restored, 0, 0, rect.width, rect.height);
        };
        restored.src = field.value;
    }

    /* -------------------------------------------------------- submit */
    var form = document.getElementById('public-form');
    var submit = document.getElementById('submit');

    form.addEventListener('submit', function (event) {
        store();

        var problems = [];

        document.querySelectorAll('[data-question]').forEach(function (block) {
            if (!block.querySelector('input[type="radio"]:checked')) {
                problems.push(block);
            }
        });

        if (problems.length) {
            event.preventDefault();
            problems[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            alert('Please answer every question before signing.');
            return;
        }

        if (!document.getElementById('agree').checked) {
            event.preventDefault();
            document.getElementById('agree').scrollIntoView({ behavior: 'smooth', block: 'center' });
            alert('Please agree to the terms before signing.');
            return;
        }

        if (!field.value) {
            event.preventDefault();
            pad.scrollIntoView({ behavior: 'smooth', block: 'center' });
            alert('Please sign in the box.');
            return;
        }

        // Double-tap on a slow connection would otherwise post twice; the
        // server refuses the second, but the member sees an error instead of
        // the confirmation they earned.
        submit.disabled = true;
        submit.textContent = 'Sending…';
    });
}());
